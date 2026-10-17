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
        $refusal = self_bank_decide((int) ($_POST['request_id'] ?? 0), $decision, $note, uid(), isset($_POST['matched']));
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

// P5-M63: the proof sent with the bank details, opened in the browser by payroll only; every look is recorded.
if (isset($_GET['proof'])) {
    require_role('payroll');
    $proof = bank_proof_read((int) $_GET['proof'], uid());
    if (! $proof) { refuse(404, t('No readable proof for these bank details.')); }
    header('Content-Type: ' . $proof['mime']);
    header('Content-Disposition: inline; filename="bank-proof-' . (int) $_GET['proof'] . ($proof['mime'] === 'application/pdf' ? '.pdf' : ($proof['mime'] === 'image/png' ? '.png' : '.jpg')) . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $proof['bytes'];
    exit;
}

$bank = can('payroll') ? rows("SELECT r.id, r.candidate_id, r.last_four, r.bank_label, r.submitted_at, c.full_name, r.proof_mime,
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
// P5-M64: the W-4s (payroll) and I-9s (the office) signed in the portal, waiting to be checked.
require_once __DIR__ . '/../tax-forms.php';
$taxForms = tf_waiting();
// P5-M65: the people whose forms are filled in, to sign at the office in front of the coordinator.
$toSign = can('recruiter') ? array_values(array_reduce(tf_drafts(), static function ($acc, $r) { $acc[$r['candidate_id']] ??= ['id' => $r['candidate_id'], 'name' => $r['full_name'], 'forms' => []]; $acc[$r['candidate_id']]['forms'][] = tf_forms()[$r['form']]['short']; return $acc; }, [])) : [];
$pageTitle = t('Change requests') . ' · ' . $config['app_name'];
render('change-requests', compact('bank', 'details', 'revealed', 'fields', 'taxForms', 'toSign'));
