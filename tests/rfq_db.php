<?php
/**
 * P3-M07 at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, approvals tied to a revision,
 * then a project and vendors for rfq_http.py.
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
$index = static fn(string $t, string $i): bool => (bool) val('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$t, $i]);
$app = __DIR__ . '/test-app';

check('P3-M07 is in place before the rollback', $table('purchase_rfqs') && $table('purchase_order_revisions') && $col('purchase_orders', 'revision')
      && $col('purchase_quotations', 'valid_until') && $index('purchase_order_approvals', 'uq_po_approval_revision'));
$orders = (int) val('SELECT COUNT(*) FROM purchase_orders');
$quotes = (int) val('SELECT COUNT(*) FROM purchase_quotations');
sql_file($app . '/install/rollback/p3-m07.sql');
check('Rollback removes every P3-M07 object and puts the one-approval-per-role key back',
      ! $table('purchase_rfqs') && ! $table('purchase_order_revisions') && ! $col('purchase_orders', 'revision') && ! $col('purchase_orders', 'quotation_id')
      && ! $col('purchase_quotations', 'rfq_id') && ! $col('purchase_order_approvals', 'revision') && $index('purchase_order_approvals', 'uq_po_approval'));
check('Rollback keeps every order and quotation', (int) val('SELECT COUNT(*) FROM purchase_orders') === $orders && (int) val('SELECT COUNT(*) FROM purchase_quotations') === $quotes);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds and starts every order at revision 0', $code === 0
      && str_contains($first, 'Requests for quotation and order revisions: in place') && str_contains($first, $orders . ' existing order(s) start at revision 0'), $first);
check('Approvals now belong to a revision', $index('purchase_order_approvals', 'uq_po_approval_revision') && ! $index('purchase_order_approvals', 'uq_po_approval'));
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P3-M07', $code === 0 && ! str_contains($second, 'Requests for quotation'), $second);

$fixture  = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'hotels' => 'hotels', 'super' => 'supervisor', 'payroll' => 'payroll'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['RFQ ' . $k, 'rfq-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
// The newest project: a supervisor works on the newest.
q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status,budget_owner_id) VALUES (?,'P3-M07 project','Synthetic project for quotations.',20,35,0,0,0,'active',?)",
  [(int) $fixture['client'], $ids['super']]);
$job = (int) db()->lastInsertId();
foreach ([['Alpha Supply', 'safety_equipment', 'approved'], ['Beta Supply', 'safety_equipment', 'approved'], ['Gamma Rentals', 'vehicle', 'approved'], ['Pending Co', 'safety_equipment', 'pending']] as [$n, $c, $s]) {
    q('INSERT INTO vendors(name,status,categories,w9_on_file) VALUES (?,?,?,1)', [$n, $s, $c]);
}
$unit = (int) val('SELECT id FROM procurement_units ORDER BY id LIMIT 1');

file_put_contents(__DIR__ . '/rfq.json', json_encode(['ids' => $ids, 'job' => $job, 'unit' => $unit, 'today' => date('Y-m-d'),
    'in10' => date('Y-m-d', strtotime('+10 days')), 'yesterday' => date('Y-m-d', strtotime('-1 day'))]));
file_put_contents(__DIR__ . '/rfq-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
