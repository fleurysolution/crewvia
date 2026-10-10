<?php
/**
 * P1-M02 at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, and the synthetic week that
 * attendance_controls_http.py exercises through the screens.
 *
 * The week is two weeks back, so every day in it is in the past and a
 * submission in it has been waiting long enough to be flagged.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') {
    fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n");
    exit(2);
}

$results = [];

function check(string $label, bool $condition, string $detail = ''): void
{
    global $results;

    if (! $condition) {
        fwrite(STDERR, 'FAIL ' . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL);
        exit(1);
    }

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
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $code);

    return [$code, implode("\n", $out)];
}

$has = static fn(string $table, ?string $column = null): bool => (bool) ($column === null
    ? val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table])
    : val('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $column]));

$app = __DIR__ . '/test-app';

// ── down, up, up ───────────────────────────────────────────────────────
check('P1-M02 is in place before the rollback',
      $has('attendance_corrections') && $has('attendance_records', 'source') && $has('attendance_records', 'note'));

$days = (int) val('SELECT COUNT(*) FROM attendance_records');
sql_file($app . '/install/rollback/p1-m02.sql');

check('Rollback removes every P1-M02 object',
      ! $has('attendance_corrections') && ! $has('attendance_records', 'source') && ! $has('attendance_records', 'note'));
check('Rollback keeps every attendance day', (int) val('SELECT COUNT(*) FROM attendance_records') === $days);

[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds', $code === 0, $first);
check('Upgrade announces the attendance controls', str_contains($first, 'Attendance: corrections and staff-entered days are in place'), $first);
check('Existing days are kept as entered by the worker',
      (int) val("SELECT COUNT(*) FROM attendance_records WHERE source = 'worker'") === $days);

[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P1-M02', $code === 0 && ! str_contains($second, 'Attendance:'), $second);

// ── the week the screens are tested on ─────────────────────────────────
$fixture  = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$week     = week_ending(date('Y-m-d', strtotime('-14 days')));
$day      = static fn(int $back): string => date('Y-m-d', strtotime($week . ' -' . $back . ' days'));
$start    = $day(13);
$admin    = (int) val("SELECT id FROM users WHERE email = 'admin@test.invalid'");
$super    = (int) $fixture['supervisor'];

q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES ('M02 other supervisor','m02-supervisor@test.invalid',?,'supervisor',0)", [$password]);
$otherSuper = (int) db()->lastInsertId();

$worker = static function (string $label) use ($password): array {
    $email = 'm02-' . str_replace(' ', '-', $label) . '@test.invalid';
    q('INSERT INTO candidates(full_name,email) VALUES (?,?)', ['M02 ' . $label, $email]);
    $cid = (int) db()->lastInsertId();
    q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,'worker',0)", ['M02 ' . $label, $email, $password]);
    $uid = (int) db()->lastInsertId();
    q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$uid, $cid]);

    return [$cid, $uid];
};

$place = static function (int $cid, int $job, int $supervisor) use ($start): int {
    q("INSERT INTO placements(candidate_id,job_id,status,start_date) VALUES (?,?,'on_site',?)", [$cid, $job, $start]);
    $pid = (int) db()->lastInsertId();
    q("INSERT INTO assignment_details(placement_id,trade,supervisor_id) VALUES (?,'Welder',?)", [$pid, $supervisor]);

    return $pid;
};

$attend = static function (int $pid, string $date, float $hours, string $status, int $by) use ($admin): int {
    q('INSERT INTO attendance_records(placement_id,work_date,hours,status,submitted_by,reviewed_by,reviewed_at)
       VALUES (?,?,?,?,?,?,?)',
      [$pid, $date, $hours, $status, $by, $status === 'submitted' ? null : $admin, $status === 'submitted' ? null : date('Y-m-d H:i:s')]);

    return (int) db()->lastInsertId();
};

// P: the person whose week has one of everything.
[$cP, $uP] = $worker('mixed week');
$p  = $place($cP, (int) $fixture['a'], $super);
$pb = $place($cP, (int) $fixture['b'], $otherSuper);

q('INSERT INTO assignment_checkins(placement_id,work_date,present,marked_by) VALUES (?,?,1,?)', [$p, $day(5), $admin]);   // present, no hours
q('INSERT INTO assignment_checkins(placement_id,work_date,present,marked_by) VALUES (?,?,0,?)', [$p, $day(4), $admin]);   // absent, with hours
$absentDay = $attend($p, $day(4), 8, 'approved', $uP);
$longDay   = $attend($p, $day(3), 18, 'approved', $uP);
$attend($p, $day(2), 6, 'approved', $uP);                                                                                   // and 4 h on job b
$attend($pb, $day(2), 4, 'approved', $uP);
$waiting   = $attend($p, $day(1), 8, 'submitted', $uP);

// Q: a week already approved for pay, with 10 h on the sheet against 8 approved.
[$cQ, $uQ] = $worker('frozen week');
$qp = $place($cQ, (int) $fixture['a'], $super);
$frozenDay = $attend($qp, $day(4), 8, 'approved', $uQ);
q("INSERT INTO timesheets(placement_id,week_ending,hours_worked,status,approved_by,approved_at) VALUES (?,?,10,'approved',?,NOW())", [$qp, $week, $admin]);

// R: hours typed on a sheet with nothing behind them.
[$cR, $uR] = $worker('typed sheet');
$rp = $place($cR, (int) $fixture['a'], $super);
q("INSERT INTO timesheets(placement_id,week_ending,hours_worked,status) VALUES (?,?,40,'draft')", [$rp, $week]);

check('The test week is in the past', $week < date('Y-m-d'));

// Today, for the overview on Activity: one present, one absent, one on leave.
q('INSERT INTO assignment_checkins(placement_id,work_date,present,marked_by) VALUES (?,CURDATE(),1,?)', [$p, $admin]);
q('INSERT INTO assignment_checkins(placement_id,work_date,present,marked_by) VALUES (?,CURDATE(),0,?)', [$qp, $admin]);
q("INSERT INTO time_off_requests(placement_id,user_id,starts_on,ends_on,request_type,status,reviewed_by,reviewed_at)
   VALUES (?,?,DATE_SUB(CURDATE(),INTERVAL 1 DAY),DATE_ADD(CURDATE(),INTERVAL 1 DAY),'Personal','approved',?,NOW())", [$rp, $uR, $admin]);

file_put_contents(__DIR__ . '/m02.json', json_encode([
    'week' => $week, 'days' => array_map($day, [6, 5, 4, 3, 2, 1, 0]), 'start' => $start,
    'p' => $p, 'pb' => $pb, 'q' => $qp, 'r' => $rp,
    'absent_day' => $absentDay, 'long_day' => $longDay, 'waiting' => $waiting, 'frozen_day' => $frozenDay,
]));

file_put_contents(__DIR__ . '/m02-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
