<?php
/**
 * P5-M04 at the database level, on the isolated test database only:
 * rollback and upgrade, silence on a repeat, then the fixture for
 * training_http.py: desks, two projects, workers of two trades, and a
 * worker who already holds the certificate a course awards, for longer.
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
$all = ['training_courses', 'training_questions', 'training_plans', 'training_plan_courses', 'training_requirements', 'training_assignments', 'training_attempts'];
$tables = static fn(): int => (int) val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN (' . implode(',', array_fill(0, count($all), '?')) . ')', $all);
$app = __DIR__ . '/test-app';

check('P5-M04 is in place before the rollback', $tables() === 7);
$quals = (int) val('SELECT COUNT(*) FROM candidate_qualifications');
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m04.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes the seven tables and keeps every qualification', $tables() === 0 && (int) val('SELECT COUNT(*) FROM candidate_qualifications') === $quals);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces training, and that project briefings stay where they are', $code === 0 && str_contains($first, 'Training: agency courses with assessments') && str_contains($first, 'Project briefings stay'), $first);
check('The tables are back', $tables() === 7);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M04', $code === 0 && ! str_contains($second, 'Training:'), $second);

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['rec' => 'recruiter', 'pay' => 'payroll', 'pay2' => 'payroll', 'hotels' => 'hotels', 'worker' => 'worker', 'worker2' => 'worker', 'worker3' => 'worker', 'workerb' => 'worker'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Training ' . $k, 'tr-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO clients(name) VALUES ('Training client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'P5-M04 tank farm','active')", [$client]);
$jobA = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'P5-M04 pier','active')", [$client]);
$jobB = (int) db()->lastInsertId();
$cands = [];
$worker = static function (string $key, string $name, int $job, string $trade, string $status = 'on_site') use ($ids, &$cands): void {
    q("INSERT INTO candidates(full_name,email,stage) VALUES (?,?,'placed')", [$name, strtolower(str_replace(' ', '.', $name)) . '@test.invalid']);
    $cid = (int) db()->lastInsertId();
    q('INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,?,CURDATE(),30,50)', [$cid, $job, $status]);
    q('INSERT INTO assignment_details(placement_id,trade) VALUES (?,?)', [(int) db()->lastInsertId(), $trade]);
    q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$ids[$key], $cid]);
    $cands[$key] = $cid;
};
$worker('worker', 'Training Elec One', $jobA, 'Electrician');
$worker('worker2', 'Training Welder', $jobA, 'Welder');
$worker('worker3', 'Training Elec Two', $jobA, 'Electrician');
$worker('workerb', 'Training Pier Hand', $jobB, 'Rigger');
q("INSERT INTO qualification_types(name, expires_required) VALUES ('P5 Confined space entry', 1)");
$type = (int) db()->lastInsertId();
// The welder already holds it, verified, until 2035: a course passed later never shortens that.
q("INSERT INTO candidate_qualifications(candidate_id,type_id,detail,issued_on,expires_on,evidence_reference,status,reviewed_at) VALUES (?,?,'Card','2025-01-01','2035-01-01','CARD-1','verified',NOW())",
  [$cands['worker2'], $type]);

file_put_contents(__DIR__ . '/training.json', json_encode(['ids' => $ids, 'cands' => $cands, 'job_a' => $jobA, 'job_b' => $jobB, 'type' => $type]));
file_put_contents(__DIR__ . '/tr-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
