<?php
/**
 * The P5-M13 test's eyes and hands, on the test database only.
 *   read                     the fixture's applications, their events and answers
 *   stage <application> <s>  moves an application, as a recruiter would
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

if (($argv[1] ?? '') === 'stage') {
    q('UPDATE applications SET stage = ? WHERE id = ?', [(string) $argv[3], (int) $argv[2]]);
    exit(0);
}
$fx = json_decode((string) file_get_contents(__DIR__ . '/my_jobs.json'), true);
$cands = implode(',', array_map('intval', $fx['cands']));
echo json_encode([
    'applications' => rows("SELECT id, candidate_id, vacancy_id, stage, source, screening_score FROM applications WHERE candidate_id IN ($cands) ORDER BY id"),
    'events' => rows("SELECT e.application_id, e.user_id, e.stage, e.note FROM application_events e JOIN applications a ON a.id = e.application_id WHERE a.candidate_id IN ($cands) ORDER BY e.id"),
    'answers' => rows("SELECT s.application_id, s.question_id, s.answer FROM screening_answers s JOIN applications a ON a.id = s.application_id WHERE a.candidate_id IN ($cands) ORDER BY s.question_id"),
]);
