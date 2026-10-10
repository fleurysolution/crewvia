<?php
/**
 * P3-M01 at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, then a project with one record of
 * every kind the report reads, with figures worked out by hand in
 * costing_http.py. Some records must not count (a submitted week, a
 * cancelled booking, a claim the client pays, an invoice not yet approved).
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

check('P3-M01 is in place before the rollback', $table('project_budget_changes') && $col('jobs', 'burden_percent') && $col('jobs', 'overhead_percent'));
$jobs = (int) val('SELECT COUNT(*) FROM jobs');
sql_file($app . '/install/rollback/p3-m01.sql');
check('Rollback removes every P3-M01 object', ! $table('project_budget_changes') && ! $col('jobs', 'burden_percent') && ! $col('jobs', 'overhead_percent'));
check('Rollback keeps every project', (int) val('SELECT COUNT(*) FROM jobs') === $jobs);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds and announces project costing', $code === 0 && str_contains($first, 'Project costing: budgets with their history'), $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P3-M01', $code === 0 && ! str_contains($second, 'Project costing:'), $second);

$fixture  = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'payroll' => 'payroll', 'recruiter' => 'recruiter'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Costing ' . $k, 'cost-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
$admin = $ids['admin'];

q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status)
   VALUES (?,'P3-M01 project','Synthetic project for costing.',30,95,0,0,30,'active')", [(int) $fixture['client']]);
$job = (int) db()->lastInsertId();

$people = [];
foreach (['one', 'two'] as $label) {
    q('INSERT INTO candidates(full_name,email) VALUES (?,?)', ['Costed ' . $label, 'cost-c-' . $label . '@test.invalid']);
    $cid = (int) db()->lastInsertId();
    q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site','2025-03-01',30,95)", [$cid, $job]);
    $people[] = ['cid' => $cid, 'pid' => (int) db()->lastInsertId()];
}
[$one, $two] = $people;

// Frozen weeks, as approval freezes them.
$sheet = static function (int $pid, string $week, string $status, array $snap) use ($admin): int {
    q('INSERT INTO timesheets(placement_id,week_ending,hours_worked,per_diem_days,expenses,status,approved_by,approved_at) VALUES (?,?,?,?,?,?,?,NOW())',
      [$pid, $week, $snap['worked'], (int) ($snap['per_diem'] / 30), $snap['expenses'], $status, $admin]);
    $id = (int) db()->lastInsertId();
    q('INSERT INTO pay_snapshots(timesheet_id,result_json) VALUES (?,?)', [$id, json_encode($snap)]);
    return $id;
};
$base = ['guarantee' => 0, 'short_by' => 0, 'leave_pay' => 0, 'paid_leave_hours' => 0, 'overtime_multiplier' => 1.5, 'salary_basis' => null, 'pay_rate' => 30, 'bill_rate' => 95];
// 40 regular + 5 overtime at 30: 1,200 + 225 = 1,425. Billed 45 x 95 = 4,275. Employer contributions 50.
$w1a = $sheet($one['pid'], '2025-03-08', 'approved', $base + ['worked' => 45, 'regular_hours' => 40, 'overtime_hours' => 5, 'labour_cost' => 1425, 'paid_hours' => 45,
    'per_diem' => 150, 'expenses' => 20, 'pay_total' => 1595, 'bill_total' => 4275, 'margin' => 2680, 'gross_to_net' => ['total_employer' => 50]]);
// 40 at 30 = 1,200. Billed 40 x 95 = 3,800.
$sheet($two['pid'], '2025-03-08', 'paid', $base + ['worked' => 40, 'regular_hours' => 40, 'overtime_hours' => 0, 'labour_cost' => 1200, 'paid_hours' => 40,
    'per_diem' => 150, 'expenses' => 0, 'pay_total' => 1350, 'bill_total' => 3800, 'margin' => 2450]);
// Submitted, not approved: must not count.
$sheet($one['pid'], '2025-03-15', 'submitted', $base + ['worked' => 40, 'regular_hours' => 40, 'overtime_hours' => 0, 'labour_cost' => 1200, 'paid_hours' => 40,
    'per_diem' => 150, 'expenses' => 0, 'pay_total' => 1350, 'bill_total' => 3800, 'margin' => 2450]);

// Corrections paid later against the first week: 75 back pay (labour), 30 reimbursed (per diem and expenses).
q("INSERT IGNORE INTO payroll_runs(week_ending,status,opened_by) VALUES ('2025-03-22','open',?)", [$admin]);
$run = (int) val("SELECT id FROM payroll_runs WHERE week_ending = '2025-03-22'");
foreach ([['back_pay', 75], ['reimbursement', 30]] as [$kind, $amount]) {
    q('INSERT INTO payroll_adjustments(run_id,candidate_id,timesheet_id,kind,amount,reason,created_by) VALUES (?,?,?,?,?,?,?)', [$run, $one['cid'], $w1a, $kind, $amount, 'Synthetic correction', $admin]);
}

// Hotels: 6 nights at 100 = 600 counted, a cancelled stay not. Hotel invoices reconcile only.
q("INSERT INTO hotels(name) VALUES ('Costing inn')");
$hotel = (int) db()->lastInsertId();
q("INSERT INTO lodging(placement_id,hotel_id,check_in,check_out,nightly_rate,status) VALUES (?,?,'2025-03-01','2025-03-07',100,'checked_out')", [$one['pid'], $hotel]);
q("INSERT INTO lodging(placement_id,hotel_id,check_in,check_out,nightly_rate,status) VALUES (?,?,'2025-03-01','2025-03-11',100,'cancelled')", [$two['pid'], $hotel]);

// Travel: 350 booked counts, 400 cancelled does not.
q("INSERT INTO travel(placement_id,mode,cost,status) VALUES (?,'flight',350,'booked')", [$one['pid']]);
q("INSERT INTO travel(placement_id,mode,cost,status) VALUES (?,'flight',400,'cancelled')", [$two['pid']]);

// Claims: hotel 40 approved and flight 300 paid count; a submitted one and one the client pays do not.
q("INSERT INTO worker_documents(candidate_id,uploaded_by,document_type,original_name,storage_name,mime_type,file_hash) VALUES (?,?,'receipt','r.pdf',?,'application/pdf',?)",
  [$one['cid'], $admin, bin2hex(random_bytes(32)), str_repeat('0', 64)]);
$doc = (int) db()->lastInsertId();
foreach ([['hotel', 40, 'approved', 'agency'], ['flight', 300, 'paid', 'agency'], ['transport', 25, 'submitted', 'agency'], ['other', 15, 'approved', 'client']] as [$cat, $amount, $status, $payer]) {
    q('INSERT INTO expense_claims(placement_id,user_id,category,amount,receipt_document_id,status,payer) VALUES (?,?,?,?,?,?,?)', [$one['pid'], $admin, $cat, $amount, $doc, $status, $payer]);
}

// Orders and their invoices.
$unit = (int) val('SELECT id FROM procurement_units ORDER BY id LIMIT 1');
$order = static function (string $category, float $total) use ($job, $unit, $admin): int {
    q("INSERT INTO purchase_requests(job_id,category,title,quantity,unit_id,status,requested_by) VALUES (?,?,?,1,?,'ordered',?)", [$job, $category, 'Costing ' . $category, $unit, $admin]);
    $request = (int) db()->lastInsertId();
    q("INSERT INTO purchase_orders(reference,job_id,request_id,vendor_name,quantity,unit_id,unit_price,total,status,created_by,decided_by,decided_at)
       VALUES (?,?,?,'Synthetic vendor',1,?,?,?,'approved',?,?,NOW())", ['COST-' . $category . '-' . bin2hex(random_bytes(3)), $job, $request, $unit, $total, $total, $admin, $admin]);
    return (int) db()->lastInsertId();
};
$invoice = static function (float $amount, string $status, ?int $po, ?int $hotelId) use ($job): void {
    q('INSERT INTO vendor_invoices(job_id,hotel_id,vendor_name,reference,amount,due_on,status,purchase_order_id) VALUES (?,?,?,?,?,?,?,?)',
      [$job, $hotelId, 'Synthetic vendor', 'INV-' . bin2hex(random_bytes(4)), $amount, '2025-03-31', $status, $po]);
};
$invoice(300, 'approved', $order('safety_equipment', 500), null);   // equipment 300, 200 still committed
$invoice(800, 'paid', $order('vehicle', 800), null);                // transportation 800
$invoice(200, 'approved', $order('lodging', 200), null);            // reconcile only
$invoice(640, 'approved', null, $hotel);                            // reconcile only
$invoice(90, 'approved', null, null);                               // other 90
$invoice(120, 'received', null, null);                              // not approved: not counted

// Client invoices: issued counts to reconcile, void does not.
q("INSERT INTO client_invoices(job_id,reference,starts_on,ends_on,total,details_json,status,created_by) VALUES (?,?,'2025-03-02','2025-03-08',8000,'{}','issued',?)", [$job, 'CI-' . bin2hex(random_bytes(4)), $admin]);
q("INSERT INTO client_invoices(job_id,reference,starts_on,ends_on,total,details_json,status,created_by) VALUES (?,?,'2025-03-02','2025-03-08',999,'{}','void',?)", [$job, 'CI-' . bin2hex(random_bytes(4)), $admin]);

file_put_contents(__DIR__ . '/cost.json', json_encode(['job' => $job, 'ids' => $ids]));
file_put_contents(__DIR__ . '/cost-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
