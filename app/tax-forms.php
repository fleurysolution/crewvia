<?php
/**
 * P5-M64: the W-4 and Section 1 of Form I-9, filled in and signed in the worker's own portal.
 *
 * Decided with the owner, 16 Oct 2026, reversing P5-M31 ("Crewvia never asks for an SSN"): the onboarding
 * tasks said "complete Form W-4 through the payroll provider" and "the office completes Form I-9 with you",
 * so the worker filled paper and somebody here typed it again. Now the worker fills the forms here.
 *  - The Social Security number, and the immigration document number of the I-9, are masked as they are
 *    typed and typed twice. The whole form is encrypted at rest (encrypted_payload, rotated with the
 *    workspace key); lists and the worker's own screens show the last four digits only.
 *  - Who opens a form in full: a W-4, payroll (who enters it in ADP); an I-9, the office while it waits
 *    to be checked, then administrators and the readers they authorize, as identity documents (P5-M33).
 *    Every look is recorded, with who and when.
 *  - A signed form never changes. A correction is a new form; the record keeps who signed (the worker's
 *    own account), when, from which address and browser, the statement they signed, and the hash of
 *    what they signed, checked each time the form is opened.
 *  - Section 2 of the I-9 stays in person: the office examines the original documents (Employment).
 *  - The onboarding task the form answers closes by itself when the form is accepted, or goes back to
 *    the worker with the reason.
 */

declare(strict_types=1);

require_once __DIR__ . '/notices.php';
require_once __DIR__ . '/onboarding-tasks.php';

/** Step 3 of the W-4: per qualifying child under 17, per other dependent. Check them against each year's form. */
const TF_W4_CHILD = 2200;
const TF_W4_OTHER = 500;

/** The statements signed, word for word as on the official forms. */
const TF_W4_ATTEST = 'Under penalties of perjury, I declare that this certificate, to the best of my knowledge and belief, is true, correct, and complete.';
const TF_I9_ATTEST = 'I am aware that federal law provides for imprisonment and/or fines for false statements, or the use of false documents, in connection with the completion of this form. I attest, under penalty of perjury, that this information, including my selection of the box attesting to my citizenship or immigration status, is true and correct.';
const TF_I9_PREPARER_ATTEST = 'I attest, under penalty of perjury, that I have assisted in the completion of Section 1 of this form and that to the best of my knowledge the information is true and correct.';

/** The fields never kept for a second try nor shown back: they are typed again. */
const TF_SECRET_FIELDS = ['ssn', 'ssn_confirm', 'doc_number', 'doc_number_confirm'];

/** The forms, by key: its name, who checks it, the onboarding task it answers. */
function tf_forms(): array
{
    return [
        'w4' => ['name' => t('Form W-4, federal tax withholding'), 'short' => 'W-4', 'desk' => 'payroll'],
        'i9' => ['name' => t('Form I-9, Section 1: identity and authorization to work'), 'short' => 'I-9', 'desk' => 'recruiter'],
    ];
}

function tf_filing_statuses(): array
{
    return ['single' => t('Single or Married filing separately'), 'joint' => t('Married filing jointly or Qualifying surviving spouse'), 'head' => t('Head of household')];
}

function tf_i9_statuses(): array
{
    return ['citizen' => t('1. A citizen of the United States'), 'national' => t('2. A noncitizen national of the United States'),
            'permanent' => t('3. A lawful permanent resident'), 'authorized' => t('4. A noncitizen authorized to work')];
}

function tf_i9_doc_types(): array
{
    return ['a_number' => t('USCIS or A-Number'), 'i94' => t('Form I-94 admission number'), 'passport' => t('Foreign passport number')];
}

function tf_digits(mixed $v): string
{
    return preg_replace('/\D/', '', (string) $v);
}

/** A Social Security number as the SSA issues them: never area 000, 666 or 900-999, group 00, serial 0000. */
function tf_ssn_valid(string $ssn): bool
{
    return (bool) preg_match('/^(?!000|666|9\d\d)\d{3}(?!00)\d{2}(?!0000)\d{4}$/D', $ssn);
}

function tf_text(array $in, string $k, int $max = 190): string
{
    return mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($in[$k] ?? ''))), 0, $max);
}

/** A dollar amount typed by the worker, 0 when empty; null when it is not an amount. */
function tf_money(array $in, string $k): ?float
{
    $v = str_replace([',', '$', ' '], '', (string) ($in[$k] ?? ''));
    if ($v === '') {
        return 0.0;
    }

    return preg_match('/^\d{1,7}(\.\d{1,2})?$/D', $v) ? (float) $v : null;
}

