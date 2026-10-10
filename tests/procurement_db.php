<?php
/**
 * Procurement at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, seeded units, then the project
 * procurement_http.py is tested on.
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
$tables = ['procurement_units', 'purchase_requests', 'purchase_quotations', 'purchase_orders', 'purchase_receipts'];

check('Procurement is in place before the rollback', ! in_array(false, array_map($table, $tables), true) && $col('jobs', 'budget_owner_id') && $col('jobs', 'auto_lodging') && $col('vendor_invoices', 'purchase_order_id'));
$invoices = (int) val('SELECT COUNT(*) FROM vendor_invoices');
sql_file($app . '/install/rollback/procurement.sql');
check('Rollback removes every procurement object', ! in_array(true, array_map($table, $tables), true) && ! $col('jobs', 'budget_owner_id') && ! $col('vendor_invoices', 'purchase_order_id'));
check('Rollback keeps every vendor invoice', (int) val('SELECT COUNT(*) FROM vendor_invoices') === $invoices);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds', $code === 0, $first);
check('Upgrade announces procurement', str_contains($first, 'Procurement: requests, purchase orders, receipts and commitments are in place'), $first);
check('Five units are seeded, room among them', (int) val('SELECT COUNT(*) FROM procurement_units') === 5 && val("SELECT label FROM procurement_units WHERE code = 'room'") === 'Room');
check('No project raises lodging requests until turned on', (int) val('SELECT COUNT(*) FROM jobs WHERE auto_lodging = 1') === 0);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for procurement', $code === 0 && ! str_contains($second, 'Procurement:') && (int) val('SELECT COUNT(*) FROM procurement_units') === 5, $second);

$fixture  = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES ('Procurement administrator','proc-admin@test.invalid',?,'admin',0)", [$password]);
q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES ('Procurement supervisor','proc-super@test.invalid',?,'supervisor',0)", [$password]);
$super = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status)
   VALUES (?,'Procurement project','Synthetic project for procurement.',20,35,0,0,0,'active')", [(int) $fixture['client']]);
$job = (int) db()->lastInsertId();
q("INSERT INTO hotels(name,nightly_rate) VALUES ('Procurement hotel',100)");
$hotel = (int) db()->lastInsertId();
$hire = static function (string $name): int {
    q('INSERT INTO candidates(full_name,email) VALUES (?,?)', [$name, strtolower(str_replace(' ', '-', $name)) . '@test.invalid']);
    return (int) db()->lastInsertId();
};
$h1 = $hire('Procurement hire');
$h2 = $hire('Procurement second hire');

file_put_contents(__DIR__ . '/proc.json', json_encode(['job' => $job, 'hotel' => $hotel, 'super' => $super, 'hire1' => $h1, 'hire2' => $h2,
    'from' => date('Y-m-d', strtotime('+7 days')), 'to' => date('Y-m-d', strtotime('+11 days'))]));
file_put_contents(__DIR__ . '/proc-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
