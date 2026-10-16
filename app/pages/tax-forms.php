<?php
/**
 * P5-M64: the worker fills in and signs their W-4 and Section 1 of their I-9 (?fill=w4|i9); a signed form
 * is opened (?id=N) by the worker, masked, or in full by the people who process it, and decided (POST).
 */

require_login();
require_once __DIR__ . '/../tax-forms.php';

$isWorker = is_worker_account();
$cid = $isWorker ? (int) val('SELECT candidate_id FROM worker_accounts WHERE user_id = ?', [uid()]) : 0;
if (! $isWorker) {
    require_role('payroll', 'recruiter');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'sign') {
        $form = (string) ($_POST['form'] ?? '');
        if (! $isWorker || ! isset(tf_forms()[$form])) {
            refuse(403, t('The worker signs their own forms, from their portal.'), '/portal');
        }
        db()->beginTransaction();
        q('SELECT id FROM candidates WHERE id = ? FOR UPDATE', [$cid]);
        $why = tf_submit($cid, $form, $_POST, uid());
        if ($why !== null) {
            db()->rollBack();
            tf_remember($form, $_POST);
            refuse(422, $why, '/tax-forms?fill=' . $form);
        }
        db()->commit();
        log_activity('signed a form in the portal', 'candidate', $cid, $form);
        flash($form === 'w4' ? t('Your W-4 is signed and sent. Payroll enters it and you are told here.') : t('Section 1 of your I-9 is signed and sent. Bring your original documents on your first day for Section 2.'));
        redirect('/portal#onboarding');
    }
    if ($do === 'decide') {
        if ($isWorker) {
            refuse(403, t('Only payroll or the office decides a form.'), '/portal');
        }
        $id = (int) ($_POST['form_id'] ?? 0);
        db()->beginTransaction();
        $why = tf_decide($id, (string) ($_POST['decision'] ?? ''), (string) ($_POST['note'] ?? ''), uid(), isset($_POST['processed']), $_POST);
        if ($why !== null) {
            db()->rollBack();
            // What the office typed in Section 2 (or payroll in "Employers Only") comes back, kept in the session only.
            $_SESSION['tf_decide_old'][$id] = array_map(static fn($v) => is_string($v) ? mb_substr($v, 0, 1000) : '', array_diff_key($_POST, ['_csrf' => 1]));
            refuse(422, $why, '/tax-forms?id=' . $id);
        }
        db()->commit();
        log_activity('decided a form signed in the portal', 'tax_form', $id, (string) ($_POST['decision'] ?? ''));
        flash(($_POST['decision'] ?? '') === 'accept' ? t('Accepted. The worker has been told.') : t('Sent back. The worker has been told what to correct.'));
        redirect('/change-requests#tax-forms');
    }
    refuse(422, t('Unknown action.'));
}

// A signed form, opened.
if (isset($_GET['id'])) {
    $tf = row('SELECT * FROM worker_tax_forms WHERE id = ?', [(int) $_GET['id']]);
    $own = $tf && $isWorker && (int) $tf['candidate_id'] === $cid;
    if (! $tf || (! $own && ($isWorker || ! tf_staff_can_open($tf)))) {
        refuse(404, t('That form is not available to you.'));
    }
    $tf = tf_open((int) $tf['id'], uid(), $own);
    $reviewer = $tf['reviewed_by'] ? (string) val('SELECT name FROM users WHERE id = ?', [(int) $tf['reviewed_by']]) : '';
    $firstDay = (string) val("SELECT MIN(start_date) FROM placements WHERE candidate_id = ? AND status IN ('confirmed','travelling','on_site','completed')", [(int) $tf['candidate_id']]);
    $canDecide = ! $own && $tf['status'] === 'submitted' && can(tf_forms()[$tf['form']]['desk']);
    // P5-M64c: the official form, filled in the browser from this record; the employer's part once accepted.
    $pdf = $tf['intact'] ? tf_pdf_map($tf, $tf['employer'], $own) : null;
    $empDefaults = tf_employer_defaults();
    $decideOld = $_SESSION['tf_decide_old'][(int) $tf['id']] ?? [];
    unset($_SESSION['tf_decide_old'][(int) $tf['id']]);
    $pageTitle = tf_forms()[$tf['form']]['short'] . ' · ' . $tf['full_name'];
    render('tax-form-show', compact('tf', 'own', 'reviewer', 'firstDay', 'canDecide', 'pdf', 'empDefaults', 'decideOld'));
    exit;
}

// The worker fills one in.
$form = (string) ($_GET['fill'] ?? '');
if (! $isWorker || ! isset(tf_forms()[$form])) {
    redirect($isWorker ? '/portal#onboarding' : '/change-requests#tax-forms');
}
$c = row('SELECT full_name, email, phone, city, state FROM candidates WHERE id = ?', [$cid]);
$parts = preg_split('/\s+/', trim((string) $c['full_name'])) ?: [''];
$prefill = ['first_name' => $parts[0], 'last_name' => count($parts) > 1 ? end($parts) : '', 'city' => (string) $c['city'],
            'state' => strlen((string) $c['state']) === 2 ? strtoupper((string) $c['state']) : '', 'email' => (string) $c['email'], 'phone' => (string) $c['phone']];
require_once __DIR__ . '/../travel-details.php';
if (($tp = tp_profile($cid)) && $tp['address'] !== '') {
    $prefill['address'] = $tp['address'];
}
$old = tf_recall($form) + $prefill;
$history = tf_history($cid, $form);
$waiting = $history && $history[0]['status'] === 'submitted';
$pageTitle = tf_forms()[$form]['short'] . ' · ' . $config['app_name'];
render('tax-form-fill', compact('form', 'old', 'history', 'waiting'));
