<?php
/**
 * P5-M31 at the database level, on the isolated test database only:
 * rollback (ADP employee IDs kept; the registration date and code gone) and
 * upgrade, silence on a repeat; then the fixture for
 * adp_registration_http.py, on the walkthrough fixture: a payroll clerk and
 * a worker with a portal on site.
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
$column = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'employee_profiles' AND column_name = 'adp_registered_at'");
$app = __DIR__ . '/test-app';
$wk = json_decode((string) file_get_contents(__DIR__ . '/walkthrough.json'), true);

check('P5-M31 is in place before the rollback', $column() === 1);
$ids = (int) val('SELECT COUNT(*) FROM employee_profiles WHERE adp_employee_id IS NOT NULL');
q("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('adp_registration_code', 'ROLLBACK-1') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m31.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes the registration date and code, and keeps every ADP employee ID',
      $column() === 0 && ! val("SELECT COUNT(*) FROM platform_settings WHERE setting_key LIKE 'adp_registration_%'") && (int) val('SELECT COUNT(*) FROM employee_profiles WHERE adp_employee_id IS NOT NULL') === $ids);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces ADP registration, and that Crewvia never asks for an SSN', $code === 0 && str_contains($first, 'ADP registration: payroll enters the registration code') && str_contains($first, 'never asks for an SSN') && $column() === 1, $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M31', $code === 0 && ! str_contains($second, 'ADP registration:'), $second);

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['ADP payroll', 'adp-payroll@test.invalid', $password, 'payroll']);
$payroll = (int) db()->lastInsertId();
q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['ADP worker', 'adp-worker@test.invalid', $password, 'worker']);
$workerUser = (int) db()->lastInsertId();
q("INSERT INTO candidates(full_name,email,stage) VALUES ('Aaa Adp Worker','adp-worker@test.invalid','placed')");
$worker = (int) db()->lastInsertId();
q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$workerUser, $worker]);
q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate) VALUES (?,?,'on_site',CURDATE(),45)", [$worker, $wk['job']]);
file_put_contents(__DIR__ . '/adp_registration.json', json_encode(compact('payroll', 'workerUser', 'worker')));
file_put_contents(__DIR__ . '/adp-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
