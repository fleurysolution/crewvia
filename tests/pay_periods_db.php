<?php
/**
 * P1-M06 at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, then the weeks pay_periods_http.py
 * is tested on. Runs last: it locks weeks.
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

check('P1-M06 is in place before the rollback', $table('payroll_runs') && $table('payroll_adjustments') && $table('payroll_run_events'));
$sheets = (int) val('SELECT COUNT(*) FROM timesheets');
sql_file($app . '/install/rollback/p1-m06.sql');
check('Rollback removes every P1-M06 object', ! $table('payroll_runs') && ! $table('payroll_adjustments') && ! $table('payroll_run_events'));
check('Rollback keeps every weekly sheet', (int) val('SELECT COUNT(*) FROM timesheets') === $sheets);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds', $code === 0, $first);
check('Upgrade announces pay periods, and opens none', str_contains($first, 'Pay periods: approval, locking, adjustments and payslips are in place') && (int) val('SELECT COUNT(*) FROM payroll_runs') === 0, $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P1-M06', $code === 0 && ! str_contains($second, 'Pay periods:'), $second);

// ── the weeks ──────────────────────────────────────────────────────────
$fixture  = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$admin    = (int) val("SELECT id FROM users WHERE email = 'admin@test.invalid'");
$week     = week_ending(date('Y-m-d', strtotime('-28 days')));   // the period that is locked
$older    = date('Y-m-d', strtotime($week . ' -7 days'));          // a paid week with a difference
$later    = week_ending(date('Y-m-d', strtotime('-7 days')));      // the open period for adjustments
$day      = static fn(string $we, int $back): string => date('Y-m-d', strtotime($we . ' -' . $back . ' days'));

q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES ('M06 second admin','m06-admin2@test.invalid',?,'admin',0)", [$password]);

$job = static function (string $title) use ($fixture): int {
    q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status)
       VALUES (?,?,'Synthetic project for pay periods.',20,35,0,0,0,'active')", [(int) $fixture['client'], $title]);
    return (int) db()->lastInsertId();
};
$jobG = $job('M06 period project');
$jobH = $job('M06 other project');

$person = static function (string $label, int $jobId, bool $login) use ($password, $older): array {
    $email = 'm06-' . $label . '@test.invalid';
    q('INSERT INTO candidates(full_name,email) VALUES (?,?)', ['M06 ' . $label, $email]);
    $cid = (int) db()->lastInsertId();
    q("INSERT INTO employee_profiles(candidate_id,employment_type,flsa_status,adp_employee_id,payment_method) VALUES (?,'hourly','non_exempt',?,'direct_deposit')", [$cid, 'M06' . strtoupper($label)]);
    if ($login) {
        q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,'worker',0)", ['M06 ' . $label, $email, $password]);
        q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [(int) db()->lastInsertId(), $cid]);
    }
    q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site',?,20,35)", [$cid, $jobId, date('Y-m-d', strtotime($older . ' -6 days'))]);
    $pid = (int) db()->lastInsertId();
    q("INSERT INTO assignment_details(placement_id,trade) VALUES (?,'Labourer')", [$pid]);
    return [$cid, $pid];
};
$attend = static function (int $pid, string $date, float $h) use ($admin): void {
    q("INSERT INTO attendance_records(placement_id,work_date,hours,status,submitted_by,reviewed_by,reviewed_at) VALUES (?,?,?,'approved',?,?,NOW())", [$pid, $date, $h, $admin, $admin]);
};

[$cWorker, $pWorker] = $person('worker', $jobG, true);
foreach ([5, 4, 3, 2, 1] as $back) { $attend($pWorker, $day($week, $back), 8.0); }
[$cOther, $pOther] = $person('elsewhere', $jobH, false);
q("INSERT INTO timesheets(placement_id,week_ending,hours_worked,status) VALUES (?,?,8,'draft')", [$pOther, $week]);

// A week already paid at 8 hours whose approved days now say 10.
[$cDiff, $pDiff] = $person('difference', $jobG, false);
$attend($pDiff, $day($older, 3), 10.0);
q("INSERT INTO timesheets(placement_id,week_ending,hours_worked,status,approved_by,approved_at) VALUES (?,?,8,'approved',?,NOW())", [$pDiff, $older, $admin]);
$diffSheet = (int) db()->lastInsertId();
q("INSERT INTO pay_snapshots(timesheet_id,result_json) VALUES (?,?)", [$diffSheet, json_encode(['labour_cost' => 160, 'pay_total' => 160, 'worked' => 8])]);
q("INSERT INTO attendance_payroll_sources(timesheet_id,attendance_json,imported_by) VALUES (?,?,?)", [$diffSheet, json_encode([['hours' => 8]]), $admin]);

// Its own administrator: the shared one is limited to 10 sign-ins in 15
// minutes (BACKLOG Q1), and every suite signing in as it ran it out.
q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,'admin',0)",
  ['M06 administrator', 'm06-admin1@test.invalid', password_hash('TestPassword123!', PASSWORD_DEFAULT)]);

file_put_contents(__DIR__ . '/m06.json', json_encode([
    'week' => $week, 'later' => $later, 'job_g' => $jobG, 'job_h' => $jobH,
    'c_worker' => $cWorker, 'p_worker' => $pWorker, 'c_other' => $cOther, 'c_diff' => $cDiff, 'diff_sheet' => $diffSheet,
    'day' => $day($week, 3),
]));
file_put_contents(__DIR__ . '/m06-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
