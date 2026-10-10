<?php
/**
 * P2-M04 at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, then three people for
 * benefits_http.py: an hourly worker hired 40 days ago with a week already
 * approved, a contractor, and an hourly worker hired 10 days ago.
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
$all = ['benefit_plans', 'benefit_plan_tiers', 'benefit_enrollments'];

check('P2-M04 is in place before the rollback', count(array_filter($all, $table)) === 3 && $col('employee_pay_items', 'benefit_enrollment_id'));
$items = (int) val('SELECT COUNT(*) FROM employee_pay_items');
sql_file($app . '/install/rollback/p2-m04.sql');
check('Rollback removes every P2-M04 object', count(array_filter($all, $table)) === 0 && ! $col('employee_pay_items', 'benefit_enrollment_id'));
check('Rollback keeps every pay item on people', (int) val('SELECT COUNT(*) FROM employee_pay_items') === $items);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds and announces benefits', $code === 0 && str_contains($first, 'Benefits: plans, eligibility, enrollment and waivers'), $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P2-M04', $code === 0 && ! str_contains($second, 'Benefits:'), $second);

$fixture  = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['payroll' => 'payroll', 'recruiter' => 'recruiter', 'worker' => 'worker'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Benefits ' . $k, 'ben-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status) VALUES (?,'P2-M04 project','Synthetic project for benefits.',25,40,0,0,0,'active')", [(int) $fixture['client']]);
$job = (int) db()->lastInsertId();
$person = static function (string $label, string $type, int $daysAgo) use ($job): array {
    q('INSERT INTO candidates(full_name,email) VALUES (?,?)', ['Benefit ' . $label, 'ben-c-' . $label . '@test.invalid']);
    $cid = (int) db()->lastInsertId();
    q("INSERT INTO employee_profiles(candidate_id,employment_type,flsa_status,payment_method) VALUES (?,?, 'non_exempt', 'direct_deposit')", [$cid, $type]);
    q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site',DATE_SUB(CURDATE(), INTERVAL ? DAY),25,40)", [$cid, $job, $daysAgo]);
    return ['cid' => $cid, 'pid' => (int) db()->lastInsertId()];
};
$a = $person('alpha', 'hourly', 40);
$b = $person('bravo', 'contractor', 40);
$c = $person('charlie', 'hourly', 10);
q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$ids['worker'], $a['cid']]);
// A week of Alpha's already approved for pay: last week, after Alpha became eligible.
$frozen = week_ending(date('Y-m-d', strtotime('-7 days')));
q("INSERT INTO timesheets(placement_id,week_ending,hours_worked,status,approved_at) VALUES (?,?,40,'approved',NOW())", [$a['pid'], $frozen]);

$day = static fn(int $n): string => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days'));
file_put_contents(__DIR__ . '/ben.json', json_encode(['ids' => $ids, 'job' => $job, 'a' => $a, 'b' => $b, 'c' => $c, 'frozen' => $frozen,
    'today' => $day(0), 'in14' => $day(14), 'in20' => $day(20), 'in30' => $day(30), 'ago10' => $day(-10), 'ago20' => $day(-20),
    'week_next' => week_ending($day(7)), 'week_later' => week_ending($day(28)), 'c_eligible' => $day(20)]));
file_put_contents(__DIR__ . '/ben-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
