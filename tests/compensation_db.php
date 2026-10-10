<?php
/**
 * P2-M01 at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, then the people
 * compensation_http.py is tested on.
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

check('P2-M01 is in place before the rollback', $table('pay_grades') && $table('compensation_changes') && $col('employee_profiles', 'grade_id'));
$profiles = (int) val('SELECT COUNT(*) FROM employee_profiles');
sql_file($app . '/install/rollback/p2-m01.sql');
check('Rollback removes every P2-M01 object', ! $table('pay_grades') && ! $table('compensation_changes') && ! $col('employee_profiles', 'grade_id'));
check('Rollback keeps every profile', (int) val('SELECT COUNT(*) FROM employee_profiles') === $profiles);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds and announces compensation', $code === 0 && str_contains($first, 'Compensation: grades with pay bands'), $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P2-M01', $code === 0 && ! str_contains($second, 'Compensation:'), $second);

$fixture  = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
foreach (['admin', 'payroll', 'recruiter'] as $role) {
    q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)", ['Compensation ' . $role, 'comp-' . $role . '@test.invalid', $password, $role]);
}
$admin = (int) val("SELECT id FROM users WHERE email = 'comp-admin@test.invalid'");
$weekB = week_ending(date('Y-m-d', strtotime('-7 days')));
$weekA = date('Y-m-d', strtotime($weekB . ' -7 days'));
$day = static fn(string $we, int $back): string => date('Y-m-d', strtotime($we . ' -' . $back . ' days'));

q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status)
   VALUES (?,'P2-M01 project','Synthetic project for pay changes.',20,35,0,0,0,'active')", [(int) $fixture['client']]);
$job = (int) db()->lastInsertId();

$person = static function (string $label, string $type, ?float $salary, ?float $rate) use ($job, $day, $weekA, $admin): array {
    q('INSERT INTO candidates(full_name,email) VALUES (?,?)', ['Comp ' . $label, 'comp-w-' . $label . '@test.invalid']);
    $cid = (int) db()->lastInsertId();
    q("INSERT INTO employee_profiles(candidate_id,employment_type,flsa_status,salary_per_period,adp_employee_id,payment_method) VALUES (?,?,?,?,?,'direct_deposit')",
      [$cid, $type, $type === 'salaried' ? 'exempt' : 'non_exempt', $salary, 'COMP' . strtoupper($label)]);
    q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site',?,?,35)", [$cid, $job, $day($weekA, 6), $rate]);
    $pid = (int) db()->lastInsertId();
    q("INSERT INTO assignment_details(placement_id,trade) VALUES (?,'Welder')", [$pid]);
    foreach ([$weekA, date('Y-m-d', strtotime($weekA . ' +7 days'))] as $we) {
        foreach ([5, 4, 3, 2, 1] as $back) {
            q("INSERT INTO attendance_records(placement_id,work_date,hours,status,submitted_by,reviewed_by,reviewed_at) VALUES (?,?,8,'approved',?,?,NOW())",
              [$pid, $day($we, $back), $admin, $admin]);
        }
    }
    return [$cid, $pid];
};
[$hourly, $pHourly] = $person('hourly', 'hourly', null, 20.0);
[$salaried, $pSalaried] = $person('salaried', 'salaried', 1200.0, null);
q("INSERT INTO personnel_changes(placement_id,kind,previous_trade,new_trade,effective_on,reason,approved_by) VALUES (?,'promotion','Welder','Lead welder',?,'Runs the night crew',?)",
  [$pHourly, $day($weekA, 3), $admin]);

file_put_contents(__DIR__ . '/comp.json', json_encode(['job' => $job, 'week_a' => $weekA, 'week_b' => $weekB, 'monday_b' => $day($weekB, 5), 'tuesday_a' => $day($weekA, 4),
    'hourly' => $hourly, 'p_hourly' => $pHourly, 'salaried' => $salaried, 'p_salaried' => $pSalaried,
    'future' => date('Y-m-d', strtotime('+40 days')), 'today' => date('Y-m-d')]));
file_put_contents(__DIR__ . '/comp-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
