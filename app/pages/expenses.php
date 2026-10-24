<?php
/**
 * Reimbursements: a worker claims what they spent for the job, with its receipt; payroll approves it (never their
 * own) and pays it, on the next pay (P5-M110) or separately. Payroll sees every project, like the pay periods.
 * See app/expense-claims.php.
 */

require_once __DIR__ . '/../expense-claims.php';

require_login();
$own = is_worker_account();
if (! $own) {
    require_role('payroll');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $claimId = (int) ($_POST['claim_id'] ?? 0);

    if ($do === 'claim') {
        $pid = (int) ($_POST['placement_id'] ?? 0);
        $doc = (int) ($_POST['receipt_document_id'] ?? 0);
        $amount = (string) ($_POST['amount'] ?? '');
        $category = (string) ($_POST['category'] ?? '');
        $p = row('SELECT p.id, p.candidate_id FROM placements p JOIN worker_accounts w ON w.candidate_id = p.candidate_id WHERE p.id = ? AND w.user_id = ?', [$pid, uid()]);
        // The receipt: photographed or chosen right here, or one uploaded before under Proof uploads.
        if ($p && ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            require_once __DIR__ . '/../identity-docs.php';
            $stored = worker_document_store((int) $p['candidate_id'], $_FILES['receipt'], 'Receipt');
            if (is_string($stored)) {
                refuse(422, $stored);
            }
            $doc = $stored;
        }
        if (! $p || ! is_numeric($amount) || ! is_finite((float) $amount) || (float) $amount <= 0 || (float) $amount > 100000
            || ! array_key_exists($category, expense_categories()) || ! row('SELECT id FROM worker_documents WHERE id = ? AND candidate_id = ?', [$doc, $p['candidate_id']])) {
            refuse(422, t('Valid assignment, positive amount and your own receipt are required.'));
        }
        // One receipt, one claim: the same bag is not paid back twice.
        if (val("SELECT COUNT(*) FROM expense_claims WHERE receipt_document_id = ? AND status <> 'rejected'", [$doc])) {
            refuse(422, t('That receipt is already on another claim.'));
        }
        q('INSERT INTO expense_claims (placement_id, user_id, category, amount, receipt_document_id, note) VALUES (?,?,?,?,?,?)',
          [$pid, uid(), $category, round((float) $amount, 2), $doc, mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 2000)]);
        $id = (int) db()->lastInsertId();
        log_activity('filed a reimbursement claim', 'expense_claim', $id, $category);
        flash(t('Claim sent. Payroll reviews it; you are told when it is paid.'));
        redirect('/expenses#claim-' . $id);
    }

    require_role('payroll');

    if ($do === 'review') {
        $status = (string) ($_POST['status'] ?? '');
        $done = in_array($status, ['approved', 'rejected'], true) ? q("UPDATE expense_claims e SET e.status = ?, e.reviewed_by = ?, e.reviewed_at = NOW()
            WHERE e.id = ? AND e.status = 'submitted' AND e.user_id <> ?", [$status, uid(), $claimId, uid()])->rowCount() : 0;
        if ($done !== 1) {
            refuse(422, t('Only a claim waiting for review is decided, and never by the person who filed it.'));
        }
        log_activity('reviewed a reimbursement claim', 'expense_claim', $claimId, $status);
        flash($status === 'approved' ? t('Approved. Pay it on the next pay, or record a separate payment.') : t('Not approved.'));
        redirect('/expenses#claim-' . $claimId);
    }

    if ($do === 'pay_on_pay') {
        if ($why = expense_pay_in_run((int) ($_POST['run_id'] ?? 0), $claimId, uid())) {
            refuse(422, $why);
        }
        flash(t('Added to the pay period as a reimbursement. The claim is paid.'));
        redirect('/expenses#claim-' . $claimId);
    }

    if ($do === 'pay') {
        if ($why = expense_pay_separately($claimId, (string) ($_POST['payment_method'] ?? ''), (string) ($_POST['payment_reference'] ?? ''))) {
            refuse(422, $why);
        }
        log_activity('recorded a reimbursement payment', 'expense_claim', $claimId, (string) ($_POST['payment_method'] ?? ''));
        flash(t('Payment recorded. The claim is paid.'));
        redirect('/expenses#claim-' . $claimId);
    }

    refuse(422, t('Unknown action.'));
}

$select = 'SELECT e.*, j.title, c.full_name, d.document_type AS receipt_type, d.created_at AS receipt_at, u.name AS reviewer,
                  a.run_id, r.week_ending AS paid_week
           FROM expense_claims e JOIN placements p ON p.id = e.placement_id JOIN jobs j ON j.id = p.job_id
           JOIN candidates c ON c.id = p.candidate_id LEFT JOIN worker_documents d ON d.id = e.receipt_document_id
           LEFT JOIN users u ON u.id = e.reviewed_by LEFT JOIN payroll_adjustments a ON a.expense_claim_id = e.id
           LEFT JOIN payroll_runs r ON r.id = a.run_id';
$assignments = $receipts = $runs = [];
if ($own) {
    $claims = rows($select . ' WHERE e.user_id = ? ORDER BY e.id DESC', [uid()]);
    $assignments = rows('SELECT p.id, j.title FROM placements p JOIN jobs j ON j.id = p.job_id JOIN worker_accounts w ON w.candidate_id = p.candidate_id
                         WHERE w.user_id = ? ORDER BY p.id DESC', [uid()]);
    $receipts = rows('SELECT d.id, d.document_type, d.created_at FROM worker_documents d JOIN worker_accounts w ON w.candidate_id = d.candidate_id
                      WHERE w.user_id = ? AND NOT EXISTS (SELECT 1 FROM expense_claims e WHERE e.receipt_document_id = d.id AND e.status <> \'rejected\')
                      ORDER BY d.id DESC', [uid()]);
} else {
    $claims = rows($select . " ORDER BY FIELD(e.status, 'submitted', 'approved', 'paid', 'rejected'), e.id DESC LIMIT 300");
    $runs = expense_open_runs();
}

$pageTitle = ($own ? t('My reimbursements') : t('Reimbursements')) . ' · ' . $config['app_name'];
render('expenses', compact('own', 'claims', 'assignments', 'receipts', 'runs'));
