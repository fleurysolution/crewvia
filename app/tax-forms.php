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
const TF_W4_YEAR = 2026;
const TF_W4_CHILD = 2200;
/** The states and places of the official I-9's State list (also those of the W-4's address). */
const TF_STATES = ['AK','AL','AR','AS','AZ','CA','CO','CT','DC','DE','FL','GA','GU','HI','IA','ID','IL','IN','KS','KY','LA','MA','MD','ME','MI','MN','MO','MP','MS','MT','NC','ND','NE','NH','NJ','NM','NV','NY','OH','OK','OR','PA','PR','RI','SC','SD','TN','TX','UT','VA','VI','VT','WA','WI','WV','WY','CAN','MEX'];
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
    return ['single' => t('Single or Married filing separately'), 'joint' => t('Married filing jointly or Qualifying surviving spouse'),
            'head' => t('Head of household (Check only if you\'re unmarried and pay more than half the costs of keeping up a home for yourself and a qualifying individual.)')];
}

/** P5-M64c: word for word as the boxes of Form I-9, edition 01/20/25. */
function tf_i9_statuses(): array
{
    return ['citizen' => t('1. A citizen of the United States'), 'national' => t('2. A noncitizen national of the United States (See Instructions.)'),
            'permanent' => t('3. A lawful permanent resident (Enter USCIS or A-Number.)'), 'authorized' => t('4. An alien authorized to work until (exp. date, if any)')];
}

/** P5-M64c: the official blank forms, kept as published (public/assets/forms), that the signed data fills. */
const TF_PDF = ['w4' => ['/assets/forms/fw4-2026.pdf', 'Form W-4 (2026), Created 12/8/25'], 'i9' => ['/assets/forms/i-9-01-20-25.pdf', 'Form I-9, Edition 01/20/25, expires 05/31/2027']];

/** The I-9 Section 2 certification, word for word. */
const TF_I9_S2_ATTEST = 'I attest, under penalty of perjury, that (1) I have examined the documentation presented by the above-named employee, (2) the above-listed documentation appears to be genuine and to relate to the employee named, and (3) to the best of my knowledge, the employee is authorized to work in the United States.';

