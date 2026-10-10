<?php
/**
 * P1-M05 at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, the seeded advance item, then the
 * people gross_to_net_http.py is tested on. Runs after the older suites.
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
$app = __DIR__ . '/test-app';

check('P1-M05 is in place before the rollback', $table('pay_items') && $table('employee_pay_items') && $col('wage_advance_payments', 'timesheet_id'));
$repayments = (int) val('SELECT COUNT(*) FROM wage_advance_payments');
sql_file($app . '/install/rollback/p1-m05.sql');
check('Rollback removes every P1-M05 object', ! $table('pay_items') && ! $table('employee_pay_items') && ! $col('wage_advance_payments', 'timesheet_id'));
check('Rollback keeps every advance repayment', (int) val('SELECT COUNT(*) FROM wage_advance_payments') === $repayments);

[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds', $code === 0, $first);
check('Upgrade announces gross to net', str_contains($first, 'Pay: deductions and employer contributions are in place'), $first);
check('The advance repayment item is seeded once', (int) val("SELECT COUNT(*) FROM pay_items WHERE method = 'advance_repayment'") === 1);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P1-M05', $code === 0 && ! str_contains($second, 'Pay:'), $second);
check('A second upgrade seeds nothing more', (int) val('SELECT COUNT(*) FROM pay_items') === 1);

// ── the people ─────────────────────────────────────────────────────────
$fixture  = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$admin    = (int) val("SELECT id FROM users WHERE email = 'admin@test.invalid'");
$week     = week_ending(date('Y-m-d', strtotime('-14 days')));
$day      = static fn(int $back): string => date('Y-m-d', strtotime($week . ' -' . $back . ' days'));

$job = static function (string $title) use ($fixture): int {
    q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status)
       VALUES (?,?,'Synthetic project for gross to net.',20,35,0,0,0,'active')", [(int) $fixture['client'], $title]);
    return (int) db()->lastInsertId();
};
$jobE = $job('M05 net project');
$jobF = $job('M05 second project');

$person = static function (string $label) use ($password): int {
    q('INSERT INTO candidates(full_name,email) VALUES (?,?)', ['M05 ' . $label, 'm05-' . $label . '@test.invalid']);
    $cid = (int) db()->lastInsertId();
    q("INSERT INTO employee_profiles(candidate_id,employment_type,flsa_status,adp_employee_id,payment_method) VALUES (?,'hourly','non_exempt',?,'direct_deposit')",
      [$cid, 'M05' . strtoupper($label)]);
    return $cid;
};
$place = static function (int $cid, int $jobId) use ($day): int {
    q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site',?,20,35)", [$cid, $jobId, $day(13)]);
    $pid = (int) db()->lastInsertId();
    q("INSERT INTO assignment_details(placement_id,trade) VALUES (?,'Labourer')", [$pid]);
    return $pid;
};
$attend = static function (int $pid, array $hours) use ($admin, $day): void {
    foreach ($hours as $back => $h) {
        q("INSERT INTO attendance_records(placement_id,work_date,hours,status,submitted_by,reviewed_by,reviewed_at) VALUES (?,?,?,'approved',?,?,NOW())",
          [$pid, $day($back), $h, $admin, $admin]);
    }
};
$advance = static function (int $cid, float $amount, float $weekly) use ($admin, $day): int {
    q("INSERT INTO wage_advances(candidate_id,amount,weekly_repayment,status,approved_by,approved_at,paid_out_on) VALUES (?,?,?,'paid_out',?,NOW(),?)",
      [$cid, $amount, $weekly, $admin, $day(10)]);
    return (int) db()->lastInsertId();
};

$full = $person('full');       $pFull = $place($full, $jobE);   $attend($pFull, [5 => 8, 4 => 8, 3 => 8, 2 => 8, 1 => 8]);
$aFull = $advance($full, 300, 100);
$split = $person('split');     $pSplitE = $place($split, $jobE); $pSplitF = $place($split, $jobF);
$attend($pSplitE, [5 => 10, 4 => 10]); $attend($pSplitF, [3 => 10, 2 => 10]);
$short = $person('short');     $pShort = $place($short, $jobE); $attend($pShort, [5 => 5]);
$aShort = $advance($short, 70, 100);

file_put_contents(__DIR__ . '/m05.json', json_encode([
    'week' => $week, 'job_e' => $jobE, 'job_f' => $jobF,
    'c_full' => $full, 'c_split' => $split, 'c_short' => $short,
    'p_full' => $pFull, 'p_split_e' => $pSplitE, 'p_split_f' => $pSplitF, 'p_short' => $pShort,
    'a_full' => $aFull, 'a_short' => $aShort, 'start' => $day(6),
]));
file_put_contents(__DIR__ . '/m05-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
