<?php
/**
 * Feedback (P5-M05): given to a colleague, or asked of colleagues about
 * yourself (or, for the HR desk and a worker's supervisor, about that
 * person). Feedback not shared is read only by the HR desk, a worker's
 * supervisor and whoever asked for it, never by the person it is about.
 * It is not a performance review and not a disciplinary case.
 */

declare(strict_types=1);

require_once __DIR__ . '/../engagement.php';

if (! eng_person(uid())) {
    refuse(403, t('Not your desk'));
}
$me = uid();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $back = '/feedback';
    switch ($do) {
        case 'give':
            [$why] = fb_give($me, (int) ($_POST['about_user_id'] ?? 0), (string) ($_POST['kind'] ?? ''), (string) ($_POST['body'] ?? ''),
                             ! empty($_POST['is_shared']), (int) ($_POST['request_id'] ?? 0));
            if ($why === null) { flash(t('Feedback given.')); }
            break;
        case 'ask':
            $about = (int) ($_POST['about_user_id'] ?? 0) ?: $me;
            [$why, $rid] = fb_request($me, $about, (array) ($_POST['user_ids'] ?? []), (string) ($_POST['question'] ?? ''), trim((string) ($_POST['due_on'] ?? '')));
            if ($why === null) { flash(t('Asked.')); $back = '/feedback?request=' . $rid; }
            break;
        default:
            refuse(400, t('Unknown action.'));
    }
    if ($why !== null) { refuse(422, $why); }
    redirect($back);
}

$colleagues = eng_colleagues($me);
$answer = $request = $about = null;
$entries = [];

if (isset($_GET['answer'])) {
    $answer = row('SELECT r.*, p.answered_at, u.name AS about_name, a.name AS asked_name FROM feedback_requests r JOIN feedback_request_people p ON p.request_id = r.id AND p.user_id = ?
                   JOIN users u ON u.id = r.about_user_id JOIN users a ON a.id = r.asked_by WHERE r.id = ?', [$me, (int) $_GET['answer']]);
    if (! $answer) { refuse(404, t('That request was not sent to you.')); }
} elseif (isset($_GET['request'])) {
    $request = row('SELECT r.*, u.name AS about_name FROM feedback_requests r JOIN users u ON u.id = r.about_user_id WHERE r.id = ?', [(int) $_GET['request']]);
    if (! $request || ((int) $request['asked_by'] !== $me && ! fb_may_read_all((int) $request['about_user_id']))) { refuse(404, t('That request does not exist.')); }
    $request['people'] = rows('SELECT p.*, u.name FROM feedback_request_people p JOIN users u ON u.id = p.user_id WHERE p.request_id = ? ORDER BY u.name', [(int) $request['id']]);
    $entries = fb_entries('e.request_id = ?', [(int) $request['id']], $me);
} elseif (isset($_GET['about'])) {
    $about = eng_person((int) $_GET['about']);
    if (! $about || (int) $about['id'] === $me || ! fb_may_read_all((int) $about['id'])) { refuse(403, t('You read feedback about yourself, or about someone you supervise.')); }
    log_activity('read feedback about a person', 'user', (int) $about['id']);
    $entries = fb_entries('e.about_user_id = ?', [(int) $about['id']], $me);
}

$received = fb_entries('e.about_user_id = ? AND e.is_shared = 1', [$me], $me);
$given = fb_entries('e.from_user_id = ?', [$me], $me);
$toAnswer = rows('SELECT r.*, u.name AS about_name, a.name AS asked_name FROM feedback_request_people p JOIN feedback_requests r ON r.id = p.request_id
                  JOIN users u ON u.id = r.about_user_id JOIN users a ON a.id = r.asked_by WHERE p.user_id = ? AND p.answered_at IS NULL ORDER BY r.id DESC', [$me]);
$myRequests = rows('SELECT r.*, u.name AS about_name, (SELECT COUNT(*) FROM feedback_request_people p WHERE p.request_id = r.id) AS asked,
                           (SELECT COUNT(*) FROM feedback_request_people p WHERE p.request_id = r.id AND p.answered_at IS NOT NULL) AS answered
                    FROM feedback_requests r JOIN users u ON u.id = r.about_user_id WHERE r.asked_by = ? ORDER BY r.id DESC LIMIT 50', [$me]);
// Whom the HR desk or a supervisor may ask about: anyone for the desk, their crew for a supervisor.
$subjects = [];
foreach (eng_manager() ? rows("SELECT id, name FROM users WHERE is_active = 1 AND role <> 'client' AND id <> ? ORDER BY name", [$me]) : array_map(fn($id, $n) => ['id' => $id, 'name' => $n], array_keys($colleagues), $colleagues) as $s) {
    if (eng_manager() || fb_may_read_all((int) $s['id'])) {
        $subjects[(int) $s['id']] = (string) $s['name'];
    }
}
$askable = eng_manager() ? array_column(rows("SELECT id, name FROM users WHERE is_active = 1 AND role <> 'client' AND id <> ? ORDER BY name", [$me]), 'name', 'id') : $colleagues;

render('feedback', compact('colleagues', 'answer', 'request', 'about', 'entries', 'received', 'given', 'toAnswer', 'myRequests', 'subjects', 'askable'));