/** The documents Section 2 accepts (Form I-9, Lists of Acceptable Documents), offered as suggestions. */
function tf_i9_documents(): array
{
    return [
        'A' => ['U.S. Passport', 'U.S. Passport Card', 'Permanent Resident Card (Form I-551)', 'Foreign passport with temporary I-551 stamp or MRIV',
                'Employment Authorization Document (Form I-766)', 'Foreign passport with Form I-94 or I-94A', 'Passport of the FSM or RMI with Form I-94 or I-94A'],
        'B' => ['Driver\'s license issued by a State or outlying possession of the U.S.', 'ID card issued by a State or outlying possession of the U.S.',
                'ID card issued by federal, state or local government agency', 'School ID card with a photograph', 'Voter\'s registration card', 'U.S. Military card or draft record',
                'Military dependent\'s ID card', 'U.S. Coast Guard Merchant Mariner Card', 'Native American tribal document', 'Driver\'s license issued by a Canadian government authority'],
        'C' => ['U.S. Social Security account number card (unrestricted)', 'Certification of report of birth (Form DS-1350, FS-545 or FS-240)',
                'Original or certified copy of birth certificate', 'Native American tribal document', 'U.S. Citizen ID Card (Form I-197)',
                'Identification Card for Use of Resident Citizen in the United States (Form I-179)', 'Employment authorization document issued by DHS'],
    ];
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
    $p['state'] = strtoupper(tf_text($in, 'state', 3));
    if (! in_array($p['state'], TF_STATES, true)) {
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
    $p = ['tax_year' => TF_W4_YEAR];
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
        // Supplement A: the preparer's name and address; they sign it in ink ("This field cannot be signed electronically").
        $prep = ['last_name' => tf_text($in, 'preparer_last'), 'first_name' => tf_text($in, 'preparer_first'),
                 'middle_initial' => mb_strtoupper(mb_substr(tf_text($in, 'preparer_mi', 1), 0, 1)), 'address' => tf_text($in, 'preparer_address', 190),
                 'city' => tf_text($in, 'preparer_city'), 'state' => strtoupper(tf_text($in, 'preparer_state', 3)), 'zip' => tf_text($in, 'preparer_zip', 10)];
        if ($prep['first_name'] === '' || $prep['last_name'] === '' || $prep['address'] === '' || $prep['city'] === ''
            || ! in_array($prep['state'], TF_STATES, true) || ! preg_match('/^\d{5}(-?\d{4})?$/D', $prep['zip']) || empty($in['preparer_attest'])) {
            return [t('Give the name and full address of the person who helped you, and tick that they will sign Supplement A in ink.'), []];
        }
        $p['preparer'] = $prep + ['statement' => TF_I9_PREPARER_ATTEST, 'signs_in_ink' => true];
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
    // P5-M64c: the employer's part (W-4 "Employers Only", I-9 Section 2), once accepted.
    $f['employer'] = tf_employer_part($f);
    unset($f['encrypted_payload'], $f['encrypted_employer']);
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
function tf_decide(int $formId, string $decision, string $note, int $userId, bool $processed, array $employer = []): ?string
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
            return $f['form'] === 'w4' ? t('Enter the W-4 in ADP, then tick the box to accept it.') : t('Examine the original documents, complete Section 2, then tick the certification to accept it.');
        }
        // P5-M64c: the employer's part of the official form - the W-4's "Employers Only", Section 2 of the I-9.
        [$why, $emp] = $f['form'] === 'w4' ? tf_check_w4_employer($employer) : tf_check_i9_section2($employer);
        if ($why !== null) {
            return $why;
        }
        require_once __DIR__ . '/crypto.php';
        q("UPDATE worker_tax_forms SET status = 'replaced' WHERE candidate_id = ? AND form = ? AND status = 'accepted'", [$cid, $f['form']]);
        q("UPDATE worker_tax_forms SET status = 'accepted', reviewed_by = ?, reviewed_at = NOW(), review_note = ?, encrypted_employer = ? WHERE id = ?",
          [$userId, $note !== '' ? $note : null, base64_encode(crypto_seal(json_encode($emp, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))), $formId]);
        if ($f['form'] === 'w4') {
            q("UPDATE employment_clearance SET w4_status = 'reviewed' WHERE candidate_id = ? AND w4_form_id = ?", [$cid, $formId]);
            tf_remember_employer(['tf_employer_name_address' => $emp['name_address'], 'tf_employer_ein' => $emp['ein']]);
        } else {
            q("UPDATE employment_clearance SET first_day = ?, review_due_on = ?, verifier_name = ?, verification_method = 'in_person', verified_by = ?, verified_at = NOW(), i9_status = 'employer_completed'
               WHERE candidate_id = ? AND i9_form_id = ?", [$emp['first_day'], tf_business_days_after($emp['first_day'], 3), $emp['rep_name_title'], $userId, $cid, $formId]);
            tf_remember_employer(['tf_employer_business_name' => $emp['business_name'], 'tf_employer_business_address' => $emp['business_address']]);
        }
        onboarding_form_tasks($cid, $f['form'], 'approved', t(':form accepted.', ['form' => $short]), $userId);
        notify_candidate($cid, t('Your :form was checked and accepted.', ['form' => $short]), '/portal#onboarding');
    } else {
        return t('Unknown action.');
    }
    tf_log($formId, $cid, $userId, $decision === 'accept' ? 'accepted' : 'sent back');

    return null;
}

/** The W-4's "Employers Only": the employer's name and address, the first date of employment, the EIN. */
function tf_check_w4_employer(array $in): array
{
    $e = ['name_address' => tf_text($in, 'employer_name_address', 250), 'first_day' => (string) ($in['first_day'] ?? ''), 'ein' => tf_digits($in['ein'] ?? '')];
    if (mb_strlen($e['name_address']) < 5) {
        return [t('Give the employer\'s name and address, as on the W-4 ("Employers Only").'), []];
    }
    if (! valid_date($e['first_day'])) {
        return [t('Give the first date of employment.'), []];
    }
    if (strlen($e['ein']) !== 9) {
        return [t('The employer identification number (EIN) has nine digits, for example 12-3456789.'), []];
    }
    $e['ein'] = substr($e['ein'], 0, 2) . '-' . substr($e['ein'], 2);

    return [null, $e];
}

/**
 * Section 2 of the I-9, completed by the office with the person and their original documents in front of
 * it: one document from List A, or one from List B and one from List C; the first day; who examined them,
 * their typed signature and the certification, word for word; the business name and address.
 */
