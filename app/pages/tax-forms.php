<?php
/**
 * P5-M64/M65: the worker fills in their W-4 and Section 1 of their I-9 beforehand (?fill=w4|i9), as a draft,
 * with photos of the documents they chose if they wish; they sign in person (?sign), with the code the
 * coordinator in front of them makes (?person=N); the office completes Section 2 with the originals and keeps
 * a copy of each (?id=N); payroll enters the W-4 in ADP. A form is opened (?id=N) masked by the worker, in
 * full by the people who process it.
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
    // The worker saves the form, filled in; it is signed at the office.
    if ($do === 'save') {
        $form = (string) ($_POST['form'] ?? '');
        if (! $isWorker || ! isset(tf_forms()[$form])) {
            refuse(403, t('The worker fills their own forms, from their portal.'), '/portal');
        }
        db()->beginTransaction();
        q('SELECT id FROM candidates WHERE id = ? FOR UPDATE', [$cid]);
        $why = tf_save_draft($cid, $form, $_POST, uid());
        // The photos of the documents they chose, if they send any (optional).
        if ($why === null && $form === 'i9') {
            foreach ((array) ($_FILES['copy_file']['name'] ?? []) as $i => $n) {
                if ((int) ($_FILES['copy_file']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $file = ['name' => $n, 'type' => $_FILES['copy_file']['type'][$i], 'tmp_name' => $_FILES['copy_file']['tmp_name'][$i], 'error' => $_FILES['copy_file']['error'][$i], 'size' => $_FILES['copy_file']['size'][$i]];
                if (($why = tf_copy_add($cid, (string) ($_POST['copy_list'][$i] ?? ''), (string) ($_POST['copy_title'][$i] ?? ''), $file, uid(), false)) !== null) {
                    break;
                }
            }
        }
        if ($why !== null) {
            db()->rollBack();
            tf_remember($form, $_POST);
            refuse(422, $why, '/tax-forms?fill=' . $form);
        }
        db()->commit();
        flash(t('Saved. Sign it at the office, in front of the coordinator: they give you a code, you sign on your phone.'));
        redirect('/portal#onboarding');
    }
    // The worker signs in person, with the coordinator's code.
    if ($do === 'sign_in_person') {
        if (! $isWorker) {
            refuse(403, t('The worker signs their own forms, from their own phone.'), '/');
        }
        // Counted outside the transaction, so a wrong code counts even though the attempt is undone.
        require_once __DIR__ . '/../security.php';
        if (! security_rate_limit('forms-signing-code', (string) uid(), 10, 900)) {
            refuse(429, t('Too many tries. Ask the coordinator for a new code in 15 minutes.'), '/tax-forms?sign=');
        }
        db()->beginTransaction();
        q('SELECT id FROM candidates WHERE id = ? FOR UPDATE', [$cid]);
        $why = tf_sign_in_person($cid, (string) ($_POST['code'] ?? ''), $_POST, uid());
        if ($why !== null) {
            db()->rollBack();
            refuse(422, $why, '/tax-forms?sign=' . preg_replace('/\D/', '', (string) ($_POST['code'] ?? '')));
        }
        db()->commit();
        flash(t('Signed, in front of the coordinator. Show them your original documents for Section 2 of the I-9.'));
        redirect('/portal#onboarding');
    }
    if ($isWorker) {
        refuse(403, t('Only payroll or the office does that.'), '/portal');
    }
    // The coordinator, with the worker in front of them, opens the signature.
    if ($do === 'authorize') {
        require_role('recruiter');
        $person = (int) ($_POST['candidate_id'] ?? 0);
        $made = tf_code_make($person, uid());
        if (! $made) {
            refuse(422, t('This person has no form filled in to sign.'), '/tax-forms?person=' . $person);
        }
        $_SESSION['tf_code'] = ['candidate_id' => $person, 'code' => $made[0], 'expires' => $made[1]];
        redirect('/tax-forms?person=' . $person . '#sign-code');
    }
    // The office photographs an original document for Section 2.
    if ($do === 'add_copy') {
        require_role('recruiter');
        $person = (int) ($_POST['candidate_id'] ?? 0);
        $back = (string) ($_POST['back'] ?? '');
        $back = preg_match('#^/tax-forms\?(id|person)=\d+$#', $back) ? $back : '/tax-forms?person=' . $person;
        if (! val('SELECT id FROM candidates WHERE id = ?', [$person])) {
            refuse(404, t('That person does not exist.'), '/change-requests#tax-forms');
        }
        if (($why = tf_copy_add($person, (string) ($_POST['list'] ?? ''), (string) ($_POST['title'] ?? ''), $_FILES['copy'] ?? null, uid(), true)) !== null) {
            refuse(422, $why, $back);
        }
        flash(t('Copy kept, encrypted.'));
        redirect($back . '#copies');
    }
    if ($do === 'everify') {
        require_role('recruiter');
        $id = (int) ($_POST['form_id'] ?? 0);
        if (($why = tf_everify_save($id, (string) ($_POST['case'] ?? ''), (string) ($_POST['result'] ?? ''), uid())) !== null) {
            refuse(422, $why, '/employment?candidate_id=' . (int) val('SELECT candidate_id FROM worker_tax_forms WHERE id = ?', [$id]));
        }
        flash(t('E-Verify case recorded.'));
        redirect('/employment?candidate_id=' . (int) val('SELECT candidate_id FROM worker_tax_forms WHERE id = ?', [$id]) . '#everify');
    }
    if ($do === 'decide') {
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

// A copy of an I-9 document, opened for comparison (the office while it is checked; then administrators and readers).
if (isset($_GET['copy'])) {
    $doc = row('SELECT d.* FROM worker_documents d JOIN i9_document_copies k ON k.document_id = d.id WHERE d.id = ?', [(int) $_GET['copy']]);
    if (! $doc || ! doc_can_open($doc, $isWorker, $isWorker ? $cid : null)) {
        refuse(404, t('That copy is not available to you.'));
    }
    global $config;
    require_once __DIR__ . '/../crypto.php';
    $raw = @file_get_contents(workforce_storage_path($config) . '/documents/' . $doc['storage_name']);
    $plain = $raw === false ? null : crypto_open($raw);
    if ($plain === null || ! hash_equals((string) $doc['file_hash'], hash('sha256', $plain))) {
        refuse(500, t('Document integrity check failed.'));
    }
    log_activity('opened a copy of an I-9 document', 'worker_document', (int) $doc['id'], 'candidate ' . (int) $doc['candidate_id']);
    header('Content-Type: ' . $doc['mime_type']);
    header('Content-Disposition: inline; filename="i9-copy-' . (int) $doc['id'] . ($doc['mime_type'] === 'application/pdf' ? '.pdf' : ($doc['mime_type'] === 'image/png' ? '.png' : '.jpg')) . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $plain;
    exit;
}

// The coordinator's screen: the person, their forms filled in, their documents, the signature to open.
if (isset($_GET['person'])) {
    require_role('recruiter');
    $person = row('SELECT id, full_name, email, phone FROM candidates WHERE id = ?', [(int) $_GET['person']]);
    if (! $person) {
        refuse(404, t('That person does not exist.'));
    }
    $forms = [];
    foreach (['w4', 'i9'] as $f) {
        $r = row('SELECT * FROM worker_tax_forms WHERE candidate_id = ? AND form = ? ORDER BY id DESC LIMIT 1', [(int) $person['id'], $f]);
        $forms[$f] = $r ? ['row' => $r, 'data' => tf_payload($r)] : null;
    }
    $copies = tf_copies((int) $person['id']);
    // P5-M66: what still stands between them and the site, for each assignment not started yet.
    require_once __DIR__ . '/../deployment.php';
    deployment_ready_notice((int) $person['id']);
    $remaining = [];
    foreach (rows("SELECT p.id, p.job_id, p.status, p.start_date, j.title FROM placements p JOIN jobs j ON j.id = p.job_id WHERE p.candidate_id = ? AND p.status IN ('offered','confirmed','travelling') ORDER BY p.id DESC", [(int) $person['id']]) as $pl) {
        // P5-M66b: before travelling, what is done from home; once confirmed, what is done on arrival; Section 2's deadline.
        $s2 = (string) val('SELECT i9_status FROM employment_clearance WHERE candidate_id = ?', [(int) $person['id']]);
        $remaining[] = $pl + ['blockers' => deployment_blockers((int) $pl['id'], (int) $person['id'], (int) $pl['job_id'], $pl['status'] === 'offered' ? 'travel' : 'on_site'),
                              's2_due' => $pl['status'] !== 'offered' && $pl['start_date'] && $s2 !== 'employer_completed' ? tf_business_days_after((string) $pl['start_date'], 3) : null];
    }
    $signCode = null;
    if (isset($_SESSION['tf_code']) && (int) $_SESSION['tf_code']['candidate_id'] === (int) $person['id'] && $_SESSION['tf_code']['expires'] > date('Y-m-d H:i:s')) {
        $signCode = $_SESSION['tf_code'];
        unset($_SESSION['tf_code']);
    }
    $pageTitle = t('Forms to sign') . ' · ' . $person['full_name'];
    render('tax-form-person', compact('person', 'forms', 'copies', 'signCode', 'remaining'));
    exit;
}

// The worker signs, on their own phone, in front of the coordinator.
if (isset($_GET['sign'])) {
    if (! $isWorker) {
        redirect('/change-requests#tax-forms');
    }
    $drafts = [];
    foreach (rows("SELECT * FROM worker_tax_forms WHERE candidate_id = ? AND status = 'draft' ORDER BY form DESC", [$cid]) as $r) {
        $drafts[] = ['row' => $r, 'data' => tf_payload($r)];
    }
    $signCode = preg_replace('/\D/', '', (string) $_GET['sign']);
    $pageTitle = t('Sign my forms');
    render('tax-form-sign', compact('drafts', 'signCode'));
    exit;
}

// A form, opened.
if (isset($_GET['id'])) {
    $tf = row('SELECT * FROM worker_tax_forms WHERE id = ?', [(int) $_GET['id']]);
    $own = $tf && $isWorker && (int) $tf['candidate_id'] === $cid;
    if (! $tf || (! $own && ($isWorker || ! tf_staff_can_open($tf)))) {
        refuse(404, t('That form is not available to you.'));
    }
    $tf = tf_open((int) $tf['id'], uid(), $own);
    $reviewer = $tf['reviewed_by'] ? (string) val('SELECT name FROM users WHERE id = ?', [(int) $tf['reviewed_by']]) : '';
    $witness = $tf['witness_id'] ? (string) val('SELECT name FROM users WHERE id = ?', [(int) $tf['witness_id']]) : '';
    $firstDay = (string) val("SELECT MIN(start_date) FROM placements WHERE candidate_id = ? AND status IN ('confirmed','travelling','on_site','completed')", [(int) $tf['candidate_id']]);
    $canDecide = ! $own && $tf['status'] === 'submitted' && can(tf_forms()[$tf['form']]['desk']);
    // P5-M64c: the official form, filled in the browser from this record; the employer's part once accepted.
    $pdf = $tf['intact'] && $tf['status'] !== 'draft' ? tf_pdf_map($tf, $tf['employer'], $own) : null;
    $empDefaults = tf_employer_defaults();
    $decideOld = $_SESSION['tf_decide_old'][(int) $tf['id']] ?? [];
    unset($_SESSION['tf_decide_old'][(int) $tf['id']]);
    // P5-M65: the copies kept, and what Crewvia compares, for Section 2.
    $copies = $tf['form'] === 'i9' && ! $own ? tf_copies((int) $tf['candidate_id']) : [];
    // P5-M68: who opened, signed, accepted or sent it back, and when - for the owner and the readers they authorize.
    $accessLog = ! $own && (can('admin') || id_is_reader(uid())) ? rows('SELECT a.action, a.created_at, u.name FROM worker_tax_form_access a LEFT JOIN users u ON u.id = a.user_id WHERE a.form_id = ? ORDER BY a.id', [(int) $tf['id']]) : [];
    $pageTitle = tf_forms()[$tf['form']]['short'] . ' · ' . $tf['full_name'];
    render('tax-form-show', compact('tf', 'own', 'reviewer', 'witness', 'firstDay', 'canDecide', 'pdf', 'empDefaults', 'decideOld', 'copies', 'accessLog'));
    exit;
}

// The worker fills one in.
$form = (string) ($_GET['fill'] ?? '');
if (! $isWorker || ! isset(tf_forms()[$form])) {
    redirect($isWorker ? '/portal#onboarding' : '/change-requests#to-sign');
}
$c = row('SELECT full_name, email, phone, city, state FROM candidates WHERE id = ?', [$cid]);
$parts = preg_split('/\s+/', trim((string) $c['full_name'])) ?: [''];
$prefill = ['first_name' => $parts[0], 'last_name' => count($parts) > 1 ? end($parts) : '', 'city' => (string) $c['city'],
            'state' => strlen((string) $c['state']) === 2 ? strtoupper((string) $c['state']) : '', 'email' => (string) $c['email'], 'phone' => (string) $c['phone']];
require_once __DIR__ . '/../travel-details.php';
// P5-M65b: the travel profile keeps the address on one line ("12 Main St, Gary, IN 46402"): it is split into the
// form's street, city, state and ZIP, or not used (it filled the street field with the whole address).
if (($tp = tp_profile($cid)) && preg_match('/^(.+?),\s*([^,]+?),\s*([A-Za-z]{2})\s+(\d{5}(?:-?\d{4})?)$/', trim((string) $tp['address']), $m)) {
    $prefill = ['address' => trim($m[1]), 'city' => trim($m[2]), 'state' => strtoupper($m[3]), 'zip' => $m[4]] + $prefill;
}
// The second form starts from the first: the same name and address, so the W-4 and the I-9 agree.
$other = row("SELECT * FROM worker_tax_forms WHERE candidate_id = ? AND form <> ? ORDER BY id DESC LIMIT 1", [$cid, $form]);
if ($other && ($od = tf_payload($other))) {
    $prefill = array_filter(array_intersect_key($od, array_flip(['first_name', 'middle_initial', 'last_name', 'address', 'apartment', 'city', 'state', 'zip'])), 'is_string') + $prefill;
}
// P5-M65: a draft comes back as it was saved, less the numbers (kept unless typed again).
$draft = row("SELECT * FROM worker_tax_forms WHERE candidate_id = ? AND form = ? AND status = 'draft' ORDER BY id DESC LIMIT 1", [$cid, $form]);
$draftData = $draft ? tf_payload($draft) : [];
$kept = ['ssn' => ! empty($draftData['ssn']) ? substr((string) $draftData['ssn'], -4) : '', 'doc_number' => ! empty($draftData['doc_number']) ? substr((string) $draftData['doc_number'], -4) : ''];
$fromDraft = [];
foreach (array_diff_key($draftData, array_flip(['ssn', 'doc_number', 'preparer'])) as $k => $val) {
    $fromDraft[$k] = is_bool($val) ? ($val ? '1' : '') : (is_scalar($val) ? (string) $val : '');
}
foreach ((array) ($draftData['preparer'] ?? []) as $k => $val) {
    $fromDraft['preparer_' . (['last_name' => 'last', 'first_name' => 'first', 'middle_initial' => 'mi'][$k] ?? $k)] = is_scalar($val) ? (string) $val : '';
}
if (! empty($draftData['preparer'])) {
    $fromDraft['used_preparer'] = '1';
}
$old = tf_recall($form) + $fromDraft + $prefill;
$history = array_values(array_filter(tf_history($cid, $form), static fn($h) => $h['status'] !== 'draft'));
$waiting = $history && $history[0]['status'] === 'submitted';
$copies = $form === 'i9' ? tf_copies($cid) : [];
$everify = tf_everify();
$pageTitle = tf_forms()[$form]['short'] . ' · ' . $config['app_name'];
render('tax-form-fill', compact('form', 'old', 'history', 'waiting', 'draft', 'kept', 'copies', 'everify'));
