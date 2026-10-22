<?php
/**
 * P5-M104: the emails every applicant receives - the agency reads, edits, previews and switches each one off here,
 * and chooses their language. What was sent to whom is on each applicant's record.
 */

declare(strict_types=1);

require_role('admin');
require_once __DIR__ . '/../candidate-emails.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'locale') {
        $l = in_array($_POST['locale'] ?? '', ['en', 'fr', 'es'], true) ? (string) $_POST['locale'] : 'en';
        q("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('candidate_email_locale', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$l]);
        log_activity('chose the language of the applicant emails', 'settings', 0, $l);
        flash(t('Applicant emails are now written in this language.'));
        redirect('/candidate-emails');
    }
    // P5-M104b: a held burst of refusals - sent (each 24 hours after its rejection) or never sent.
    if ($do === 'held_send' || $do === 'held_cancel') {
        $n = ce_release_held($do === 'held_send');
        log_activity($do === 'held_send' ? 'released held applicant refusals' : 'cancelled held applicant refusals', 'settings', 0, (string) $n);
        flash($do === 'held_send'
            ? tn($n, ':n refusal will be sent, 24 hours after its rejection.', ':n refusals will be sent, each 24 hours after its rejection.')
            : tn($n, ':n refusal will not be sent.', ':n refusals will not be sent.'));
        redirect('/candidate-emails');
    }
    $key = (string) ($_POST['template_key'] ?? '');
    if (! in_array($key, CE_KEYS, true)) {
        refuse(422, t('Choose an email.'), '/candidate-emails');
    }
    $locale = ce_locale();
    if ($do === 'save') {
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($subject === '' || $body === '' || mb_strlen($subject) > 255 || mb_strlen($body) > 5000) {
            refuse(422, t('Write a subject and a message.'), '/candidate-emails#ce-' . $key);
        }
        q('INSERT INTO candidate_email_templates (template_key, locale, subject, body, updated_by) VALUES (?,?,?,?,?)
           ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), updated_by = VALUES(updated_by)', [$key, $locale, $subject, $body, uid()]);
        q("INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
          ['candidate_email_off_' . $key, isset($_POST['enabled']) ? '0' : '1']);
        log_activity('edited an applicant email', 'settings', 0, $key . ' (' . $locale . ')');
        flash(t('Saved. The next applicants receive this text.'));
    } elseif ($do === 'reset') {
        q('DELETE FROM candidate_email_templates WHERE template_key = ? AND locale = ?', [$key, $locale]);
        log_activity('reset an applicant email to the default text', 'settings', 0, $key . ' (' . $locale . ')');
        flash(t('Back to the default text.'));
    }
    redirect('/candidate-emails#ce-' . $key);
}

$locale = ce_locale();
$sample = ['first_name' => 'Maria', 'job_title' => t('Industrial Maintenance Tech'), 'agency' => agency_name()];
$templates = [];
foreach (CE_KEYS as $key) {
    $t = ce_template($key, $locale);
    $templates[$key] = $t + [
        'preview_subject' => ce_render($t['subject'], $sample),
        'preview_body'    => ce_render($t['body'], $sample),
        'sent_30d'        => (int) val("SELECT COUNT(*) FROM candidate_email_log WHERE template_key = ? AND status = 'sent' AND sent_at >= NOW() - INTERVAL 30 DAY", [$key]),
        'waiting'         => (int) val("SELECT COUNT(*) FROM candidate_email_log WHERE template_key = ? AND status = 'scheduled'", [$key]),
    ];
}
require_once __DIR__ . '/../mail-routing.php';
$testMode = mail_test_mode();

$pageTitle = t('Emails to applicants') . ' · ' . $config['app_name'];
$held = ce_held_count();
render('candidate-emails', compact('locale', 'templates', 'testMode', 'held'));
