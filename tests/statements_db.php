<?php
/**
 * P3-M05 statements at the database level, on the isolated test database
 * only: rollback, upgrade, silence on a repeat, then records dated in 2018
 * and 2019, years no other test uses, so statements_http.py can expect
 * exact figures:
 *
 *   2018-12-10  invoice 1,000 (P3-M05 project)
 *   2019-03-05  invoice 10,000 (P3-M05 project)
 *   2019-03-07  invoice 500 (P3-M05 other project)
 *   2019-03-10  hotel bill 2,000 (P3-M05 project)
 *   2019-03-15  claim paid by the agency 300 (P3-M05 project)
 *   2019-03-20  payment received 8,000 on the 10,000 invoice
 *   2019-03-25  payment made 1,500 on the hotel bill
 *
 * The owner's contribution and the office lease are typed as journals by
 * the HTTP test.
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

check('The P3-M05 statements are in place before the rollback', $table('gl_budgets') && $table('gl_budget_events'));
$journals = (int) val('SELECT COUNT(*) FROM gl_journals');
sql_file($app . '/install/rollback/p3-m05-statements.sql');
check('Rollback removes the budget tables', ! $table('gl_budgets') && ! $table('gl_budget_events'));
check('Rollback keeps every ledger journal', (int) val('SELECT COUNT(*) FROM gl_journals') === $journals);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds and announces the statements', $code === 0 && str_contains($first, 'Statements: income statement, balance sheet and cash flow are read from the ledger'), $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for the statements', $code === 0 && ! str_contains($second, 'Statements:'), $second);
check('Nothing in the ledger is dated 2018 or 2019 yet', ! val("SELECT COUNT(*) FROM gl_journals WHERE posted_on < '2020-01-01'"));

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'payroll' => 'payroll', 'recruiter' => 'recruiter'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Statements ' . $k, 'st-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
$admin = $ids['admin'];

q("INSERT INTO clients(name) VALUES ('Statements client')");
$client = (int) db()->lastInsertId();
$job = static function (string $title) use ($client): int {
    q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status)
       VALUES (?,?,'Synthetic project for the statements.',30,95,0,0,30,'active')", [$client, $title]);
    return (int) db()->lastInsertId();
};
$jobA = $job('P3-M05 project');
$jobB = $job('P3-M05 other project');
$inv = static function (int $jobId, float $total, string $issued) use ($admin): int {
    q("INSERT INTO client_invoices(job_id,reference,starts_on,ends_on,total,details_json,status,created_by,created_at,issued_at) VALUES (?,?,?,?,?,'{}','issued',?,?,?)",
      [$jobId, 'ST-' . strtoupper(bin2hex(random_bytes(3))), $issued, $issued, $total, $admin, $issued . ' 09:00:00', $issued . ' 10:00:00']);
    return (int) db()->lastInsertId();
};
$inv($jobA, 1000, '2018-12-10');
$main = $inv($jobA, 10000, '2019-03-05');
$inv($jobB, 500, '2019-03-07');

q("INSERT INTO hotels(name) VALUES ('Statements inn')");
$hotel = (int) db()->lastInsertId();
q("INSERT INTO vendor_invoices(job_id,hotel_id,vendor_name,reference,amount,due_on,status) VALUES (?,?,'Statements inn',?,2000,'2019-03-10','approved')", [$jobA, $hotel, 'STB-' . bin2hex(random_bytes(4))]);
$bill = (int) db()->lastInsertId();

q('INSERT INTO candidates(full_name,email) VALUES (?,?)', ['Stated one', 'st-c-one@test.invalid']);
$cand = (int) db()->lastInsertId();
q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site','2019-03-01',30,95)", [$cand, $jobA]);
$placement = (int) db()->lastInsertId();
q("INSERT INTO worker_documents(candidate_id,uploaded_by,document_type,original_name,storage_name,mime_type,file_hash) VALUES (?,?,'receipt','r.pdf',?,'application/pdf',?)",
  [$cand, $admin, bin2hex(random_bytes(32)), str_repeat('0', 64)]);
$doc = (int) db()->lastInsertId();
q("INSERT INTO expense_claims(placement_id,user_id,category,amount,receipt_document_id,status,payer,paid_at) VALUES (?,?,'other',300,?,'paid','agency','2019-03-15 09:00:00')", [$placement, $admin, $doc]);

q("INSERT INTO ar_payments(client_id,received_on,amount,method,reference,created_by) VALUES (?,'2019-03-20',8000,'check','ST-CHK-1',?)", [$client, $admin]);
q('INSERT INTO ar_allocations(payment_id,invoice_id,amount,created_by) VALUES (?,?,8000,?)', [(int) db()->lastInsertId(), $main, $admin]);
q("INSERT INTO ap_payments(vendor_name,received_on,amount,method,reference,created_by) VALUES ('Statements inn','2019-03-25',1500,'ach','ST-ACH-1',?)", [$admin]);
q('INSERT INTO ap_allocations(payment_id,invoice_id,amount,created_by) VALUES (?,?,1500,?)', [(int) db()->lastInsertId(), $bill, $admin]);

file_put_contents(__DIR__ . '/statements.json', json_encode(['ids' => $ids]));
file_put_contents(__DIR__ . '/st-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
