<?php
/**
 * The P5-M28 test's eyes and hands, on the test database only.
 *   notices <user id>     how many notifications they have, and the last one
 *   line <role>           a scope line's id
 *   chain <name>          an approval chain's id
 *   app <id>              an application's stage, its offer validation, the pending request, the note, invitations
 *   newapp <vacancy>      a new applicant who answered their questions, ready to offer (prints the application id)
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/approvals.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
$fx = json_decode((string) file_get_contents(__DIR__ . '/walkthrough.json'), true);
[$cmd, $arg] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($cmd === 'notices') {
    $last = row('SELECT message, target FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [(int) $arg]);
    echo json_encode(['count' => (int) val('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [(int) $arg]), 'last' => $last['message'] ?? '', 'target' => $last['target'] ?? '']);
} elseif ($cmd === 'line') {
    echo json_encode(row('SELECT id FROM job_order_lines WHERE role_title = ? ORDER BY id DESC LIMIT 1', [$arg]));
} elseif ($cmd === 'chain') {
    echo json_encode(row('SELECT id FROM approval_chains WHERE name = ?', [$arg]));
} elseif ($cmd === 'app') {
    $id = (int) $arg;
    echo json_encode(['stage' => val('SELECT stage FROM applications WHERE id = ?', [$id]), 'validation' => approval_state('offer', $id),
        'request' => (int) val("SELECT id FROM approval_requests WHERE subject_type = 'offer' AND subject_id = ? AND status = 'pending' ORDER BY id LIMIT 1", [$id]),
        'offer_note' => val("SELECT note FROM application_events WHERE application_id = ? AND stage = 'offered' ORDER BY id DESC LIMIT 1", [$id]),
        'invited' => (int) val('SELECT COUNT(*) FROM invitations i JOIN applications a ON a.candidate_id = i.candidate_id WHERE a.id = ?', [$id])]);
} elseif ($cmd === 'newapp') {
    q("INSERT INTO candidates(full_name,email,stage) VALUES ('Walk to validate', CONCAT('wk-val-', ?, '@test.invalid'), 'new')", [bin2hex(random_bytes(3))]);
    $c = (int) db()->lastInsertId();
    q("INSERT INTO applications(candidate_id,vacancy_id,stage) VALUES (?,?,'interview')", [$c, (int) $arg]);
    $a = (int) db()->lastInsertId();
    q('INSERT INTO screening_answers(application_id,question_id,answer) VALUES (?,?,?),(?,?,?)', [$a, (int) $fx['qAll'], 'No', $a, (int) $fx['qNum'], '6']);
    echo json_encode($a);
}
