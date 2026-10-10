<?php
/**
 * P1-M04 at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, then the people leave_http.py
 * is tested on. Runs after the older HTTP suites.
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
$col = static fn(string $t, string $c): bool => (bool) val('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
$app = __DIR__ . '/test-app';
$m04 = ['accrual_method', 'accrual_hours_per_day', 'accrual_cap_days', 'carryover_max_days', 'eligible_after_days', 'eligible_employment_types', 'hours_per_day'];

check('P1-M04 is in place before the rollback', $col('timesheets', 'paid_leave_hours') && ! in_array(false, array_map(fn($c) => $col('leave_types', $c), $m04), true));
$types = (int) val('SELECT COUNT(*) FROM leave_types');
sql_file($app . '/install/rollback/p1-m04.sql');
check('Rollback removes every P1-M04 column', ! $col('timesheets', 'paid_leave_hours') && ! in_array(true, array_map(fn($c) => $col('leave_types', $c), $m04), true));
check('Rollback keeps every leave type', (int) val('SELECT COUNT(*) FROM leave_types') === $types);

[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds', $code === 0, $first);
check('Upgrade announces the leave controls', str_contains($first, 'Leave: accrual, carryover, eligibility and paid leave on the weekly sheet are in place'), $first);
check('Existing leave types keep their yearly allowance',
      (int) val("SELECT COUNT(*) FROM leave_types WHERE accrual_method <> 'annual' OR carryover_max_days <> 0 OR eligible_after_days <> 0 OR eligible_employment_types IS NOT NULL") === 0
      && (int) val("SELECT days_allowed FROM leave_types WHERE slug = 'sick'") === 5);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P1-M04', $code === 0 && ! str_contains($second, 'Leave:'), $second);

// ── the people ─────────────────────────────────────────────────────────
$fixture  = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$admin    = (int) val("SELECT id FROM users WHERE email = 'admin@test.invalid'");
$year     = (int) date('Y');
$week     = week_ending(date('Y-m-d', strtotime('-14 days')));
$day      = static fn(int $back): string => date('Y-m-d', strtotime($week . ' -' . $back . ' days'));

$person = static function (string $label, string $type, int $job, string $start) use ($password): array {
    $email = 'm04-' . $label . '@test.invalid';
    q('INSERT INTO candidates(full_name,email) VALUES (?,?)', ['M04 ' . $label, $email]);
    $cid = (int) db()->lastInsertId();
    q("INSERT INTO employee_profiles(candidate_id,employment_type,flsa_status,adp_employee_id,payment_method) VALUES (?,?,?,?,'direct_deposit')",
      [$cid, $type, $type === 'contractor' ? 'not_applicable' : 'non_exempt', 'M04' . strtoupper(substr(md5($label), 0, 6))]);
    q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,'worker',0)", ['M04 ' . $label, $email, $password]);
    $uid = (int) db()->lastInsertId();
    q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$uid, $cid]);
    q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site',?,20,35)", [$cid, $job, $start]);
    $pid = (int) db()->lastInsertId();
    q("INSERT INTO assignment_details(placement_id,trade) VALUES (?,'Labourer')", [$pid]);
    return [$cid, $uid, $pid];
};
$leave = static function (int $pid, int $uid, string $slug, string $from, string $to) use ($admin): void {
    q("INSERT INTO time_off_requests(placement_id,user_id,starts_on,ends_on,request_type,leave_type,status,reviewed_by,reviewed_at)
       VALUES (?,?,?,?,?,?,'approved',?,NOW())", [$pid, $uid, $from, $to, $slug, $slug, $admin]);
};
$attend = static function (int $pid, string $date, float $h) use ($admin): void {
    q("INSERT INTO attendance_records(placement_id,work_date,hours,status,submitted_by,reviewed_by,reviewed_at) VALUES (?,?,?,'approved',?,?,NOW())", [$pid, $date, $h, $admin, $admin]);
};

$a = (int) $fixture['a'];
[$cNew, $uNew, $pNew] = $person('newcomer', 'hourly', $a, date('Y-m-d', strtotime('-10 days')));
[$cContractor, $uContractor, $pContractor] = $person('contractor', 'contractor', $a, date('Y-m-d', strtotime('-200 days')));
[$cAccrual, $uAccrual, $pAccrual] = $person('accrual', 'hourly', $a, date('Y-m-d', strtotime('-400 days')));

// Accrual: 960 approved hours last year and 600 this year; 1 day taken last year.
foreach ([[($year - 1) . '-07-04', 480], [($year - 1) . '-08-01', 480], [$year . '-01-10', 600]] as [$we, $h]) {
    q("INSERT INTO timesheets(placement_id,week_ending,hours_worked,status,approved_by,approved_at) VALUES (?,?,?,'approved',?,NOW())", [$pAccrual, $we, $h, $admin]);
}
$leave($pAccrual, $uAccrual, 'm04_sick_accrual', ($year - 1) . '-09-01', ($year - 1) . '-09-01');

// A project with a 50-hour guarantee: 24 hours worked and 2 days of paid sick leave.
q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status)
   VALUES (?,'M04 leave project','Synthetic project for paid leave.',20,35,50,60,0,'active')", [(int) $fixture['client']]);
$jobD = (int) db()->lastInsertId();
[$cPaid, $uPaid, $pPaid] = $person('paid week', 'hourly', $jobD, $day(13));
foreach ([5, 4, 3] as $back) { $attend($pPaid, $day($back), 8.0); }
$leave($pPaid, $uPaid, 'sick', $day(2), $day(1));
// And somebody who worked a day they were on leave.
[$cBoth, $uBoth, $pBoth] = $person('worked on leave', 'hourly', $jobD, $day(13));
$leave($pBoth, $uBoth, 'sick', $day(3), $day(3));
$attend($pBoth, $day(3), 8.0);

file_put_contents(__DIR__ . '/m04.json', json_encode([
    'year' => $year, 'week' => $week, 'job_d' => $jobD, 'p_paid' => $pPaid, 'p_both' => $pBoth,
    'c_accrual' => $cAccrual, 'p_accrual' => $pAccrual, 'p_new' => $pNew, 'p_contractor' => $pContractor,
]));
file_put_contents(__DIR__ . '/m04-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
