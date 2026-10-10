<?php
/**
 * P3-M03 at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, the numbered chart and the
 * triggers, then records for ledger_http.py: an invoice, a bill and a paid
 * claim dated February 2025, and an invoice dated in November 2022, a
 * month already closed when it reaches the ledger.
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
$triggers = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND trigger_name LIKE 'gl\\_%'");
$app = __DIR__ . '/test-app';

check('P3-M03 is in place before the rollback', $table('gl_journals') && $table('gl_lines') && $col('accounting_accounts', 'number') && $triggers() === 5);
$batches = (int) val('SELECT COUNT(*) FROM accounting_batches');
$exported = (int) val('SELECT COUNT(*) FROM accounting_lines');
sql_file($app . '/install/rollback/p3-m03.sql');
check('Rollback removes every P3-M03 object and its triggers', ! $table('gl_journals') && ! $table('gl_lines') && ! $col('accounting_accounts', 'number')
      && ! $col('accounting_accounts', 'is_system') && $triggers() === 0);
check('Rollback keeps the 14 mapped accounts and every export', (int) val('SELECT COUNT(*) FROM accounting_accounts') === 14
      && (int) val('SELECT COUNT(*) FROM accounting_batches') === $batches && (int) val('SELECT COUNT(*) FROM accounting_lines') === $exported);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds and announces the ledger with 16 numbered accounts',
      $code === 0 && str_contains($first, 'Ledger: Crewvia keeps its own books') && str_contains($first, '16 accounts are numbered') && ! str_contains($first, 'WARNING'), $first);
check('The five triggers are back', $triggers() === 5);
check('Every account has a number; the records\' and the equity accounts are built in',
      ! val('SELECT COUNT(*) FROM accounting_accounts WHERE number IS NULL') && (int) val('SELECT COUNT(*) FROM accounting_accounts WHERE is_system = 1') === 16
      && val("SELECT number FROM accounting_accounts WHERE account_key = 'revenue'") === '4000' && val("SELECT side FROM accounting_accounts WHERE account_key = 'retained_earnings'") === 'equity');
check('The equity accounts start unconfirmed for QuickBooks', (int) val("SELECT COUNT(*) FROM accounting_accounts WHERE side = 'equity' AND confirmed = 0") === 2);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P3-M03', $code === 0 && ! str_contains($second, 'Ledger:'), $second);
check('The ledger starts empty: it fills itself from the records', ! val('SELECT COUNT(*) FROM gl_journals'));

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'payroll' => 'payroll', 'payroll2' => 'payroll', 'recruiter' => 'recruiter'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Ledger ' . $k, 'gl-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
$admin = $ids['admin'];

q("INSERT INTO clients(name) VALUES ('Ledger test client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status)
   VALUES (?,'P3-M03 project','Synthetic project for the ledger.',30,95,0,0,30,'active')", [$client]);
$job = (int) db()->lastInsertId();
$inv = static function (float $total, string $issued) use ($job, $admin): int {
    q("INSERT INTO client_invoices(job_id,reference,starts_on,ends_on,total,details_json,status,created_by,created_at,issued_at) VALUES (?,?,?,?,?,'{}','issued',?,?,?)",
      [$job, 'GL-' . strtoupper(bin2hex(random_bytes(3))), $issued, $issued, $total, $admin, $issued . ' 09:00:00', $issued . ' 10:00:00']);
    return (int) db()->lastInsertId();
};
$invFeb = $inv(4000, '2025-02-03');
// November 2022 is closed before this invoice ever reaches the ledger.
q("INSERT INTO financial_periods (period, status, changed_by, changed_at) VALUES ('2022-11', 'closed', ?, NOW())", [$admin]);
$invOld = $inv(1000, '2022-11-20');

q("INSERT INTO hotels(name) VALUES ('Ledger inn')");
$hotel = (int) db()->lastInsertId();
q("INSERT INTO vendor_invoices(job_id,hotel_id,vendor_name,reference,amount,due_on,status) VALUES (?,?,'Ledger inn',?,600,'2025-02-10','approved')", [$job, $hotel, 'GLB-' . bin2hex(random_bytes(4))]);
$bill = (int) db()->lastInsertId();

q('INSERT INTO candidates(full_name,email) VALUES (?,?)', ['Ledgered one', 'gl-c-one@test.invalid']);
$cand = (int) db()->lastInsertId();
q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site','2025-02-01',30,95)", [$cand, $job]);
$placement = (int) db()->lastInsertId();
q("INSERT INTO worker_documents(candidate_id,uploaded_by,document_type,original_name,storage_name,mime_type,file_hash) VALUES (?,?,'receipt','r.pdf',?,'application/pdf',?)",
  [$cand, $admin, bin2hex(random_bytes(32)), str_repeat('0', 64)]);
$doc = (int) db()->lastInsertId();
q("INSERT INTO expense_claims(placement_id,user_id,category,amount,receipt_document_id,status,payer,paid_at) VALUES (?,?,'other',50,?,'paid','agency','2025-02-12 09:00:00')", [$placement, $admin, $doc]);
$claim = (int) db()->lastInsertId();

file_put_contents(__DIR__ . '/ledger.json', json_encode(['ids' => $ids, 'job' => $job, 'inv_feb' => $invFeb, 'inv_old' => $invOld, 'bill' => $bill,
    'claim' => $claim, 'placement' => $placement, 'doc' => $doc]));
file_put_contents(__DIR__ . '/gl-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
