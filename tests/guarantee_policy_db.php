<?php
/**
 * P5-M34 at the database level, on the isolated test database only:
 * rollback and upgrade, silence on a repeat, nothing paid differently by
 * default; then the fixture for guarantee_policy_http.py, on the walkthrough
 * fixture: a payroll clerk, and a worker at $50 an hour and $30 per diem with
 * an approved week of 41 hours and 5 per diem days, absent on Tuesday
 * (unexcused) and on Wednesday (approved time off); ADP RUN codes set, in
 * sandbox.
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
function run_php(string $script): array
{
    $out = []; $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}
$parts = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'project_pay_policies' AND column_name IN ('guarantee_absence_rule','guarantee_day_hours','guarantee_overtime')) OR (table_name = 'placements' AND column_name = 'per_diem_taxable'))");
$app = __DIR__ . '/test-app';
$wk = json_decode((string) file_get_contents(__DIR__ . '/walkthrough.json'), true);

check('P5-M34 is in place before the rollback', $parts() === 4);
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m34.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes the policy columns and the taxable flag', $parts() === 0);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces the guarantee policy and taxable per diem, and that nothing changes until chosen', $code === 0 && str_contains($first, 'Guarantee policy:') && str_contains($first, 'Nothing changes until it is chosen') && str_contains($first, 'Per diem: payroll marks') && $parts() === 4, $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M34', $code === 0 && ! str_contains($second, 'Guarantee policy:') && ! str_contains($second, 'Per diem: payroll marks'), $second);
check('By default every project keeps its guarantee and pays guaranteed hours as regular', ! val("SELECT COUNT(*) FROM project_pay_policies WHERE guarantee_absence_rule <> 'keep' OR guarantee_overtime <> 0") && ! val('SELECT COUNT(*) FROM placements WHERE per_diem_taxable <> 0'));

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Guarantee payroll', 'gp-payroll@test.invalid', $password, 'payroll']);
$payroll = (int) db()->lastInsertId();
q("INSERT INTO candidates(full_name,email,stage) VALUES ('Aaa Guarantee Worker','gp-worker@test.invalid','placed')");
$worker = (int) db()->lastInsertId();
q("INSERT INTO employee_profiles(candidate_id, adp_employee_id) VALUES (?, 'GP001')", [$worker]);
$week = date('Y-m-d', strtotime('last saturday'));
q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,per_diem_rate) VALUES (?,?,'on_site',DATE_SUB(?, INTERVAL 20 DAY),50,30)", [$worker, $wk['job'], $week]);
$placement = (int) db()->lastInsertId();
q('UPDATE jobs SET guarantee_hours = 50, strike_live = 0 WHERE id = ?', [$wk['job']]);
q("INSERT INTO timesheets(placement_id,week_ending,hours_worked,per_diem_days,expenses,status) VALUES (?,?,41,5,0,'approved')", [$placement, $week]);
$timesheet = (int) db()->lastInsertId();
$tuesday = date('Y-m-d', strtotime($week . ' -4 days'));
$wednesday = date('Y-m-d', strtotime($week . ' -3 days'));
q('INSERT INTO assignment_checkins(placement_id,work_date,present,note) VALUES (?,?,0,?),(?,?,0,?)', [$placement, $tuesday, 'No show', $placement, $wednesday, 'Doctor']);
q("INSERT INTO time_off_requests(placement_id,user_id,starts_on,ends_on,request_type,status) VALUES (?,?,?,?,'Medical','approved')", [$placement, $wk['ids']['admin'], $wednesday, $wednesday]);
foreach (['adp_company_code' => 'RSS1', 'adp_hours_code' => 'REG', 'adp_perdiem_code' => 'PD', 'adp_overtime_code' => 'OT'] as $k => $v) {
    q('INSERT INTO platform_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)', [$k, $v]);
}
q("DELETE FROM platform_settings WHERE setting_key = 'adp_perdiem_taxable_code'");
q("INSERT INTO connector_modes (connector, kind, mode) VALUES ('adp_run', 'payroll', 'sandbox') ON DUPLICATE KEY UPDATE mode = 'sandbox'");
file_put_contents(__DIR__ . '/guarantee_policy.json', json_encode(compact('payroll', 'worker', 'placement', 'timesheet', 'week')));
file_put_contents(__DIR__ . '/gp-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
