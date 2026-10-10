<?php
/**
 * What workers have asked to change, waiting for somebody here to check
 * it (R24). Payroll checks bank details; recruiters check personal
 * details. An administrator sees both.
 */

require_once __DIR__ . '/../self-service.php';

require_role('payroll', 'recruiter');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $decision = (string) ($_POST['decision'] ?? '');
    $note = (string) ($_POST['note'] ?? '');

    db()->beginTransaction();

    if ($do === 'bank') {
        require_role('payroll');
        $refusal = self_bank_decide((int) ($_POST['request_id'] ?? 0), $decision, $note, uid());
    } elseif ($do === 'detail') {
        require_role('recruiter');
        $refusal = self_detail_decide((int) ($_POST['request_id'] ?? 0), $decision, $note, uid());
    } else {
        $refusal = t('Unknown action.');
    }

    if ($refusal !== null) {
        db()->rollBack();
        refuse(422, $refusal);
    }

    db()->commit();
    log_activity($do === 'bank' ? 'decided proposed bank details' : 'decided a detail change', 'change_request', (int) ($_POST['request_id'] ?? 0), $decision);
    flash($decision === 'approve' ? t('Accepted. The worker has been told.') : t('Refused. The worker has been told why.'));
    redirect('/change-requests');
}

$bank = can('payroll') ? rows("SELECT r.id, r.candidate_id, r.last_four, r.bank_label, r.submitted_at, c.full_name,
                                      (SELECT b.last_four FROM worker_bank_details b WHERE b.candidate_id = r.candidate_id) AS current_last_four
                               FROM worker_bank_change_requests r JOIN candidates c ON c.id = r.candidate_id
                               WHERE r.status = 'pending' ORDER BY r.id") : [];
$details = can('recruiter') ? rows("SELECT r.*, c.full_name FROM profile_change_requests r JOIN candidates c ON c.id = r.candidate_id
                                    WHERE r.status = 'pending' ORDER BY r.id") : [];

$revealed = null;
if (can('payroll') && isset($_GET['reveal'])) {
    $revealed = self_bank_reveal((int) $_GET['reveal']);
}

$fields = self_service_fields();
$pageTitle = t('Change requests') . ' · ' . $config['app_name'];
render('change-requests', compact('bank', 'details', 'revealed', 'fields'));
