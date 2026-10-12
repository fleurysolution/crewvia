<?php
/**
 * P5-M06b at the database level, on the isolated test database only:
 * rollback (hours and gate marks already sent stay) and upgrade, silence
 * on a repeat, then the fixture for offline_sync_http.py: two workers and
 * a supervisor on one project, a worker's ended assignment, a week frozen
 * for pay, a supervisor with no crew, a recruiter and an administrator.
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
$tables = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('device_grants','attendance_sync_log')");
$app = __DIR__ . '/test-app';

check('P5-M06b is in place before the rollback', $tables() === 2);
$attendance = (int) val('SELECT COUNT(*) FROM attendance_records');
$checkins = (int) val('SELECT COUNT(*) FROM assignment_checkins');
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m06b.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes the two tables and keeps every day of hours and every gate mark', $tables() === 0
      && (int) val('SELECT COUNT(*) FROM attendance_records') === $attendance && (int) val('SELECT COUNT(*) FROM assignment_checkins') === $checkins);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces offline attendance', $code === 0 && str_contains($first, 'Offline attendance: workers and supervisors may trust their phone'), $first);
check('The tables are back', $tables() === 2);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M06b', $code === 0 && ! str_contains($second, 'Offline attendance:'), $second);

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['w' => 'worker', 'w2' => 'worker', 'sup' => 'supervisor', 'sup2' => 'supervisor', 'rec' => 'recruiter', 'admin' => 'admin'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Offline ' . $k, 'os-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("UPDATE users SET locale = 'fr' WHERE id = ?", [$ids['w2']]);
q("INSERT INTO clients(name) VALUES ('Offline client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'P5-M06b compressor station','active')", [$client]);
$jobA = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'P5-M06b old job','active')", [$client]);
$jobB = (int) db()->lastInsertId();
$cands = $places = [];
$place = static function (string $key, int $job, string $status, string $start, ?int $supervisor) use ($ids, &$cands): int {
    if (! isset($cands[$key])) {
        q("INSERT INTO candidates(full_name,email,stage) VALUES (?,?,'placed')", ['Offline ' . $key, 'os-cand-' . $key . '@test.invalid']);
        $cands[$key] = (int) db()->lastInsertId();
        q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$ids[$key], $cands[$key]]);
    }
    q('INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,?,?,30,50)', [$cands[$key], $job, $status, $start]);
    $pid = (int) db()->lastInsertId();
    q('INSERT INTO assignment_details(placement_id,trade,supervisor_id) VALUES (?,?,?)', [$pid, 'Mechanic', $supervisor]);
    return $pid;
};
$places['w'] = $place('w', $jobA, 'on_site', date('Y-m-d', strtotime('-40 days')), $ids['sup']);
$places['w_old'] = $place('w', $jobB, 'completed', date('Y-m-d', strtotime('-90 days')), null);
$places['w2'] = $place('w2', $jobA, 'confirmed', date('Y-m-d', strtotime('-20 days')), $ids['sup']);
// A week frozen for pay: its sheet approved.
$frozenDay = date('Y-m-d', strtotime('-21 days'));
q("INSERT INTO timesheets(placement_id,week_ending,hours_worked,status,approved_by,approved_at) VALUES (?,?,40,'approved',?,NOW())", [$places['w'], week_ending($frozenDay), $ids['admin']]);

file_put_contents(__DIR__ . '/offline_sync.json', json_encode(['ids' => $ids, 'cands' => $cands, 'places' => $places, 'frozen_day' => $frozenDay, 'job_a' => $jobA]));
file_put_contents(__DIR__ . '/os-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