/** The name and address parts both forms share. */
function tf_identity(array $in, array &$p): ?string
{
    foreach (['first_name' => t('Give your first name.'), 'last_name' => t('Give your last name.'), 'address' => t('Give your street address.'), 'city' => t('Give your city.')] as $k => $msg) {
        $p[$k] = tf_text($in, $k);
        if (mb_strlen($p[$k]) < 1) {
            return $msg;
        }
    }
    $p['middle_initial'] = mb_strtoupper(mb_substr(tf_text($in, 'middle_initial', 1), 0, 1));
    $p['apartment'] = tf_text($in, 'apartment', 30);
    $p['state'] = strtoupper(tf_text($in, 'state', 2));
    if (! preg_match('/^[A-Z]{2}$/D', $p['state'])) {
        return t('Give the state as its two letters, for example IN.');
    }
    $p['zip'] = tf_text($in, 'zip', 10);
    if (! preg_match('/^\d{5}(-?\d{4})?$/D', $p['zip'])) {
        return t('Give the ZIP code: five digits, or five and four.');
    }

    return null;
}

/** The SSN typed twice, valid; $optional lets it be left out (I-9 without E-Verify, a W-4 "applied for"). */
function tf_ssn(array $in, array &$p, bool $optional): ?string
{
    $ssn = tf_digits($in['ssn'] ?? '');
    if ($ssn === '' && $optional) {
        $p['ssn'] = '';

        return null;
    }
    if (! tf_ssn_valid($ssn)) {
        return t('That is not a valid Social Security number: nine digits, as on your card.');
    }
    if (tf_digits($in['ssn_confirm'] ?? '') !== $ssn) {
        return t('The two Social Security numbers differ. Type it again, digit by digit, from your card.');
    }
    $p['ssn'] = $ssn;

    return null;
}

/** The typed signature: the worker's own first and last name, as typed in the form, and the box ticked. */
function tf_signature(array $in, array $p): ?string
{
    $signed = mb_strtolower(tf_text($in, 'signer_name'));
    if ($signed === '' || ! str_contains($signed, mb_strtolower($p['first_name'])) || ! str_contains($signed, mb_strtolower($p['last_name']))) {
        return t('Sign by typing your first and last name, as written in the form.');
    }
    if (empty($in['attest'])) {
        return t('Tick the box to sign the statement.');
    }

    return null;
}

/** @return array{0:?string,1:array} the refusal, or null and what the worker certified */
function tf_check_w4(array $in): array
{
    $p = ['tax_year' => (int) date('Y')];
    if (($why = tf_identity($in, $p)) !== null) {
        return [$why, []];
    }
    $p['ssn_applied'] = ! empty($in['ssn_applied']);
    if (($why = tf_ssn($in, $p, $p['ssn_applied'])) !== null) {
        return [$why, []];
    }
    $p['filing_status'] = (string) ($in['filing_status'] ?? '');
    if (! isset(tf_filing_statuses()[$p['filing_status']])) {
        return [t('Choose your filing status (Step 1c).'), []];
    }
    $p['exempt'] = ! empty($in['exempt']);
    $p['two_jobs'] = ! $p['exempt'] && ! empty($in['two_jobs']);
    $p['children'] = $p['exempt'] ? 0 : (int) tf_digits($in['children'] ?? '0');
    $p['other_dependents'] = $p['exempt'] ? 0 : (int) tf_digits($in['other_dependents'] ?? '0');
    if ($p['children'] > 20 || $p['other_dependents'] > 20) {
        return [t('Check the number of dependents.'), []];
    }
    foreach (['other_credits', 'other_income', 'deductions', 'extra_withholding'] as $k) {
        $v = $p['exempt'] ? 0.0 : tf_money($in, $k);
        if ($v === null) {
            return [t('Amounts are in dollars, for example 1200 or 45.50.'), []];
        }
        $p[$k] = $v;
    }
    $p['step3_total'] = $p['children'] * TF_W4_CHILD + $p['other_dependents'] * TF_W4_OTHER + $p['other_credits'];
    if (($why = tf_signature($in, $p)) !== null) {
        return [$why, []];
    }

    return [null, $p];
}