function tf_check_i9_section2(array $in): array
{
    $doc = static fn(string $list, int $n) => ['title' => tf_text($in, "s2_{$list}{$n}_title"), 'authority' => tf_text($in, "s2_{$list}{$n}_authority"),
                                               'number' => tf_text($in, "s2_{$list}{$n}_number", 60), 'expires' => tf_text($in, "s2_{$list}{$n}_expires", 10)];
    $e = ['list_a' => array_values(array_filter([$doc('a', 1), $doc('a', 2), $doc('a', 3)], static fn($d) => $d['title'] !== '')),
          'list_b' => $doc('b', 1), 'list_c' => $doc('c', 1)];
    $hasA = (bool) $e['list_a'];
    $hasBC = $e['list_b']['title'] !== '' && $e['list_c']['title'] !== '';
    $anyBC = $e['list_b']['title'] !== '' || $e['list_c']['title'] !== '';
    if (! (($hasA && ! $anyBC) || (! $hasA && $hasBC))) {
        return [t('Section 2 takes one document from List A, or one from List B and one from List C, never both.'), []];
    }
    foreach ($hasA ? $e['list_a'] : [$e['list_b'], $e['list_c']] as $d) {
        if ($d['authority'] === '') {
            return [t('Give the issuing authority of each document.'), []];
        }
        if ($d['expires'] !== '' && ! valid_date($d['expires'])) {
            return [t('An expiration date is a date, or left empty when the document has none.'), []];
        }
    }
    $e += ['additional' => tf_text($in, 's2_additional', 1000), 'alternative' => ! empty($in['s2_alternative']), 'first_day' => (string) ($in['first_day'] ?? ''),
           'rep_name_title' => tf_text($in, 's2_rep_name_title'), 'signature' => tf_text($in, 's2_signature'), 'business_name' => tf_text($in, 's2_business_name'),
           'business_address' => tf_text($in, 's2_business_address', 250), 'signed_on' => date('Y-m-d'), 'statement' => TF_I9_S2_ATTEST];
    if (! valid_date($e['first_day'])) {
        return [t('Give the first day of employment.'), []];
    }
    if ($e['rep_name_title'] === '' || mb_strlen($e['signature']) < 3 || $e['business_name'] === '' || mb_strlen($e['business_address']) < 5) {
        return [t('Give your last name, first name and title, sign by typing your name, and give the business name and address.'), []];
    }

    return [null, $e];
}

/** The date $n business days (Monday to Friday) after $day: Section 2 is due within three of the first day. */
function tf_business_days_after(string $day, int $n): string
{
    $t = strtotime($day);
    while ($n > 0) {
        $t = strtotime('+1 day', $t);
        if ((int) date('N', $t) <= 5) {
            $n--;
        }
    }

    return date('Y-m-d', $t);
}

