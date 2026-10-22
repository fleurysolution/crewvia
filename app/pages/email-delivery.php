<?php
/**
 * Email delivery: the invitation queue, and (P5-M47) where candidates'
 * emails go while the agency tests its flows. See app/mail-routing.php.
 */
require_role('admin');
require_once __DIR__ . '/../mail-routing.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
render('email-delivery', compact('messages', 'routing', 'staffMail'));
