<?php
/**
 * Integrations (P4-M04), administrators only: whether Gmail, DocuSign and
 * Stripe are on (never a setting's value), the Gmail connections that need
 * reconnecting, the DocuSign Connect queue with its failures to requeue,
 * the Stripe events received, and the audit trail of every outcome.
 */

require_once __DIR__ . '/../integrations.php';
require_once __DIR__ . '/../docusign.php';
require_once __DIR__ . '/../connectors.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'mode') {
    $connector = (string) ($_POST['connector'] ?? '');
    $why = connector_set_mode($connector, (string) ($_POST['mode'] ?? ''), (string) ($_POST['authorization'] ?? ''), (string) ($_POST['note'] ?? ''));
    if ($why !== null) {
        refuse(422, $why);
    }
    log_activity('changed a connector mode', 'integration', 0, $connector . ' ' . $_POST['mode']);
    flash(t('Connector saved.'));
    redirect('/integrations#connectors');
}

// P5-M08: Twilio's credentials, and the check of the account (the sandbox trial).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['do'] ?? '', ['twilio_save', 'twilio_check'], true)) {
    require_once __DIR__ . '/../twilio.php';
    $why = $_POST['do'] === 'twilio_save' ? tw_credentials_save($_POST, uid()) : tw_check_account();
    if ($why !== null) {
        refuse(422, $why);
    }
    log_activity($_POST['do'] === 'twilio_save' ? 'saved the Twilio credentials' : 'checked the Twilio account', 'integration', 0, 'twilio');
    flash($_POST['do'] === 'twilio_save' ? t('Saved. Twilio is in sandbox until the account is checked.') : t('The account is active: the sandbox trial passed.'));
    redirect('/integrations#twilio');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['do'] ?? '') !== 'requeue') {
        refuse(400, t('Unknown action.'));
    }
    if (! docusign_connect_requeue((string) ($_POST['event'] ?? ''))) {
        refuse(422, t('Only a failed Connect event is requeued.'));
    }
    log_activity('requeued a DocuSign Connect event', 'integration', 0, (string) $_POST['event']);
    flash(t('Requeued: the next reconciliation run tries it again from the first attempt.'));
    redirect('/integrations#connect');
}

$provider = in_array($_GET['provider'] ?? '', ['gmail', 'docusign', 'stripe', 'adp', 'ats', 'twilio'], true) ? (string) $_GET['provider'] : '';
$outcome = in_array($_GET['outcome'] ?? '', ['ok', 'duplicate', 'ignored', 'retry', 'failed', 'refused'], true) ? (string) $_GET['outcome'] : '';
$where = [];
$args = [];
if ($provider !== '') { $where[] = 'e.provider = ?'; $args[] = $provider; }
if ($outcome !== '') { $where[] = 'e.outcome = ?'; $args[] = $outcome; }
$events = rows('SELECT e.*, u.name AS by_name FROM integration_events e LEFT JOIN users u ON u.id = e.user_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY e.id DESC LIMIT 200', $args);
$status = integration_status();
$gmail = row("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'reconnect'), 0) AS reconnect FROM gmail_connections");
$reconnect = rows("SELECT g.user_id, u.name, g.last_error, g.updated_at FROM gmail_connections g JOIN users u ON u.id = g.user_id WHERE g.status = 'reconnect' ORDER BY u.name");
$connect = rows("SELECT payload_hash, envelope_id, status, attempts, next_attempt_at, last_error, created_at, processed_at FROM docusign_connect_events
                 WHERE status IN ('pending','failed') OR processed_at > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY FIELD(status, 'failed', 'pending', 'processed'), created_at DESC LIMIT 100");
$stripe = rows('SELECT event_id, event_type, processed_at FROM saas_webhook_events ORDER BY processed_at DESC LIMIT 20');

$connectors = connector_list();
require_once __DIR__ . '/../twilio.php';
$twilio = tw_summary() + ['status_url' => tw_public_url('kind=status'), 'inbound_url' => tw_public_url('kind=inbound'),
                         'recent' => rows('SELECT m.*, c.full_name FROM sms_messages m LEFT JOIN candidates c ON c.id = m.candidate_id ORDER BY m.id DESC LIMIT 30')];
render('integrations', compact('provider', 'outcome', 'events', 'status', 'gmail', 'reconnect', 'connect', 'stripe', 'connectors', 'twilio'));
