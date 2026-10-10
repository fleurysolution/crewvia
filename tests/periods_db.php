<?php
/**
 * P3-M05 at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, then a client invoice and two
 * bills dated in March 2023, a month no other suite touches.
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
$app = __DIR__ . '/test-app';

check('P3-M05 is in place before the rollback', $table('financial_periods') && $table('financial_period_events'));
sql_file($app . '/install/rollback/p3-m05.sql');
check('Rollback removes every P3-M05 table', ! $table('financial_periods') && ! $table('financial_period_events'));
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds and announces financial periods', $code === 0 && str_contains($first, 'Financial periods: months can be closed'), $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P3-M05', $code === 0 && ! str_contains($second, 'Financial periods:'), $second);
check('Nothing dated in March 2023 exists yet', ! val("SELECT COUNT(*) FROM accounting_lines WHERE txn_date BETWEEN '2023-03-01' AND '2023-03-31'"));

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'payroll' => 'payroll', 'recruiter' => 'recruiter'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Periods ' . $k, 'per-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO clients(name) VALUES ('Periods client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status) VALUES (?,'P3-M05 project','Synthetic project for periods.',30,95,0,0,30,'active')", [$client]);
$job = (int) db()->lastInsertId();
q("INSERT INTO client_invoices(job_id,reference,starts_on,ends_on,total,details_json,status,created_by,created_at,issued_at,due_on)
   VALUES (?,?,'2023-03-01','2023-03-07',1000,'{}','issued',?,'2023-03-10 09:00:00','2023-03-10 10:00:00','2023-04-09')", [$job, 'PER-' . strtoupper(bin2hex(random_bytes(3))), $ids['admin']]);
$invoice = (int) db()->lastInsertId();
q("INSERT INTO vendor_invoices(job_id,vendor_name,reference,amount,due_on,status) VALUES (?,'Periods vendor',?,400,'2023-03-15','approved')", [$job, 'PB-' . bin2hex(random_bytes(4))]);
$bill = (int) db()->lastInsertId();
q("INSERT INTO vendor_invoices(job_id,vendor_name,reference,amount,due_on,status) VALUES (?,'Periods vendor',?,75,'2023-03-20','received')", [$job, 'PB-' . bin2hex(random_bytes(4))]);
$late = (int) db()->lastInsertId();

file_put_contents(__DIR__ . '/per.json', json_encode(['ids' => $ids, 'client' => $client, 'job' => $job, 'invoice' => $invoice, 'bill' => $bill, 'late' => $late]));
file_put_contents(__DIR__ . '/per-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
