<?php
/**
 * P2-M02 at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, the seeded template, then the
 * people appraisals_http.py is tested on.
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
$all = ['appraisal_templates', 'appraisal_template_criteria', 'appraisals', 'appraisal_scores', 'appraisal_events'];

check('P2-M02 is in place before the rollback', count(array_filter($all, $table)) === 5);
$reviews = (int) val('SELECT COUNT(*) FROM assignment_reviews');
sql_file($app . '/install/rollback/p2-m02.sql');
check('Rollback removes every P2-M02 table', count(array_filter($all, $table)) === 0);
check('Rollback keeps the roster\'s reviews', (int) val('SELECT COUNT(*) FROM assignment_reviews') === $reviews);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds and announces appraisals', $code === 0 && str_contains($first, 'Appraisals: templates, self and supervisor scoring'), $first);
$template = row("SELECT * FROM appraisal_templates WHERE code = 'end_of_assignment'");
$weights = [];
foreach (rows('SELECT criterion_slug, weight FROM appraisal_template_criteria WHERE template_id = ?', [(int) $template['id']]) as $r) {
    $weights[$r['criterion_slug']] = (int) $r['weight'];
}
check('The end-of-assignment template scores the five criteria on 1-5, safety counting double',
      (int) $template['scale_max'] === 5 && count($weights) === 5 && $weights['safety'] === 2 && $weights['workmanship'] === 1, json_encode($weights));
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P2-M02', $code === 0 && ! str_contains($second, 'Appraisals:'), $second);

$fixture  = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$user = static function (string $key, string $role) use ($password): int {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Appraisal ' . $key, 'appr-' . $key . '@test.invalid', $password, $role]);
    return (int) db()->lastInsertId();
};
$ids = [];
foreach (['admin' => 'admin', 'rec1' => 'recruiter', 'rec2' => 'recruiter', 'payroll' => 'payroll', 'sup' => 'supervisor', 'sup2' => 'supervisor', 'worker' => 'worker', 'worker2' => 'worker'] as $k => $role) {
    $ids[$k] = $user($k, $role);
}

q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status)
   VALUES (?,'P2-M02 project','Synthetic project for appraisals.',20,35,0,0,0,'active')", [(int) $fixture['client']]);
$job = (int) db()->lastInsertId();

$person = static function (string $label, ?int $workerUser, ?int $supervisor) use ($job): array {
    q('INSERT INTO candidates(full_name,email) VALUES (?,?)', ['Appraised ' . $label, 'appr-c-' . $label . '@test.invalid']);
    $cid = (int) db()->lastInsertId();
    q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site',DATE_SUB(CURDATE(), INTERVAL 30 DAY),20,35)", [$cid, $job]);
    $pid = (int) db()->lastInsertId();
    if ($supervisor) {
        q("INSERT INTO assignment_details(placement_id,trade,supervisor_id) VALUES (?,'Pipefitter',?)", [$pid, $supervisor]);
    }
    if ($workerUser) {
        q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$workerUser, $cid]);
    }
    return [$cid, $pid];
};
[$candA, $placeA] = $person('alpha', $ids['worker'], $ids['sup']);
[$candB, $placeB] = $person('bravo', null, null);
[$candC, $placeC] = $person('charlie', $ids['worker2'], $ids['sup2']);

file_put_contents(__DIR__ . '/appr.json', json_encode(['job' => $job, 'ids' => $ids, 'template' => (int) $template['id'],
    'cand_a' => $candA, 'place_a' => $placeA, 'cand_b' => $candB, 'place_b' => $placeB, 'cand_c' => $candC, 'place_c' => $placeC]));
file_put_contents(__DIR__ . '/appr-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
