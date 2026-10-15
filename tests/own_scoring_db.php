<?php
/**
 * P5-M43 at the database level, on the isolated test database only, after
 * own_scoring_http.py: how an answer earns its weight under Crewvia's rules,
 * that a board applicant is scored only on what the board asked, then the
 * rollback (board answers and board questions gone) and the upgrade (the
 * answers recorded again from the note the import left), once.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/screening.php';

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
$app = __DIR__ . '/test-app';

$years = ['answer_type' => 'number', 'knockout_answer' => '<2', 'full_marks_at' => '10', 'choice_points' => null];
check('Full marks at 10 years: 10 or more earn it all, 4 earn 40%, under the minimum nothing',
      screening_answer_share($years, '12') === 1.0 && screening_answer_share($years, '4') === 0.4 && screening_answer_share($years, '1') === 0.0 && screening_answer_share($years, 'many') === 0.0);
$travel = ['answer_type' => 'choice', 'knockout_answer' => null, 'full_marks_at' => null, 'choice_points' => "100% = 100\n50% = 40\n25% = 0"];
check('Points per answer: 100% earns it all, 50% earns 40%, an answer not listed earns nothing',
      screening_answer_share($travel, '100%') === 1.0 && screening_answer_share($travel, '50%') === 0.4 && screening_answer_share($travel, '75%') === 0.0);
check('Without a graded rule, any answer that is not the knockout earns the whole weight, as before',
      screening_answer_share(['answer_type' => 'yes_no', 'knockout_answer' => 'No'], 'Yes') === 1.0 && screening_answer_share(['answer_type' => 'yes_no', 'knockout_answer' => 'No'], 'No') === 0.0);
check('A rule form with points above 100 or a full mark of 0 is refused',
      screening_rules_from_post('choice', ['choice_points' => '100% = 140'])[2] !== null && screening_rules_from_post('number', ['full_marks_at' => '0'])[2] !== null
      && screening_rules_from_post('number', ['full_marks_at' => '5'])[0] === 5.0);

$wk = json_decode((string) file_get_contents(__DIR__ . '/walkthrough.json'), true);
$one = (int) val("SELECT a.id FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE c.email = 'in-one@test.invalid' ORDER BY a.id DESC LIMIT 1");
$office = (int) val("SELECT COUNT(*) FROM screening_questions WHERE job_id = ? AND origin = 'office' AND weight > 0", [$wk['job']]);
check('A board applicant is scored only on the board\'s questions, not on the office\'s own they were never asked',
      $office > 0 && (float) val('SELECT screening_score FROM applications WHERE id = ?', [$one]) === 70.0, (string) $office);

$column = static fn(string $t, string $c): int => (int) val('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m43.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes the board answers, the board questions and the graded rules; the office\'s questions stay',
      ! $column('screening_answers', 'from_board') && ! $column('screening_questions', 'full_marks_at') && ! $column('screening_questions', 'origin')
      && ! val('SELECT COUNT(*) FROM screening_answers WHERE application_id = ?', [$one]) && (int) val('SELECT COUNT(*) FROM screening_questions WHERE job_id = ? AND weight > 0', [$wk['job']]) === $office);

[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade brings the rules back and records the board answers again from the note the import left',
      $code === 0 && $column('screening_answers', 'from_board') === 1 && str_contains($first, 'Job board answers:') && str_contains($first, 'Screening: a number question can give full marks')
      && (int) val('SELECT COUNT(*) FROM screening_answers WHERE application_id = ? AND from_board = 1', [$one]) === 2, $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M43', $code === 0 && ! str_contains($second, 'Job board answers:') && ! str_contains($second, 'Screening: a number question'), $second);

file_put_contents(__DIR__ . '/own-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