/** @return array{0:?string,1:array} the refusal, or null and what the worker attested */
function tf_check_i9(array $in): array
{
    $p = [];
    if (($why = tf_identity($in, $p)) !== null) {
        return [$why, []];
    }
    $p['other_last_names'] = tf_text($in, 'other_last_names');
    $dob = (string) ($in['birth_date'] ?? '');
    if (! valid_date($dob) || $dob > date('Y-m-d', strtotime('-14 years')) || $dob < '1900-01-01') {
        return [t('Give your date of birth.'), []];
    }
    $p['birth_date'] = $dob;
    if (($why = tf_ssn($in, $p, true)) !== null) {
        return [$why, []];
    }
    $p['email'] = tf_text($in, 'email');
    if ($p['email'] !== '' && ! filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
        return [t('That email address is not valid; leave it empty if you prefer.'), []];
    }
    $p['phone'] = tf_text($in, 'phone', 30);
    $p['status'] = (string) ($in['status'] ?? '');
    if (! isset(tf_i9_statuses()[$p['status']])) {
        return [t('Tick the box that says your citizenship or immigration status.'), []];
    }
    $p['doc_type'] = $p['doc_number'] = $p['passport_country'] = $p['work_until'] = '';
    if (in_array($p['status'], ['permanent', 'authorized'], true)) {
        $p['doc_type'] = $p['status'] === 'permanent' ? 'a_number' : (string) ($in['doc_type'] ?? '');
        if (! isset(tf_i9_doc_types()[$p['doc_type']])) {
            return [t('Choose the number you give: USCIS or A-Number, Form I-94 admission number, or foreign passport number.'), []];
        }
        $num = strtoupper(preg_replace('/[\s-]/', '', (string) ($in['doc_number'] ?? '')));
        if ($p['doc_type'] === 'a_number') {
            $num = ltrim($num, 'A');
        }
        $ok = match ($p['doc_type']) {
            'a_number' => (bool) preg_match('/^\d{7,9}$/D', $num),
            'i94' => (bool) preg_match('/^[0-9A-Z]{11}$/D', $num),
            default => (bool) preg_match('/^[0-9A-Z]{5,20}$/D', $num),
        };
        if (! $ok) {
            return [t('Check the number: an A-Number has 7 to 9 digits, an I-94 admission number 11 characters.'), []];
        }
        $again = strtoupper(preg_replace('/[\s-]/', '', (string) ($in['doc_number_confirm'] ?? '')));
        if (($p['doc_type'] === 'a_number' ? ltrim($again, 'A') : $again) !== $num) {
            return [t('The two document numbers differ. Type it again from your document.'), []];
        }
        $p['doc_number'] = $num;
        if ($p['doc_type'] === 'passport') {
            $p['passport_country'] = tf_text($in, 'passport_country', 60);
            if ($p['passport_country'] === '') {
                return [t('Give the country that issued the passport.'), []];
            }
        }
        if ($p['status'] === 'authorized' && empty($in['work_until_na'])) {
            $until = (string) ($in['work_until'] ?? '');
            if (! valid_date($until) || $until < date('Y-m-d')) {
                return [t('Give the date your authorization to work ends, or tick that it has none.'), []];
            }
            $p['work_until'] = $until;
        }
    }
    $p['preparer'] = null;
    if (! empty($in['used_preparer'])) {
        $prep = ['first_name' => tf_text($in, 'preparer_first'), 'last_name' => tf_text($in, 'preparer_last'), 'address' => tf_text($in, 'preparer_address', 250)];
        if ($prep['first_name'] === '' || $prep['last_name'] === '' || $prep['address'] === '' || empty($in['preparer_attest'])) {
            return [t('Give the name and address of the person who helped you, and tick their statement.'), []];
        }
        $p['preparer'] = $prep + ['statement' => TF_I9_PREPARER_ATTEST];
    }
    if (($why = tf_signature($in, $p)) !== null) {
        return [$why, []];
    }

    return [null, $p];
}

/** What the worker typed, less the secrets, to fill the form again after a refusal. */
function tf_remember(string $form, array $in): void
{
    $_SESSION['tf_old'][$form] = array_map(static fn($v) => is_string($v) ? mb_substr($v, 0, 250) : '', array_diff_key($in, array_flip([...TF_SECRET_FIELDS, '_csrf', 'signer_name', 'attest'])));
}

function tf_recall(string $form): array
{
    $old = $_SESSION['tf_old'][$form] ?? [];
    unset($_SESSION['tf_old'][$form]);

    return $old;
}

