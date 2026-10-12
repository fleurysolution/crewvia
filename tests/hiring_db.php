<?php
/**
 * P5-M10 at the database level, on the isolated test database only:
 * rollback (applications kept, only the interest columns go) and upgrade,
 * silence on a repeat, then the fixture for hiring_http.py: a recruiter,
 * an administrator, Logistics; a project with one required screening
 * question, an open chemical requisition and a closed one; applicants at
 * several stages; people in the talent pool, one of them not to be rehired
 * and one in another discipline.
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
$columns = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'applications' AND column_name IN ('interest','interest_by','interest_at')");
$app = __DIR__ . '/test-app';

check('P5-M10 is in place before the rollback', $columns() === 3);
$applications = (int) val('SELECT COUNT(*) FROM applications');
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m10.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes the interest columns and keeps every application', $columns() === 0 && (int) val('SELECT COUNT(*) FROM applications') === $applications);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces the Hiring workspace', $code === 0 && str_contains($first, 'Hiring: one workspace per requisition') && str_contains($first, 'open requisition(s).'), $first);
check('The columns are back', $columns() === 3);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M10', $code === 0 && ! str_contains($second, 'Hiring:'), $second);

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['rec' => 'recruiter', 'admin' => 'admin', 'hotels' => 'hotels'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Hiring ' . $k, 'hw-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO clients(name) VALUES ('Hiring client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'P5-M10 turnaround','active')", [$client]);
$job = (int) db()->lastInsertId();
q("INSERT INTO screening_questions(job_id,question,required) VALUES (?,'Willing to travel?',1)", [$job]);
$question = (int) db()->lastInsertId();
q("INSERT INTO vacancies(job_id,title,description,is_open,openings,discipline) VALUES (?,'P5-M10 process engineer','Synthetic requisition.',1,3,'chemical')", [$job]);
$open = (int) db()->lastInsertId();
q("INSERT INTO vacancies(job_id,title,description,is_open,openings,discipline) VALUES (?,'P5-M10 closed requisition','Synthetic requisition.',0,1,'chemical')", [$job]);
$closed = (int) db()->lastInsertId();

$cands = [];
foreach (['ann' => ['Ann Applicant', 'chemical'], 'ben' => ['Ben Applicant', 'chemical'], 'cara' => ['Cara Applicant', 'chemical'], 'dee' => ['Dee Applicant', 'chemical'],
          'pool' => ['Pat Poolmatch', 'chemical'], 'veteran' => ['Val Veteran', 'chemical'], 'banned' => ['Bo Norehire', 'chemical'], 'mech' => ['Mo Mechanical', 'mechanical']] as $k => [$name, $discipline]) {
    q("INSERT INTO candidates(full_name,email,stage,discipline,city,state,degree,years_exp) VALUES (?,?,'contacted',?,'Katy','TX','BSc',5)", [$name, 'hw-' . $k . '@test.invalid', $discipline]);
    $cands[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO employee_profiles(candidate_id,rehire_status) VALUES (?,'ineligible')", [$cands['banned']]);
q("INSERT INTO placements(candidate_id,job_id,status) VALUES (?,?,'completed')", [$cands['veteran'], $job]);

$apps = [];
foreach (['ann' => ['new', 80, 2], 'ben' => ['screening', 60, 5], 'cara' => ['accepted', 90, 9], 'dee' => ['new', 40, 1]] as $k => [$stage, $score, $hours]) {
    q('INSERT INTO applications(candidate_id,vacancy_id,stage,source,screening_score,created_at) VALUES (?,?,?,?,?,DATE_SUB(NOW(), INTERVAL ? HOUR))', [$cands[$k], $open, $stage, 'Indeed', $score, $hours]);
    $apps[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO screening_answers(application_id,question_id,answer,recorded_by) VALUES (?,?,'Yes',?)", [$apps['ben'], $question, $ids['rec']]);
q("INSERT INTO screening_checks(application_id,title,status) VALUES (?,'Reference check','pending')", [$apps['ben']]);

file_put_contents(__DIR__ . '/hiring.json', json_encode(['ids' => $ids, 'cands' => $cands, 'apps' => $apps, 'open' => $open, 'closed' => $closed, 'job' => $job, 'question' => $question]));
file_put_contents(__DIR__ . '/hw-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
