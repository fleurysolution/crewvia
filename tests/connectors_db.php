<?php
/**
 * P4-M05 at the database level, on the isolated test database only:
 * rollback, upgrade (existing ADP setup and partner channels kept as they
 * were), silence on a repeat, then the fixture for connectors_http.py: a
 * project, three employees with ADP ids and approved sheets for the week
 * ending 13 June 2015 (a week no other test uses), ADP codes, an open
 * vacancy for partner applications. ADP is put in sandbox to start. The
 * frozen pay carries every field a real approval freezes.
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

check('P4-M05 is in place before the rollback', $table('connector_modes') && $table('payroll_exports') && $table('adp_reconciliations'));
sql_file($app . '/install/rollback/p4-m05.sql');
check('Rollback removes the P4-M05 tables and narrows the audit trail back', ! $table('connector_modes') && ! $table('payroll_exports') && ! $table('adp_reconciliations')
      && ! str_contains((string) val("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'integration_events' AND column_name = 'provider'"), 'adp'));
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces the connectors: the configured partner channel stays live, screening is not connected',
      $code === 0 && str_contains($first, 'Connectors: ADP') && str_contains($first, '1 applicant-tracking channel(s) live as before') && str_contains($first, 'no background-screening provider is connected'), $first);
check('The partner channel enabled before is recorded live', val("SELECT mode FROM connector_modes WHERE connector = 'ats:test_partner'") === 'live');
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P4-M05', $code === 0 && ! str_contains($second, 'Connectors:'), $second);

q("UPDATE connector_modes SET mode = 'sandbox', sandbox_passed_at = NULL, sandbox_note = NULL WHERE connector = 'adp_run'");
foreach (['adp_company_code' => 'P4CO', 'adp_hours_code' => 'REG', 'adp_perdiem_code' => 'PD', 'adp_overtime_code' => 'OT'] as $k => $v) {
    q('INSERT INTO platform_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$k, $v]);
}

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'payroll' => 'payroll', 'recruiter' => 'recruiter'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Connectors ' . $k, 'cn-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO clients(name) VALUES ('Connectors client')");
q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status) VALUES (?,'P4-M05 project','Synthetic project for the connectors.',30,50,0,0,0,'active')", [(int) db()->lastInsertId()]);
$job = (int) db()->lastInsertId();
$week = '2015-06-13';
foreach ([['P4A1', 'Ada Exported', 1200], ['P4A2', 'Ben Exported', 1000], ['P4A3', 'Cy Exported', 800]] as [$adpId, $name, $gross]) {
    q('INSERT INTO candidates(full_name,email,stage) VALUES (?,?,?)', [$name, strtolower(str_replace(' ', '.', $name)) . '@test.invalid', 'placed']);
    $c = (int) db()->lastInsertId();
    q("INSERT INTO employee_profiles(candidate_id,employment_type,payment_method,adp_employee_id) VALUES (?,'hourly','direct_deposit',?)", [$c, $adpId]);
    q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate,per_diem_rate,created_by) VALUES (?,?,'on_site','2015-06-01',30,50,0,?)", [$c, $job, $ids['admin']]);
    $p = (int) db()->lastInsertId();
    q("INSERT INTO timesheets(placement_id,week_ending,hours_worked,per_diem_days,expenses,status,approved_by,approved_at) VALUES (?,?,40,0,0,'approved',?,NOW())", [$p, $week, $ids['admin']]);
    q('INSERT INTO pay_snapshots(timesheet_id,result_json) VALUES (?,?)', [(int) db()->lastInsertId(), json_encode(['worked' => 40, 'paid_hours' => 40, 'pay_rate' => 30, 'pay_total' => $gross, 'labour_cost' => $gross, 'leave_pay' => 0, 'paid_leave_hours' => 0,
        'per_diem' => 0, 'expenses' => 0, 'bill_rate' => 50, 'bill_total' => 2000, 'margin' => 2000 - $gross, 'guarantee' => 0, 'short_by' => 0, 'gross_to_net' => ['gross_wages' => $gross, 'deductions' => [], 'total_deductions' => 0,
        'employer' => [], 'total_employer' => 0, 'reimbursements' => 0, 'net_before_tax' => $gross]])]);
}
q("INSERT INTO vacancies(job_id,title,description,is_open,openings) VALUES (?,'Partner hire','Synthetic vacancy.',1,2)", [$job]);
$vacancy = (int) db()->lastInsertId();

file_put_contents(__DIR__ . '/connectors.json', json_encode(['ids' => $ids, 'job' => $job, 'week' => $week, 'vacancy' => $vacancy]));
check('The P4-M05 fixture is in place', (int) val("SELECT COUNT(*) FROM timesheets WHERE week_ending = '2015-06-13'") === 3);
file_put_contents(__DIR__ . '/cn-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
