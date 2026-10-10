<?php
/**
 * P3-M02 at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, the seeded mapping, then records
 * dated in 2024 for accounting_http.py: some must be exported, some must
 * not (a draft and a void invoice, a bill not approved, a claim not paid,
 * a claim the client pays).
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
$all = ['accounting_accounts', 'accounting_batches', 'accounting_lines', 'accounting_sources'];

check('P3-M02 is in place before the rollback', count(array_filter($all, $table)) === 4 && $col('client_invoices', 'paid_at'));
$invoices = (int) val('SELECT COUNT(*) FROM client_invoices');
sql_file($app . '/install/rollback/p3-m02.sql');
check('Rollback removes every P3-M02 object', count(array_filter($all, $table)) === 0 && ! $col('client_invoices', 'issued_at') && ! $col('client_invoices', 'paid_at'));
check('Rollback keeps every client invoice', (int) val('SELECT COUNT(*) FROM client_invoices') === $invoices);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds and announces the QuickBooks export with payroll off',
      $code === 0 && str_contains($first, 'Accounting: chart-of-accounts mapping and QuickBooks export') && str_contains($first, '14 accounts await confirmation, payroll export is off'), $first);
check('No account starts confirmed', (int) val('SELECT COUNT(*) FROM accounting_accounts WHERE confirmed = 1') === 0);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P3-M02', $code === 0 && ! str_contains($second, 'Accounting:'), $second);

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'payroll' => 'payroll', 'recruiter' => 'recruiter'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Accounting ' . $k, 'acct-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
$admin = $ids['admin'];

q("INSERT INTO clients(name) VALUES ('Accounting test client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status)
   VALUES (?,'P3-M02 project','Synthetic project for the QuickBooks export.',30,95,0,0,30,'active')", [$client]);
$job = (int) db()->lastInsertId();

$inv = static function (string $status, float $total, ?string $issued, ?string $paid) use ($job, $admin): int {
    q('INSERT INTO client_invoices(job_id,reference,starts_on,ends_on,total,details_json,status,created_by,created_at,issued_at,paid_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
      [$job, 'ACCT-' . strtoupper(bin2hex(random_bytes(3))), '2024-04-28', '2024-05-04', $total, '{}', $status, $admin, '2024-05-04 09:00:00', $issued, $paid]);
    return (int) db()->lastInsertId();
};
$invA = $inv('issued', 5000, '2024-05-04 10:00:00', null);
$invB = $inv('paid', 3000, '2024-05-11 10:00:00', '2024-06-01 15:00:00');
$invC = $inv('draft', 999, null, null);
$invD = $inv('void', 777, '2024-05-04 10:00:00', null);

q("INSERT INTO hotels(name) VALUES ('Accounting inn')");
$hotel = (int) db()->lastInsertId();
$unit = (int) val('SELECT id FROM procurement_units ORDER BY id LIMIT 1');
q("INSERT INTO purchase_requests(job_id,category,title,quantity,unit_id,status,requested_by) VALUES (?,'vehicle','Pickup rental',1,?,'ordered',?)", [$job, $unit, $admin]);
$request = (int) db()->lastInsertId();
q("INSERT INTO purchase_orders(reference,job_id,request_id,vendor_name,quantity,unit_id,unit_price,total,status,created_by,decided_by,decided_at)
   VALUES (?,?,?,'Synthetic rentals',1,?,800,800,'approved',?,?,NOW())", ['ACCT-PO-' . bin2hex(random_bytes(3)), $job, $request, $unit, $admin, $admin]);
$po = (int) db()->lastInsertId();
$bill = static function (?int $hotelId, ?int $poId, string $vendor, float $amount, string $status, string $due, ?string $paid) use ($job): int {
    q('INSERT INTO vendor_invoices(job_id,hotel_id,vendor_name,reference,amount,due_on,status,paid_at,purchase_order_id) VALUES (?,?,?,?,?,?,?,?,?)',
      [$job, $hotelId, $vendor, 'B-' . bin2hex(random_bytes(4)), $amount, $due, $status, $paid, $poId]);
    return (int) db()->lastInsertId();
};
$v1 = $bill($hotel, null, 'Accounting inn', 640, 'approved', '2024-05-15', null);
$v2 = $bill(null, $po, 'Synthetic rentals', 800, 'paid', '2024-05-20', '2024-05-25 12:00:00');
$v3 = $bill(null, null, 'Pending vendor', 120, 'received', '2024-05-20', null);

q('INSERT INTO candidates(full_name,email) VALUES (?,?)', ['Accounted one', 'acct-c-one@test.invalid']);
$c1 = (int) db()->lastInsertId();
q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site','2024-05-01',30,95)", [$c1, $job]);
$p1 = (int) db()->lastInsertId();
q('INSERT INTO candidates(full_name,email) VALUES (?,?)', ['Accounted two', 'acct-c-two@test.invalid']);
$c2 = (int) db()->lastInsertId();
q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site','2024-05-01',30,95)", [$c2, $job]);
$p2 = (int) db()->lastInsertId();

q("INSERT INTO worker_documents(candidate_id,uploaded_by,document_type,original_name,storage_name,mime_type,file_hash) VALUES (?,?,'receipt','r.pdf',?,'application/pdf',?)",
  [$c1, $admin, bin2hex(random_bytes(32)), str_repeat('0', 64)]);
$doc = (int) db()->lastInsertId();
$claim = static function (string $cat, float $amount, string $status, string $payer, ?string $paid) use ($p1, $admin, $doc): int {
    q('INSERT INTO expense_claims(placement_id,user_id,category,amount,receipt_document_id,status,payer,paid_at) VALUES (?,?,?,?,?,?,?,?)', [$p1, $admin, $cat, $amount, $doc, $status, $payer, $paid]);
    return (int) db()->lastInsertId();
};
$e1 = $claim('flight', 300, 'paid', 'agency', '2024-05-18 09:00:00');
$e2 = $claim('hotel', 45, 'approved', 'agency', null);
$e3 = $claim('other', 15, 'paid', 'client', '2024-05-18 09:00:00');

// A payroll period approved for the week ending 11 May 2024: one sheet with
// gross-to-net, one without, a back pay and a recovery.
q("INSERT INTO payroll_runs(week_ending,status,opened_by,approved_by,approved_at) VALUES ('2024-05-11','approved',?,?,NOW())", [$admin, $admin]);
$run = (int) db()->lastInsertId();
$sheet = static function (int $pid, array $snap) use ($admin): int {
    q("INSERT INTO timesheets(placement_id,week_ending,hours_worked,status,approved_by,approved_at) VALUES (?,'2024-05-11',40,'approved',?,NOW())", [$pid, $admin]);
    $id = (int) db()->lastInsertId();
    q('INSERT INTO pay_snapshots(timesheet_id,result_json) VALUES (?,?)', [$id, json_encode($snap)]);
    return $id;
};
$t1 = $sheet($p1, ['labour_cost' => 1425, 'per_diem' => 150, 'expenses' => 20,
    'gross_to_net' => ['gross_wages' => 1425, 'total_deductions' => 100, 'total_employer' => 50, 'reimbursements' => 170, 'net_before_tax' => 1495]]);
$t2 = $sheet($p2, ['labour_cost' => 1200, 'per_diem' => 150, 'expenses' => 0]);
q("INSERT INTO payroll_adjustments(run_id,candidate_id,timesheet_id,kind,amount,reason,created_by) VALUES (?,?,?,'back_pay',75,'Missed hours',?)", [$run, $c1, $t1, $admin]);
q("INSERT INTO payroll_adjustments(run_id,candidate_id,timesheet_id,kind,amount,reason,created_by) VALUES (?,?,?,'recovery',-20,'Overpaid per diem',?)", [$run, $c2, $t2, $admin]);

file_put_contents(__DIR__ . '/acct.json', json_encode(['job' => $job, 'ids' => $ids, 'inv' => [$invA, $invB, $invC, $invD], 'bills' => [$v1, $v2, $v3],
    'claims' => [$e1, $e2, $e3], 'run' => $run]));
file_put_contents(__DIR__ . '/acct-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
