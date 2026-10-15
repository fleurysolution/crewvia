<?php
/**
 * The P5-M43 test's eyes, on the test database only.
 *   questions <vacancy>        the questions that apply to a requisition
 *   answer <email> <question>  an applicant's answer to one question, and whether it came from a board
 *   score <email>              an applicant's screening score
 *   application <email>        their latest application id
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
[$cmd, $arg, $extra] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
$app = static fn(string $email): int => (int) val('SELECT a.id FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE c.email = ? ORDER BY a.id DESC LIMIT 1', [$email]);
if ($cmd === 'questions') {
    echo json_encode(rows('SELECT q.id, q.question, q.answer_type, q.choices, q.weight, q.origin, q.full_marks_at, q.choice_points FROM screening_questions q JOIN vacancies v ON v.id = ? WHERE q.job_id = v.job_id AND (q.vacancy_id IS NULL OR q.vacancy_id = v.id)', [(int) $arg]));
} elseif ($cmd === 'answer') {
    $r = row('SELECT answer, from_board FROM screening_answers WHERE application_id = ? AND question_id = ?', [$app($arg), (int) $extra]);
    echo json_encode($r ? ['answer' => $r['answer'], 'from_board' => (int) $r['from_board']] : null);
} elseif ($cmd === 'score') {
    $s = val('SELECT screening_score FROM applications WHERE id = ?', [$app($arg)]);
    echo json_encode($s === null ? null : (float) $s);
} elseif ($cmd === 'application') {
    echo json_encode($app($arg));
}