/** The worker's forms of one kind, newest first, without their content. */
function tf_history(int $candidateId, string $form): array
{
    return rows('SELECT id, form, status, ssn_last_four, signer_name, signed_at, review_note, reviewed_at FROM worker_tax_forms WHERE candidate_id = ? AND form = ? ORDER BY id DESC LIMIT 20', [$candidateId, $form]);
}

/**
 * The worker signs the form. Returns the refusal, or null.
 */
function tf_submit(int $candidateId, string $form, array $in, int $userId): ?string
{
    if (! isset(tf_forms()[$form])) {
        return t('Unknown form.');
    }
    require_once __DIR__ . '/crypto.php';
    if (! crypto_key_ready()) {
        return t('Secure document storage is not configured.');
    }
    [$why, $p] = $form === 'w4' ? tf_check_w4($in) : tf_check_i9($in);
    if ($why !== null) {
        return $why;
    }
    if (val("SELECT id FROM worker_tax_forms WHERE candidate_id = ? AND form = ? AND status = 'submitted'", [$candidateId, $form])) {
        return $form === 'w4' ? t('Your W-4 is already waiting for payroll. If it is wrong, ask payroll to send it back to you.') : t('Your I-9 is already waiting for the office. If it is wrong, ask the office to send it back to you.');
    }
    $p['statement'] = $form === 'w4' ? TF_W4_ATTEST : TF_I9_ATTEST;
    $p['signer_name'] = tf_text($in, 'signer_name');
    $p['signed_on'] = date('Y-m-d');
    $json = json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    q("INSERT INTO worker_tax_forms (candidate_id, form, encrypted_payload, payload_hash, ssn_last_four, signer_name, signed_at, signed_by, signer_ip, signer_agent)
       VALUES (?,?,?,?,?,?, NOW(), ?,?,?)",
      [$candidateId, $form, base64_encode(crypto_seal($json)), hash('sha256', $json), $p['ssn'] !== '' ? substr($p['ssn'], -4) : null, $p['signer_name'], $userId,
       mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
    $id = (int) db()->lastInsertId();
    tf_log($id, $candidateId, $userId, 'signed');
    q('INSERT IGNORE INTO employment_clearance (candidate_id) VALUES (?)', [$candidateId]);
    if ($form === 'w4') {
        q("UPDATE employment_clearance SET w4_form_id = ?, w4_status = 'submitted' WHERE candidate_id = ?", [$id, $candidateId]);
    } else {
        q("UPDATE employment_clearance SET i9_form_id = ?, i9_status = 'employee_submitted' WHERE candidate_id = ? AND i9_status <> 'employer_completed'", [$id, $candidateId]);
    }
    $short = tf_forms()[$form]['short'];
    onboarding_form_tasks($candidateId, $form, 'submitted', t(':form filled in and signed in the portal.', ['form' => $short]));
    q('INSERT INTO candidate_events (candidate_id, user_id, event_type, detail) VALUES (?,?,?,?)', [$candidateId, $userId, $short . ' signed in the portal', 'Form ' . $id . ', signed electronically']);
    $who = (string) val('SELECT full_name FROM candidates WHERE id = ?', [$candidateId]);
    notify_desks([tf_forms()[$form]['desk']], t(':name signed their :form in the portal: check it on Change requests.', ['name' => $who, 'form' => $short]), '/change-requests#tax-forms');

    return null;
}

function tf_log(int $formId, int $candidateId, int $userId, string $action): void
{
    q('INSERT INTO worker_tax_form_access (form_id, candidate_id, user_id, action) VALUES (?,?,?,?)', [$formId, $candidateId, $userId, $action]);
}

/** Whether a staff member opens this form in full. */
function tf_staff_can_open(array $f): bool
{
    if ($f['form'] === 'w4') {
        return can('payroll');
    }
    require_once __DIR__ . '/identity-docs.php';
    if (can('admin') || id_is_reader(uid())) {
        return true;
    }

    return can('recruiter') && $f['status'] === 'submitted';
}

/**
 * A form opened: its record and what was signed, checked against the hash signed; the look recorded.
 * $own: the worker opening their own form (secrets reduced to their last four).
 */
function tf_open(int $formId, int $userId, bool $own): ?array
{
    $f = row('SELECT f.*, c.full_name FROM worker_tax_forms f JOIN candidates c ON c.id = f.candidate_id WHERE f.id = ?', [$formId]);
    if (! $f) {
        return null;
    }
    require_once __DIR__ . '/crypto.php';
    $raw = base64_decode((string) $f['encrypted_payload'], true);
    $json = $raw === false ? null : crypto_open($raw);
    $f['intact'] = $json !== null && hash_equals((string) $f['payload_hash'], hash('sha256', $json));
    $f['data'] = $f['intact'] ? json_decode($json, true) : [];
    unset($f['encrypted_payload']);
    if ($own) {
        foreach (['ssn', 'doc_number'] as $k) {
            if (! empty($f['data'][$k])) {
                $f['data'][$k] = '•••••' . substr((string) $f['data'][$k], -4);
            }
        }
    } else {
        tf_log($formId, (int) $f['candidate_id'], $userId, 'opened');
    }

    return $f;
}

/**
 * Payroll (a W-4) or the office (an I-9) accepts the form, saying it was processed, or sends it back with
 * the reason. Nobody decides a form they signed. Returns the refusal, or null.
 */
function tf_decide(int $formId, string $decision, string $note, int $userId, bool $processed): ?string
{
    $f = row("SELECT * FROM worker_tax_forms WHERE id = ? FOR UPDATE", [$formId]);
    if (! $f || $f['status'] !== 'submitted') {
        return t('That form has already been decided.');
    }
    if (! tf_staff_can_open($f) || ! can(tf_forms()[$f['form']]['desk'])) {
        return t('This form is checked by :desk.', ['desk' => $f['form'] === 'w4' ? t('payroll') : t('the office')]);
    }
    if ((int) $f['signed_by'] === $userId) {
        return t('Nobody checks a form they signed themselves.');
    }
    $cid = (int) $f['candidate_id'];
    $note = mb_substr(trim($note), 0, 500);
    $short = tf_forms()[$f['form']]['short'];
    if ($decision === 'return') {
        if (mb_strlen($note) < 3) {
            return t('Say what to correct, so the worker can fill the form again.');
        }
        q("UPDATE worker_tax_forms SET status = 'returned', reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?", [$userId, $note, $formId]);
        q($f['form'] === 'w4' ? "UPDATE employment_clearance SET w4_status = 'pending' WHERE candidate_id = ? AND w4_form_id = ?"
                              : "UPDATE employment_clearance SET i9_status = 'pending' WHERE candidate_id = ? AND i9_form_id = ? AND i9_status = 'employee_submitted'", [$cid, $formId]);
        onboarding_form_tasks($cid, $f['form'], 'rejected', t(':form sent back: :why', ['form' => $short, 'why' => $note]), $userId);
        notify_candidate($cid, t('Your :form was sent back to you: :why. Correct it in your portal.', ['form' => $short, 'why' => $note]), '/tax-forms?fill=' . $f['form']);
    } elseif ($decision === 'accept') {
        if (! $processed) {
            return $f['form'] === 'w4' ? t('Enter the W-4 in ADP, then tick the box to accept it.') : t('Check Section 1 is complete, then tick the box to accept it.');
        }
        q("UPDATE worker_tax_forms SET status = 'replaced' WHERE candidate_id = ? AND form = ? AND status = 'accepted'", [$cid, $f['form']]);
        q("UPDATE worker_tax_forms SET status = 'accepted', reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?", [$userId, $note !== '' ? $note : null, $formId]);
        if ($f['form'] === 'w4') {
            q("UPDATE employment_clearance SET w4_status = 'reviewed' WHERE candidate_id = ? AND w4_form_id = ?", [$cid, $formId]);
        }
        onboarding_form_tasks($cid, $f['form'], 'approved', t(':form accepted.', ['form' => $short]), $userId);
        notify_candidate($cid, t('Your :form was checked and accepted.', ['form' => $short]), '/portal#onboarding');
    } else {
        return t('Unknown action.');
    }
    tf_log($formId, $cid, $userId, $decision === 'accept' ? 'accepted' : 'sent back');

    return null;
}

/** The forms waiting for this staff member: W-4s for payroll, I-9s for the office. */
function tf_waiting(): array
{
    $forms = array_values(array_filter(['w4', 'i9'], static fn($f) => can(tf_forms()[$f]['desk'])));
    if (! $forms) {
        return [];
    }

    return rows("SELECT f.id, f.form, f.candidate_id, f.ssn_last_four, f.signed_at, f.signer_name, c.full_name
                 FROM worker_tax_forms f JOIN candidates c ON c.id = f.candidate_id
                 WHERE f.status = 'submitted' AND f.form IN (" . implode(',', array_fill(0, count($forms), '?')) . ') ORDER BY f.id', $forms);
}
