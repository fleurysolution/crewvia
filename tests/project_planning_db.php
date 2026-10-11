<?php
/**
 * P5-M02 at the database level, on the isolated test database only:
 * rollback (P5-M01's tasks kept) and upgrade, silence on a repeat, then the
 * fixture for project_planning_http.py: two projects, desks, a supervisor
 * with a crew, a worker on the crew with two approved days off next week.
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
$tables = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_sprints','project_task_dependencies','user_capacity')");
$columns = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'project_tasks' AND column_name IN ('parent_id','estimate_hours','sprint_id')");
$app = __DIR__ . '/test-app';

check('P5-M02 is in place before the rollback', $tables() === 3 && $columns() === 3);
$tasks = (int) val('SELECT COUNT(*) FROM project_tasks');
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m02.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes sprints, dependencies, capacities and the three task columns, and keeps every task', $tables() === 0 && $columns() === 0 && (int) val('SELECT COUNT(*) FROM project_tasks') === $tasks);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces project planning, and that existing tasks stay as they were', $code === 0 && str_contains($first, 'Project planning: a board per project') && str_contains($first, 'Every existing task stays as it was'), $first);
check('The tables and the task columns are back', $tables() === 3 && $columns() === 3);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M02', $code === 0 && ! str_contains($second, 'Project planning:'), $second);

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['rec' => 'recruiter', 'rec2' => 'recruiter', 'pay' => 'payroll', 'sup' => 'supervisor', 'worker' => 'worker'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Planning ' . $k, 'pp-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO clients(name) VALUES ('Planning client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'P5-M02 boiler outage','active')", [$client]);
$jobA = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'P5-M02 other site','active')", [$client]);
$jobB = (int) db()->lastInsertId();
q("INSERT INTO candidates(full_name,email,stage) VALUES ('Planning Crew','planning.crew@test.invalid','placed')");
$cid = (int) db()->lastInsertId();
q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site',CURDATE(),30,50)", [$cid, $jobA]);
$pl = (int) db()->lastInsertId();
q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$ids['worker'], $cid]);
q("INSERT INTO assignment_details(placement_id,trade,supervisor_id) VALUES (?,'Boilermaker',?)", [$pl, $ids['sup']]);
// Monday and Tuesday of next week off, approved.
$nextMonday = date('Y-m-d', strtotime('monday next week'));
q("INSERT INTO time_off_requests(placement_id,user_id,starts_on,ends_on,request_type,status) VALUES (?,?,?,?,'vacation','approved')",
  [$pl, $ids['worker'], $nextMonday, date('Y-m-d', strtotime($nextMonday . ' +1 day'))]);
// A refused request in the same week counts for nothing.
q("INSERT INTO time_off_requests(placement_id,user_id,starts_on,ends_on,request_type,status) VALUES (?,?,?,?,'vacation','rejected')",
  [$pl, $ids['worker'], date('Y-m-d', strtotime($nextMonday . ' +2 days')), date('Y-m-d', strtotime($nextMonday . ' +4 days'))]);
q("INSERT INTO project_tasks(job_id,title,created_by) VALUES (?,'Task on the other site',?)", [$jobB, $ids['rec']]);
$otherTask = (int) db()->lastInsertId();

file_put_contents(__DIR__ . '/project-planning.json', json_encode(['ids' => $ids, 'job_a' => $jobA, 'job_b' => $jobB, 'other_task' => $otherTask, 'next_monday' => $nextMonday]));
file_put_contents(__DIR__ . '/pp-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
