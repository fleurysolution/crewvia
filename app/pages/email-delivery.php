<?php
/**
 * Email delivery: the invitation queue, and (P5-M47) where candidates'
 * emails go while the agency tests its flows. See app/mail-routing.php.
 */
require_role('admin');
require_once __DIR__ . '/../mail-routing.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // P5-M103b: send the queued emails now, without waiting for the delivery job (a demonstration, a check).
    if (($_POST['do'] ?? '') === 'send_now') {
        require_once __DIR__ . '/../notices.php';
        require_once __DIR__ . '/../candidate-emails.php';
        ce_due();
        $run = mail_deliver(100);
        log_activity('sent the queued emails now', 'settings', 0, $run['sent'] . ' sent, ' . $run['failed'] . ' failed');
        if ($run['held'] !== null) {
            flash(t('Nothing was sent: email is not switched on for this installation.'), 'err');
        } else {
            flash(t(':sent sent, :failed failed.', ['sent' => $run['sent'], 'failed' => $run['failed']]), $run['failed'] ? 'err' : 'ok');
        }
        redirect('/email-delivery#staff-mail');
    }
    if (($_POST['do'] ?? '') === 'routing') {
        $pattern = trim((string) ($_POST['test_to'] ?? ''));
        $reply = trim((string) ($_POST['reply_to'] ?? ''));
        if (! str_contains($pattern, '{first}') || ! filter_var(str_replace('{first}', 'name', $pattern), FILTER_VALIDATE_EMAIL)) {
            refuse(422, t('The test inbox is an email address with {first} where the first name goes, such as solutionsfleury+{first}@gmail.com.'), '/email-delivery');
        }
        if ($reply !== '' && ! filter_var($reply, FILTER_VALIDATE_EMAIL)) {
            refuse(422, t('The reply address is not a valid email address.'), '/email-delivery');
        }
        $test = isset($_POST['test_mode']) ? '1' : '0';
        foreach (['candidate_mail_test' => $test, 'candidate_mail_test_to' => $pattern, 'candidate_mail_reply_to' => $reply] as $k => $v) {
            q('INSERT INTO platform_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$k, $v]);
        }
        log_activity($test === '1' ? 'kept candidate emails in test mode' : 'sent candidate emails to the candidates themselves', 'settings', 0, $pattern . ' · reply to ' . ($reply ?: 'nobody'));
        flash($test === '1' ? t('Test mode on: candidates\' emails go to the test inbox.') : t('Test mode off: candidates now receive their emails.'));
        redirect('/email-delivery');
    }
    q("UPDATE email_outbox o JOIN invitations i ON i.id=o.invitation_id SET o.status='pending' WHERE o.id=? AND o.status='failed' AND o.attempts<5 AND i.expires_at>NOW() AND i.used_at IS NULL", [(int) ($_POST['id'] ?? 0)]);
    redirect('/email-delivery');
}
$messages = rows('SELECT id,recipient,status,attempts,created_at,sent_at FROM email_outbox ORDER BY id DESC LIMIT 100');
foreach ($messages as &$message) {
    $message['route'] = mail_route((string) $message['recipient']);
}
unset($message);
$routing = ['test' => mail_test_mode(), 'test_to' => mail_test_pattern(), 'reply_to' => mail_setting('candidate_mail_reply_to', '')];
// P5-M103: the emails to office staff - a requisition sent to recruiting or published, an applicant to review, a decision -
// with when each left: the proof it went, not the promise it will.
$staffMail = rows("SELECT m.id, m.recipient, m.purpose, m.status, m.attempts, m.created_at, m.sent_at, v.title AS requisition
                   FROM mail_outbox m LEFT JOIN vacancies v ON v.id = CAST(SUBSTRING_INDEX(m.purpose, ':', -1) AS UNSIGNED) AND SUBSTRING_INDEX(m.purpose, ':', 1) IN ('requisition','published')
                   WHERE SUBSTRING_INDEX(m.purpose, ':', 1) IN ('requisition','published','review','decision') ORDER BY m.id DESC LIMIT 60");
// P5-M103b: a queue that stops looks like an empty one. The oldest email still waiting, and for how long, says which.
$waiting = row("SELECT COUNT(*) AS n, MIN(created_at) AS oldest, TIMESTAMPDIFF(MINUTE, MIN(created_at), NOW()) AS minutes
                FROM mail_outbox WHERE status IN ('pending','sending') AND attempts < 5") ?: ['n' => 0, 'oldest' => null, 'minutes' => null];
// P5-M104b: the refusals not yet in the queue - waiting their 24 hours, or held as a burst.
require_once __DIR__ . '/../candidate-emails.php';
$refusals = ['scheduled' => (int) val("SELECT COUNT(*) FROM candidate_email_log WHERE template_key = 'not_retained' AND status = 'scheduled'"), 'held' => ce_held_count()];
render('email-delivery', compact('messages', 'routing', 'staffMail', 'waiting', 'refusals'));
