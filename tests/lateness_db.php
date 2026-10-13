<?php
/**
 * P5-M14 (T19) at the database level, on the isolated test database only:
 * rollback (attendance and weekly sheets kept) and upgrade, silence on a
 * repeat; the rules (grace, rounding, a night shift's arrival after
 * midnight); then the fixture for lateness_http.py: payroll, a supervisor,
 * Logistics, two workers on one project (one on this supervisor's crew,
 * one not), last week's approved days, and a week two weeks ago frozen
 * for pay.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/lateness.php';

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
$tables = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('attendance_lateness','lateness_policies')");
$columns = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'timesheets' AND column_name IN ('late_docked_hours','late_billed_hours')) OR (table_name = 'assignment_details' AND column_name = 'shift_start'))");
$app = __DIR__ . '/test-app';

check('P5-M14 is in place before the rollback', $tables() === 2 && $columns() === 3);
$sheets = (int) val('SELECT COUNT(*) FROM timesheets');
$days = (int) val('SELECT COUNT(*) FROM attendance_records');
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m14.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes lateness and keeps every weekly sheet and day', $tables() === 0 && $columns() === 0 && (int) val('SELECT COUNT(*) FROM timesheets') === $sheets && (int) val('SELECT COUNT(*) FROM attendance_records') === $days);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces lateness, with no shift start set yet', $code === 0 && str_contains($first, 'Lateness: late arrivals against the shift start') && $tables() === 2 && $columns() === 3, $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M14', $code === 0 && ! str_contains($second, 'Lateness:'), $second);

// ── the rules ──────────────────────────────────────────────────────────
check('Minutes late against the shift start', lateness_minutes('07:00:00', '07:07:00') === 7 && lateness_minutes('07:00:00', '06:50:00') === -10);
check('A night shift\'s arrival after midnight counts from the evening before', lateness_minutes('22:00:00', '00:20:00') === 140);
check('Within the grace nothing is docked; past it the whole lateness, rounded up', lateness_docked(5, 5, 15) === 0 && lateness_docked(7, 5, 15) === 15 && lateness_docked(31, 5, 15) === 45 && lateness_docked(16, 0, 15) === 30);
check('Rounding to one minute docks the exact minutes', lateness_docked(7, 5, 1) === 7);
check('Times are read as typed', lateness_time('7:05') === '07:05:00' && lateness_time('24:00') === null && lateness_time('7am') === null);

// ── the fixture ────────────────────────────────────────────────────────
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['pay' => 'payroll', 'sup' => 'supervisor', 'hotels' => 'hotels', 'w' => 'worker', 'w2' => 'worker'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Late ' . $k, 'lt-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO clients(name) VALUES ('Lateness client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate) VALUES (?,'P5-M14 site','active',30,50,0,0,0)", [$client]);
$job = (int) db()->lastInsertId();
$places = $cands = [];
foreach (['w' => $ids['sup'], 'w2' => null] as $k => $supervisor) {
    q("INSERT INTO candidates(full_name,email,stage) VALUES (?,?,'placed')", ['Late worker ' . $k, 'lt-cand-' . $k . '@test.invalid']);
    $cands[$k] = (int) db()->lastInsertId();
    q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$ids[$k], $cands[$k]]);
    q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site',?,30,50)", [$cands[$k], $job, date('Y-m-d', strtotime('-60 days'))]);
    $places[$k] = (int) db()->lastInsertId();
    q('INSERT INTO assignment_details(placement_id,trade,supervisor_id) VALUES (?,?,?)', [$places[$k], 'Operator', $supervisor]);
}
$week = week_ending(date('Y-m-d', strtotime('-7 days')));
$day = [];
foreach (['mon' => 5, 'tue' => 4, 'wed' => 3, 'thu' => 2, 'fri' => 1] as $k => $back) {
    $day[$k] = date('Y-m-d', strtotime($week . ' -' . $back . ' days'));
    foreach (['w', 'w2'] as $who) {
        q("INSERT INTO attendance_records(placement_id,work_date,hours,status,submitted_by,reviewed_by,reviewed_at) VALUES (?,?,10,'approved',?,?,NOW())", [$places[$who], $day[$k], $ids[$who], $ids['pay']]);
    }
}
$frozenWeek = week_ending(date('Y-m-d', strtotime('-14 days')));
$frozenDay = date('Y-m-d', strtotime($frozenWeek . ' -3 days'));
q("INSERT INTO timesheets(placement_id,week_ending,hours_worked,status,approved_by,approved_at) VALUES (?,?,40,'approved',?,NOW())", [$places['w'], $frozenWeek, $ids['pay']]);

file_put_contents(__DIR__ . '/lateness.json', json_encode(['ids' => $ids, 'cands' => $cands, 'places' => $places, 'job' => $job, 'week' => $week, 'day' => $day, 'frozen_day' => $frozenDay]));
file_put_contents(__DIR__ . '/lt-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