/** What the employer's part said last, to fill the next one the same way. */
function tf_remember_employer(array $settings): void
{
    foreach ($settings as $k => $v) {
        q('INSERT INTO platform_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$k, (string) $v]);
    }
}

function tf_employer_defaults(): array
{
    $s = array_column(rows("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key LIKE 'tf\\_employer\\_%'"), 'setting_value', 'setting_key');

    return ['name_address' => $s['tf_employer_name_address'] ?? agency_name(), 'ein' => $s['tf_employer_ein'] ?? '',
            'business_name' => $s['tf_employer_business_name'] ?? agency_name(), 'business_address' => $s['tf_employer_business_address'] ?? ''];
}

/** The employer's part of an accepted form, opened with it. */
function tf_employer_part(array $row): array
{
    if (empty($row['encrypted_employer'])) {
        return [];
    }
    require_once __DIR__ . '/crypto.php';
    $json = crypto_open((string) base64_decode((string) $row['encrypted_employer'], true));

    return $json !== null ? (json_decode($json, true) ?: []) : [];
}

/**
 * P5-M64c: the official form filled from what was signed: the published PDF's own fields (names as in the
 * PDF), the boxes to tick, the choices, and the typed signatures drawn where the form has no field for them.
 * $mask: the worker's own copy, the SSN and document numbers reduced to their last four.
 */
function tf_pdf_map(array $tf, array $emp, bool $mask): array
{
    $d = $tf['data'];
    $md = static fn(?string $ymd) => $ymd ? date('m/d/Y', strtotime($ymd)) : '';
    $money = static fn($v) => (float) $v > 0 ? number_format((float) $v, 2) : '';
    $last4 = static fn(string $v) => substr(preg_replace('/\D/', '', $v) ?: $v, -4);
    $ssn = (string) ($d['ssn'] ?? '');
    $signedOn = $md($d['signed_on'] ?? substr((string) $tf['signed_at'], 0, 10));
    $sig = '/s/ ' . $tf['signer_name'] . ' (signed electronically)';
    if ($tf['form'] === 'w4') {
        $P = 'topmostSubform[0].Page1[0].';
        $text = [
            $P . 'Step1a[0].f1_01[0]' => trim(($d['first_name'] ?? '') . ' ' . ($d['middle_initial'] ?? '')),
            $P . 'Step1a[0].f1_02[0]' => (string) ($d['last_name'] ?? ''),
            $P . 'Step1a[0].f1_03[0]' => trim(($d['address'] ?? '') . (! empty($d['apartment']) ? ', ' . $d['apartment'] : '')),
            $P . 'Step1a[0].f1_04[0]' => trim(($d['city'] ?? '') . ', ' . ($d['state'] ?? '') . ' ' . ($d['zip'] ?? '')),
            $P . 'f1_05[0]' => $ssn === '' ? (! empty($d['ssn_applied']) ? 'Applied for' : '') : ($mask ? 'XXX-XX-' . $last4($ssn) : substr($ssn, 0, 3) . '-' . substr($ssn, 3, 2) . '-' . substr($ssn, 5)),
        ];
        $checks = [];
        $checks[] = $P . 'c1_1[' . ['single' => 0, 'joint' => 1, 'head' => 2][$d['filing_status'] ?? 'single'] . ']';
        if (! empty($d['exempt'])) {
            $checks[] = $P . 'c1_3[0]';
        } else {
            if (! empty($d['two_jobs'])) {
                $checks[] = $P . 'c1_2[0]';
            }
            $text += [$P . 'Step3_ReadOrder[0].f1_06[0]' => $money((int) ($d['children'] ?? 0) * TF_W4_CHILD), $P . 'Step3_ReadOrder[0].f1_07[0]' => $money((int) ($d['other_dependents'] ?? 0) * TF_W4_OTHER),
                      $P . 'f1_08[0]' => $money($d['step3_total'] ?? 0), $P . 'f1_09[0]' => $money($d['other_income'] ?? 0), $P . 'f1_10[0]' => $money($d['deductions'] ?? 0), $P . 'f1_11[0]' => $money($d['extra_withholding'] ?? 0)];
        }
        if ($emp) {
            $text += [$P . 'f1_12[0]' => $emp['name_address'], $P . 'f1_13[0]' => $md($emp['first_day']), $P . 'f1_14[0]' => $emp['ein']];
        }

        return ['src' => TF_PDF['w4'][0], 'file' => 'W-4-2026-' . preg_replace('/[^A-Za-z0-9]+/', '-', $tf['full_name']) . '.pdf', 'text' => array_filter($text, 'strlen'),
                'checks' => $checks, 'choices' => [], 'draw_ticks' => true,
                'draw' => [['page' => 0, 'x' => 140, 'y' => 96, 'text' => $sig], ['page' => 0, 'x' => 466, 'y' => 96, 'text' => $signedOn]]];
    }
    $num = static fn(string $v) => $mask && $v !== '' ? 'XXXXX' . $last4($v) : $v;
    $text = [
        'Last Name (Family Name)' => (string) ($d['last_name'] ?? ''), 'First Name Given Name' => (string) ($d['first_name'] ?? ''),
        'Employee Middle Initial (if any)' => (string) ($d['middle_initial'] ?? ''), 'Employee Other Last Names Used (if any)' => (string) ($d['other_last_names'] ?? ''),
        'Address Street Number and Name' => (string) ($d['address'] ?? ''), 'Apt Number (if any)' => (string) ($d['apartment'] ?? ''), 'City or Town' => (string) ($d['city'] ?? ''),
        'ZIP Code' => substr((string) ($d['zip'] ?? ''), 0, 5), 'Date of Birth mmddyyyy' => $md($d['birth_date'] ?? null),
        'US Social Security Number' => $ssn !== '' ? ($mask ? 'XXXXX' . $last4($ssn) : $ssn) : '',
        'Employees E-mail Address' => (string) ($d['email'] ?? ''), 'Telephone Number' => (string) ($d['phone'] ?? ''),
        'Signature of Employee' => $sig, "Today's Date mmddyyy" => $signedOn,
    ];
    $status = $d['status'] ?? '';
    $checks = [['citizen' => 'CB_1', 'national' => 'CB_2', 'permanent' => 'CB_3', 'authorized' => 'CB_4'][$status] ?? null];
    if ($status === 'permanent') {
        $text['3 A lawful permanent resident Enter USCIS or ANumber'] = $num('A' . ($d['doc_number'] ?? ''));
    }
    if ($status === 'authorized') {
        $text['Exp Date mmddyyyy'] = ! empty($d['work_until']) ? $md($d['work_until']) : 'N/A';
        $field = ['a_number' => 'USCIS ANumber', 'i94' => 'Form I94 Admission Number', 'passport' => 'Foreign Passport Number and Country of IssuanceRow1'][$d['doc_type'] ?? ''] ?? null;
        if ($field) {
            $text[$field] = ($d['doc_type'] === 'a_number' ? 'A' : '') . $num((string) $d['doc_number']) . ($d['doc_type'] === 'passport' ? ' ' . $d['passport_country'] : '');
        }
    }
    $choices = ['State' => (string) ($d['state'] ?? '')];
    if (! empty($d['preparer'])) {
        $pr = $d['preparer'];
        $text += ['Last Name Family Name from Section 1' => (string) $d['last_name'], 'First Name Given Name from Section 1' => (string) $d['first_name'],
                  'Middle initial if any from Section 1' => (string) ($d['middle_initial'] ?? ''), 'Preparer or Translator Last Name (Family Name) 0' => $pr['last_name'],
                  'Preparer or Translator First Name (Given Name) 0' => $pr['first_name'], 'PT Middle Initial 0' => (string) ($pr['middle_initial'] ?? ''),
                  'Preparer or Translator Address (Street Number and Name) 0' => $pr['address'], 'Preparer or Translator City or Town 0' => (string) ($pr['city'] ?? ''),
                  'Zip Code 0' => substr((string) ($pr['zip'] ?? ''), 0, 5)];
        $choices['Preparer State 0'] = (string) ($pr['state'] ?? '');
    }
    if ($emp) {
        $rowA = [['Document Title 1', 'Issuing Authority 1', 'Document Number 0 (if any)', 'Expiration Date if any'],
                 ['Document Title 2 If any', 'Issuing Authority_2', 'Document Number If any_2', 'List A.  Document 2. Expiration Date (if any)'],
                 ['List A.   Document Title 3.  If any', 'List A. Document 3.  Enter Issuing Authority', 'List A.  Document 3 Number.  If any', 'Document Number if any_3']];
        $put = static function (array $names, array $doc) use (&$text, $md, $num) {
            $text[$names[0]] = $doc['title']; $text[$names[1]] = $doc['authority'];
            $text[$names[2]] = $doc['number'] !== '' ? $num($doc['number']) : 'N/A'; $text[$names[3]] = $doc['expires'] !== '' ? $md($doc['expires']) : 'N/A';
        };
        foreach ($emp['list_a'] as $i => $doc) {
            $put($rowA[$i], $doc);
        }
        if (! $emp['list_a']) {
            $put(['List B Document 1 Title', 'List B Issuing Authority 1', 'List B Document Number 1', 'List B Expiration Date 1'], $emp['list_b']);
            $put(['List C Document Title 1', 'List C Issuing Authority 1', 'List C Document Number 1', 'List C Expiration Date 1'], $emp['list_c']);
        }
        $text += ['Additional Information' => $emp['additional'], 'FirstDayEmployed mmddyyyy' => $md($emp['first_day']),
                  'Last Name First Name and Title of Employer or Authorized Representative' => $emp['rep_name_title'],
                  'Signature of Employer or AR' => '/s/ ' . $emp['signature'] . ' (signed electronically)', 'S2 Todays Date mmddyyyy' => $md($emp['signed_on']),
                  'Employers Business or Org Name' => $emp['business_name'], 'Employers Business or Org Address' => $emp['business_address']];
        if (! empty($emp['alternative'])) {
            $checks[] = 'CB_Alt';
        }
    }

    return ['src' => TF_PDF['i9'][0], 'file' => 'I-9-' . preg_replace('/[^A-Za-z0-9]+/', '-', $tf['full_name']) . '.pdf', 'text' => array_filter($text, 'strlen'),
            'checks' => array_values(array_filter($checks)), 'choices' => array_filter($choices, 'strlen'), 'draw_ticks' => false, 'draw' => []];
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
