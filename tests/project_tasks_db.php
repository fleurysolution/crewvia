<?php
/**
 * P5-M01 at the database level, on the isolated test database only:
 * rollback and upgrade, silence on a repeat, then the fixture for
 * project_tasks_http.py: two projects, desks, a supervisor with a crew on
 * the first and one with none, a worker placed on each project, a
 * milestone on the second project.
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
$tables = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('project_milestones','project_tasks','project_task_events')");
$app = __DIR__ . '/test-app';

check('P5-M01 is in place before the rollback', $tables() === 3);
$jobs = (int) val('SELECT COUNT(*) FROM jobs');
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m01.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes milestones, tasks and their history, and keeps every project', $tables() === 0 && (int) val('SELECT COUNT(*) FROM jobs') === $jobs);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces project tasks, and that none exists yet', $code === 0 && str_contains($first, 'Project tasks: milestones, tasks') && str_contains($first, 'no task exists yet'), $first);
check('The three tables are back', $tables() === 3);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M01', $code === 0 && ! str_contains($second, 'Project tasks:'), $second);
try {
    q("INSERT INTO project_tasks (job_id, title, status) VALUES (?, 'Bad status', 'finished')", [(int) val('SELECT MIN(id) FROM jobs')]);
    $refused = false;
} catch (Throwable $e) {
    $refused = true;
}
check('The database refuses a status that does not exist', $refused);

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'rec' => 'recruiter', 'pay' => 'payroll', 'hotels' => 'hotels', 'sup' => 'supervisor', 'sup2' => 'supervisor', 'worker' => 'worker', 'workerb' => 'worker', 'inactive' => 'recruiter'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw,is_active) VALUES (?,?,?,?,0,?)', ['Tasks ' . $k, 'pt-' . $k . '@test.invalid', $password, $role, $k === 'inactive' ? 0 : 1]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO clients(name) VALUES ('Tasks client')");
$client = (int) db()->lastInsertId();
$job = static function (string $title) use ($client): int {
    q("INSERT INTO jobs(client_id,title,status) VALUES (?,?,'active')", [$client, $title]);
    return (int) db()->lastInsertId();
};
$jobA = $job('P5-M01 refinery turnaround');
$jobB = $job('P5-M01 other site');
$placed = static function (string $name, int $jobId, int $userId): int {
    q("INSERT INTO candidates(full_name,email,stage) VALUES (?,?,'placed')", [$name, strtolower(str_replace(' ', '.', $name)) . '@test.invalid']);
    $cid = (int) db()->lastInsertId();
    q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site',CURDATE(),30,50)", [$cid, $jobId]);
    $pid = (int) db()->lastInsertId();
    q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$userId, $cid]);
    return $pid;
};
$plA = $placed('Tasks Crew Alpha', $jobA, $ids['worker']);
$placed('Tasks Crew Bravo', $jobB, $ids['workerb']);
q("INSERT INTO assignment_details(placement_id,trade,supervisor_id) VALUES (?,'Pipefitter',?)", [$plA, $ids['sup']]);
q("INSERT INTO project_milestones(job_id,title,created_by) VALUES (?,'Other site milestone',?)", [$jobB, $ids['admin']]);
$milestoneB = (int) db()->lastInsertId();

file_put_contents(__DIR__ . '/project-tasks.json', json_encode(['ids' => $ids, 'job_a' => $jobA, 'job_b' => $jobB, 'milestone_b' => $milestoneB]));
file_put_contents(__DIR__ . '/pt-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
