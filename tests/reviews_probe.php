<?php
/**
 * The P5-M44 test's eyes, on the test database only.
 *   app <email>           the latest application of an applicant: id, stage, vacancy
 *   review <app>          the latest review of an application
 *   interview <app>       the latest interview of an application
 *   notes <email>         unread notifications of a user
 *   doc <email>           the id of an applicant's first document
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
[$cmd, $arg] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($cmd === 'app') {
    echo json_encode(row('SELECT a.id, a.stage, a.vacancy_id FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE c.email = ? ORDER BY a.id DESC LIMIT 1', [$arg]) ?: null);
} elseif ($cmd === 'review') {
    echo json_encode(row('SELECT id, status, rating, reviewer_id, decision_note FROM application_reviews WHERE application_id = ? ORDER BY id DESC LIMIT 1', [(int) $arg]) ?: null);
} elseif ($cmd === 'interview') {
    echo json_encode(row('SELECT id, status, rating, verdict, interviewer_id, mode FROM interviews WHERE application_id = ? ORDER BY id DESC LIMIT 1', [(int) $arg]) ?: null);
} elseif ($cmd === 'notes') {
    echo json_encode(array_column(rows('SELECT n.message FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.email = ? AND n.read_at IS NULL ORDER BY n.id', [$arg]), 'message'));
} elseif ($cmd === 'doc') {
    echo json_encode((int) val('SELECT r.id FROM candidate_resumes r JOIN candidates c ON c.id = r.candidate_id WHERE c.email = ? ORDER BY r.id LIMIT 1', [$arg]));
}
