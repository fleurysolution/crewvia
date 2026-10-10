<?php
/**
 * P3-M08 at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, the default tolerance, then a
 * project and an approved vendor for matching_http.py.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n"); exit(2); }

$results = [];
function check(string $label, bool $condition, string $detail = ''): void
{
    global $results;
    if (! $condition) { fwrite(STDERR, 'FAIL ' . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL); exit(1); }
    $results[] = $label;
    echo 'PASS ' . $label . PHP_EOL;
}
function sql_file(string $path): void
{
    foreach (preg_split('/;\s*\n/', (string) file_get_contents($path)) as $chunk) {
        $lines = array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'));
        $statement = trim(implode("\n", $lines));
        if ($statement !== '') { db()->exec($statement); }
    }
}
function run_php(string $script): array
{
    $out = []; $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}
$table = static fn(string $t): bool => (bool) val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
$col = static fn(string $t, string $c): bool => (bool) val('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
$app = __DIR__ . '/test-app';

check('P3-M08 is in place before the rollback', $table('bill_match_clearances') && $col('purchase_receipts', 'rejected_quantity') && $col('vendor_invoices', 'quantity'));
$bills = (int) val('SELECT COUNT(*) FROM vendor_invoices');
$receipts = (int) val('SELECT COUNT(*) FROM purchase_receipts');
sql_file($app . '/install/rollback/p3-m08.sql');
check('Rollback removes every P3-M08 object and setting', ! $table('bill_match_clearances') && ! $col('purchase_receipts', 'rejected_quantity') && ! $col('vendor_invoices', 'quantity')
      && ! val("SELECT COUNT(*) FROM platform_settings WHERE setting_key LIKE 'match_tolerance%'"));
check('Rollback keeps every bill and receipt', (int) val('SELECT COUNT(*) FROM vendor_invoices') === $bills && (int) val('SELECT COUNT(*) FROM purchase_receipts') === $receipts);
$open = (int) val("SELECT COUNT(*) FROM vendor_invoices WHERE status IN ('received','approved') AND purchase_order_id IS NOT NULL");
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback says how many open bills against an order are now checked', $code === 0
      && str_contains($first, 'Three-way matching:') && str_contains($first, $open . ' open bill(s) against an order are now checked; tolerance 2% or $10'), $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P3-M08', $code === 0 && ! str_contains($second, 'Three-way matching:'), $second);

$fixture  = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'hotels' => 'hotels', 'payroll' => 'payroll', 'recruiter' => 'recruiter'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Matching ' . $k, 'match-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status) VALUES (?,'P3-M08 project','Synthetic project for matching.',20,35,0,0,0,'active')", [(int) $fixture['client']]);
$job = (int) db()->lastInsertId();
q("INSERT INTO vendors(name,status,categories,w9_on_file) VALUES ('Delta Tools','approved','safety_equipment',1)");
$unit = (int) val('SELECT id FROM procurement_units ORDER BY id LIMIT 1');

file_put_contents(__DIR__ . '/match.json', json_encode(['ids' => $ids, 'job' => $job, 'unit' => $unit, 'today' => date('Y-m-d')]));
file_put_contents(__DIR__ . '/match-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
