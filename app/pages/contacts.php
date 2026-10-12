<?php
/**
 * /contacts (P5-M07): what the text panel records.
 *   do=texted       a text was opened on the phone; logged in the contact
 *                   register. From a placement: recruiters, logistics and
 *                   payroll, for that placement's person. From a person's
 *                   page: recruiters.
 *   do=preferences  a recruiter records that the person asked not to be
 *                   texted or called, or that they may be again.
 */

declare(strict_types=1);

require_once __DIR__ . '/../messaging.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST required.');
}

$do = (string) ($_POST['do'] ?? '');
$cid = (int) ($_POST['candidate_id'] ?? 0);
$pid = (int) ($_POST['placement_id'] ?? 0);

if ($do === 'texted') {
    if ($pid) {
        require_role('recruiter', 'hotels', 'payroll');
        if (! row('SELECT id FROM placements WHERE id = ? AND candidate_id = ?', [$pid, $cid])) { refuse(404, t('That placement no longer exists.')); }
    } else {
        require_role('recruiter');
    }
    $why = msg_log_text($cid, (int) ($_POST['template_id'] ?? 0), (string) ($_POST['text'] ?? ''), uid());
    if ($why !== null) { refuse(422, $why); }
    http_response_code(204);
    exit;
}

// P5-M08: a text sent, or a call placed, through Crewvia's Twilio connector.
if ($do === 'send_sms' || $do === 'call_twilio') {
    if ($pid) {
        require_role('recruiter', 'hotels', 'payroll');
        if (! row('SELECT id FROM placements WHERE id = ? AND candidate_id = ?', [$pid, $cid])) { refuse(404, t('That placement no longer exists.')); }
    } else {
        require_role('recruiter');
    }
    require_once __DIR__ . '/../twilio.php';
    [$why, $what] = $do === 'send_sms'
        ? tw_send_text($cid, (int) ($_POST['template'] ?? 0), (string) ($_POST['text'] ?? ''), uid())
        : tw_call($cid, uid());
    $back = $pid ? '/placements/' . $pid : '/candidates/' . $cid . '#contact';
    if ($why !== null) { refuse(422, $why, $back); }
    flash(match ($what) {
        'sandbox' => t('Sandbox: recorded here, nothing was sent or dialled.'),
        'ringing' => t('Twilio is ringing your phone. Answer it to be connected.'),
        default   => t('Sent through Twilio. Its delivery status shows under Integrations.'),
    });
    redirect($back);
}

if ($do === 'preferences') {
    require_role('recruiter');
    $why = msg_preferences_save($cid, ! empty($_POST['no_texts']), ! empty($_POST['no_calls']), (string) ($_POST['reason'] ?? ''), uid());
    if ($why !== null) { refuse(422, $why); }
    log_activity('recorded how a person may be contacted', 'candidate', $cid, (! empty($_POST['no_texts']) ? 'no texts ' : '') . (! empty($_POST['no_calls']) ? 'no calls' : ''));
    flash(t('Saved.'));
    redirect('/candidates/' . $cid . '#contact');
}

refuse(400, t('Unknown action.'));
