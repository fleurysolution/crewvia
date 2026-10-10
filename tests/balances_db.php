<?php
/**
 * P3-M04 at the database level, on the isolated test database only:
 * rollback, upgrade (due dates given to invoices already out), silence on a
 * repeat, then two clients and a vendor with invoices in every aging
 * bucket, one paid before payments were recorded, one draft, one bill not
 * approved.
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
$all = ['ar_payments', 'ar_allocations', 'ar_credits', 'ap_payments', 'ap_allocations', 'ap_credits'];

check('P3-M04 is in place before the rollback', count(array_filter($all, $table)) === 6 && $col('clients', 'payment_terms_days') && $col('client_invoices', 'due_on'));
$statuses = rows('SELECT id, status FROM client_invoices ORDER BY id');
$out = (int) val("SELECT COUNT(*) FROM client_invoices WHERE status IN ('issued','paid')");
sql_file($app . '/install/rollback/p3-m04.sql');
check('Rollback removes every P3-M04 object', count(array_filter($all, $table)) === 0 && ! $col('clients', 'payment_terms_days') && ! $col('client_invoices', 'due_on'));
check('Rollback leaves every invoice\'s status as it was', rows('SELECT id, status FROM client_invoices ORDER BY id') === $statuses);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds and dates every invoice already out', $code === 0 && str_contains($first, 'Receivables and payables: payments, applications, credit notes and aging')
      && str_contains($first, $out . ' invoice(s) given a due date 30 days after issue'), $first);
check('Those due dates are 30 days after issue', ! val("SELECT COUNT(*) FROM client_invoices WHERE status IN ('issued','paid')
      AND (due_on IS NULL OR due_on <> DATE_ADD(DATE(COALESCE(issued_at, created_at)), INTERVAL 30 DAY))"));
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P3-M04', $code === 0 && ! str_contains($second, 'Receivables and payables:'), $second);

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'payroll' => 'payroll', 'recruiter' => 'recruiter'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Balances ' . $k, 'bal-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
$admin = $ids['admin'];
$day = static fn(int $offset): string => date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days'));

q("INSERT INTO clients(name) VALUES ('Balances client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO clients(name) VALUES ('Other balances client')");
$other = (int) db()->lastInsertId();
$job = static function (int $clientId, string $title): int {
    q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status) VALUES (?,?,'Synthetic project for balances.',30,95,0,0,30,'active')", [$clientId, $title]);
    return (int) db()->lastInsertId();
};
$jobA = $job($client, 'P3-M04 project');
$jobB = $job($other, 'P3-M04 other project');

$inv = static function (int $jobId, string $status, float $total, ?int $issuedAgo, ?int $dueIn) use ($admin, $day): int {
    q('INSERT INTO client_invoices(job_id,reference,starts_on,ends_on,total,details_json,status,created_by,issued_at,due_on) VALUES (?,?,?,?,?,?,?,?,?,?)',
      [$jobId, 'BAL-' . strtoupper(bin2hex(random_bytes(3))), $day(-60), $day(-54), $total, '{}', $status, $admin,
       $issuedAgo === null ? null : $day(-$issuedAgo) . ' 10:00:00', $dueIn === null ? null : $day($dueIn)]);
    return (int) db()->lastInsertId();
};
$i1 = $inv($jobA, 'issued', 1000, 10, 20);     // not yet due
$i2 = $inv($jobA, 'issued', 2000, 75, -45);    // 31-60 days late
$i3 = $inv($jobA, 'issued', 500, 130, -100);   // over 90 days late
$i4 = $inv($jobA, 'paid', 700, 200, -170);     // paid before payments were recorded
$i5 = $inv($jobA, 'draft', 300, null, null);   // not out yet
$o1 = $inv($jobB, 'issued', 800, 50, -20);     // the other client's

$bill = static function (string $status, float $amount, int $dueIn) use ($jobA, $day): int {
    q('INSERT INTO vendor_invoices(job_id,vendor_name,reference,amount,due_on,status,paid_at) VALUES (?,?,?,?,?,?,?)',
      [$jobA, 'Balances vendor', 'BB-' . bin2hex(random_bytes(4)), $amount, $day($dueIn), $status, $status === 'paid' ? $day(-30) . ' 10:00:00' : null]);
    return (int) db()->lastInsertId();
};
$b1 = $bill('approved', 400, -5);
$b2 = $bill('approved', 600, 10);
$b3 = $bill('received', 250, 10);
$b4 = $bill('paid', 90, -40);

file_put_contents(__DIR__ . '/bal.json', json_encode(['ids' => $ids, 'client' => $client, 'other' => $other, 'job' => $jobA,
    'inv' => [$i1, $i2, $i3, $i4, $i5, $o1], 'bills' => [$b1, $b2, $b3, $b4], 'today' => $day(0), 'ago40' => $day(-40), 'in45' => $day(45)]));
file_put_contents(__DIR__ . '/bal-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
