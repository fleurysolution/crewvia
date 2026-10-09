<?php
/**
 * Approvals: the steps this person can decide, and where everything stands.
 *
 * The chain decides who is asked and in what order; this screen is only the
 * place a decision is recorded.
 */

require_login();
require_once __DIR__ . '/../approvals.php';

if (! approvals_available()) {
    refuse(503, t('Approvals are not set up on this workspace yet. Run the upgrade.'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = approval_decide(
        (int) ($_POST['request_id'] ?? 0),
        (string) ($_POST['decision'] ?? ''),
        (string) ($_POST['comments'] ?? ''));

    flash(t($result['message']), $result['ok'] ? 'ok' : 'err');
    redirect('/approvals');
}

$mine = approval_queue();

// Everything in flight, so somebody can see what is waiting on whom rather
// than only what is waiting on them.
$inFlight = rows("SELECT subject_type, subject_id, subject_label,
                         MIN(CASE WHEN status = 'pending' THEN step_order END) next_step,
                         SUM(status = 'approved') approved,
                         SUM(status = 'pending')  pending,
                         SUM(status = 'rejected') rejected,
                         COUNT(*) steps,
                         MAX(created_at) started
                  FROM approval_requests
                  WHERE status <> 'cancelled'
                  GROUP BY subject_type, subject_id, subject_label
                  ORDER BY rejected DESC, pending DESC, started DESC
                  LIMIT 100");

$decided = rows("SELECT r.*, u.name AS decided_name
                 FROM approval_requests r LEFT JOIN users u ON u.id = r.decided_by
                 WHERE r.status IN ('approved','rejected')
                 ORDER BY r.decided_at DESC LIMIT 15");

$pageTitle = t('Approvals') . ' · ' . $config['app_name'];

render('approvals', compact('mine', 'inFlight', 'decided'));
