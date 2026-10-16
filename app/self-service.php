<?php
/**
 * HR self-service (R22-R24): a worker keeps their own details, somebody
 * here validates them, and only then does anything change.
 *
 *   - bank details: the worker enters them; payroll checks them against
 *     the cheque or letter and approves; only then do they replace the
 *     details in use. The worker never sees more than the last four.
 *   - personal details (name, email, phone, city, state): the worker asks;
 *     a recruiter approves; only then is the record changed. Everything
 *     else on the record is changed by staff alone.
 *   - on a later job: "nothing has changed" is a recorded answer, not a
 *     form filled in again (R23).
 *
 * One request of each kind waits at a time, so a reviewer never chooses
 * between two versions the worker sent a minute apart.
 */

declare(strict_types=1);

require_once __DIR__ . '/hr.php';
// token_encrypt() / token_decrypt() live there: bank details are encrypted with the workspace key.
require_once __DIR__ . '/gmail.php';

/** The details a worker may ask to change, in words. */
function self_service_fields(): array
{
    return ['full_name' => t('Name'), 'email' => t('Email'), 'phone' => t('Phone'), 'city' => t('City'), 'state' => t('State')];
}

/** Why bank details as typed cannot be used, or null. */
function self_bank_refusal(string $account, string $routing, string $bank): ?string
{
    if (! preg_match('/^[0-9]{4,20}$/D', $account)) {
        return t('An account number is 4 to 20 digits.');
    }

    if (! preg_match('/^[0-9]{9}$/D', $routing)) {
        return t('A routing number is exactly 9 digits.');
    }

    // The ABA checksum: a transposed digit that still has nine characters
    // is how money reaches the wrong bank.
    $d = array_map('intval', str_split($routing));
    if ((3 * ($d[0] + $d[3] + $d[6]) + 7 * ($d[1] + $d[4] + $d[7]) + ($d[2] + $d[5] + $d[8])) % 10 !== 0) {
        return t('That routing number fails its checksum. Check the digits against the cheque.');
    }

    if ($bank === '' || mb_strlen($bank) > 90) {
        return t('Name the bank.');
    }

    return null;
}

/** Notify everybody at a desk that something waits for them. */
function self_service_notify(string $role, string $message): void
{
    q("INSERT INTO notifications (user_id, message, target)
       SELECT id, ?, '/change-requests' FROM users WHERE is_active = 1 AND role IN (?, 'admin')", [$message, $role]);
}

/**
 * P5-M63: the proof that goes with new bank details - a voided cheque, or the bank's direct deposit form or
 * letter, as a photo or a PDF - so payroll can compare the numbers before they are used. Wrong account
 * numbers send pay to someone else's account or delay it; a typed number alone cannot be checked.
 * Encrypted at rest, outside the general document store (recruiters never see it), served to payroll only.
 *
 * @return array{0:?string,1:?array} an error, or null and the stored proof (storage name, type, hash)
 */
function bank_proof_store(?array $file): array
{
    global $config;
    if (! $file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [t('Attach a voided cheque or the direct deposit form or letter from your bank (photo or PDF), so payroll can check the numbers.'), null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 10 * 1024 * 1024) {
        return [t('The proof must be a photo or a PDF of up to 10 MB.'), null];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
        return [t('The proof must be a photo (JPEG or PNG) or a PDF.'), null];
    }
    require_once __DIR__ . '/crypto.php';
    if (! crypto_key_ready()) {
        return [t('Secure document storage is not configured.'), null];
    }
    $bytes = (string) file_get_contents($file['tmp_name']);
    $dir = workforce_storage_path($config) . '/bank-proofs';
    if (! is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $name = bin2hex(random_bytes(32));
    if (file_put_contents($dir . '/' . $name, crypto_seal($bytes), LOCK_EX) === false) {
        return [t('Document storage unavailable.'), null];
    }

    return [null, ['name' => $name, 'mime' => $mime, 'hash' => hash('sha256', $bytes)]];
}

/** The proof of a bank change request, opened for payroll, checked and the look recorded; null if there is none. */
function bank_proof_read(int $requestId, int $userId): ?array
{
    global $config;
    $r = row('SELECT candidate_id, proof_storage_name, proof_mime, proof_hash FROM worker_bank_change_requests WHERE id = ?', [$requestId]);
    if (! $r || ! $r['proof_storage_name']) {
        return null;
    }
    require_once __DIR__ . '/crypto.php';
    $raw = @file_get_contents(workforce_storage_path($config) . '/bank-proofs/' . $r['proof_storage_name']);
    $plain = $raw === false ? null : crypto_open($raw);
    if ($plain === null || ! hash_equals((string) $r['proof_hash'], hash('sha256', $plain))) {
        return null;
    }
    q('INSERT INTO worker_bank_access (candidate_id, user_id, action) VALUES (?,?,?)', [(int) $r['candidate_id'], $userId, 'viewed the proof']);

    return ['mime' => $r['proof_mime'], 'bytes' => $plain];
}

function self_bank_submit(int $candidateId, array $in, int $userId, bool $byStaff = false, ?array $proofFile = null): ?string
{
    $account = preg_replace('/\s+/', '', (string) ($in['account_number'] ?? ''));
    $routing = preg_replace('/\s+/', '', (string) ($in['routing_number'] ?? ''));
    $bank = trim((string) ($in['bank_name'] ?? ''));
    $holder = trim((string) ($in['account_holder'] ?? ''));

    if (($why = self_bank_refusal($account, $routing, $bank)) !== null) {
        return $why;
    }

    if (mb_strlen($holder) < 2 || mb_strlen($holder) > 190) {
        return t('Give the name on the account.');
    }
    // P5-M63: typed twice, so one wrong digit is caught before anybody checks it.
    if (preg_replace('/\s+/', '', (string) ($in['account_number_confirm'] ?? '')) !== $account) {
        return t('The two account numbers differ. Type it again, digit by digit, from the cheque or the bank letter.');
    }

    if (val("SELECT COUNT(*) FROM worker_bank_change_requests WHERE candidate_id = ? AND status = 'pending'", [$candidateId])) {
        return $byStaff ? t('Bank details for this person are already waiting to be checked on Change requests.') : t('Your last bank details are still being checked. Wait for payroll before sending new ones.');
    }

    [$why, $proof] = bank_proof_store($proofFile);
    if ($why !== null) {
        return $why;
    }
    q('INSERT INTO worker_bank_change_requests (candidate_id, encrypted_details, last_four, bank_label, submitted_by, proof_storage_name, proof_mime, proof_hash) VALUES (?,?,?,?,?,?,?,?)',
      [$candidateId, token_encrypt(['account_number' => $account, 'routing_number' => $routing, 'bank_name' => $bank, 'account_holder' => $holder]),
       substr($account, -4), $bank, $userId, $proof['name'], $proof['mime'], $proof['hash']]);
    q('INSERT INTO worker_bank_access (candidate_id, user_id, action) VALUES (?,?,?)', [$candidateId, $userId, $byStaff ? 'proposed by payroll' : 'proposed by the worker']);
    if (! $byStaff) {
        log_activity('proposed own bank details', 'candidate', $candidateId, 'ending ' . substr($account, -4));
    }
    self_service_notify('payroll', 'Bank details waiting to be checked');

    return null;
}

/** Payroll's decision on proposed bank details. The caller checks the role. */
function self_bank_decide(int $requestId, string $decision, string $note, int $userId, bool $matched = false): ?string
{
    $r = row("SELECT * FROM worker_bank_change_requests WHERE id = ? FOR UPDATE", [$requestId]);

    if (! $r || $r['status'] !== 'pending') {
        return t('Those bank details have already been decided.');
    }

    if ((int) $r['submitted_by'] === $userId) {
        return t('Nobody checks bank details they entered themselves.');
    }

    if ($decision === 'reject') {
        if (mb_strlen(trim($note)) < 3) {
            return t('Say why the bank details are refused, so the worker can correct them.');
        }
        q("UPDATE worker_bank_change_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?",
          [$userId, mb_substr(trim($note), 0, 500), $requestId]);
    } elseif ($decision === 'approve') {
        // P5-M63: accepted only by somebody who says they compared the numbers with the proof.
        if (! $matched) {
            return t('Compare the account and routing numbers with the proof, then tick the box to accept.');
        }
        q("INSERT INTO worker_bank_details (candidate_id, encrypted_details, last_four, bank_label, status, submitted_by, reviewed_by, reviewed_at)
           VALUES (?,?,?,?, 'verified', ?, ?, NOW())
           ON DUPLICATE KEY UPDATE encrypted_details = VALUES(encrypted_details), last_four = VALUES(last_four),
             bank_label = VALUES(bank_label), status = 'verified', submitted_by = VALUES(submitted_by),
             reviewed_by = VALUES(reviewed_by), reviewed_at = NOW()",
          [(int) $r['candidate_id'], $r['encrypted_details'], $r['last_four'], $r['bank_label'], (int) $r['submitted_by'], $userId]);
        q("UPDATE worker_bank_change_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?",
          [$userId, trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null, $requestId]);
    } else {
        return t('Unknown action.');
    }

    q('INSERT INTO worker_bank_access (candidate_id, user_id, action) VALUES (?,?,?)',
      [(int) $r['candidate_id'], $userId, $decision === 'approve' ? 'approved the change' : 'refused the change']);
    q('INSERT INTO notifications (user_id, message, target) VALUES (?,?,?)',
      [(int) $r['submitted_by'], $decision === 'approve' ? 'Your bank details were checked and are now in use' : 'Your bank details were refused: ' . mb_substr(trim($note), 0, 200), '/employee-folder']);

    return null;
}

/** The full proposed details, for payroll to check them - and the look is logged. */
function self_bank_reveal(int $requestId): ?array
{
    $r = row('SELECT * FROM worker_bank_change_requests WHERE id = ?', [$requestId]);

    if (! $r) {
        return null;
    }

    q('INSERT INTO worker_bank_access (candidate_id, user_id, action) VALUES (?,?,?)', [(int) $r['candidate_id'], uid(), 'revealed a proposal']);

    try {
        return $r + ['details' => token_decrypt((string) $r['encrypted_details'])];
    } catch (Throwable $e) {
        return $r + ['details' => [], 'unreadable' => true];
    }
}

function self_detail_submit(int $candidateId, string $field, string $value, string $reason, int $userId): ?string
{
    if (! array_key_exists($field, self_service_fields())) {
        return t('Choose which detail to change.');
    }

    $value = trim($value);
    $refusal = match ($field) {
        'full_name' => mb_strlen($value) < 2 || mb_strlen($value) > 190 ? t('A name is 2 to 190 characters.') : null,
        'email'     => ! filter_var($value, FILTER_VALIDATE_EMAIL) || mb_strlen($value) > 190 ? t('Give a valid email address.') : null,
        'phone'     => ! preg_match('/^[0-9 +().-]{7,40}$/', $value) ? t('Give a phone number of 7 to 40 digits and spaces.') : null,
        'city'      => mb_strlen($value) < 2 || mb_strlen($value) > 120 ? t('A city is 2 to 120 characters.') : null,
        'state'     => mb_strlen($value) < 2 || mb_strlen($value) > 40 ? t('A state is 2 to 40 characters.') : null,
    };

    if ($refusal !== null) {
        return $refusal;
    }

    $current = (string) (val('SELECT `' . $field . '` FROM candidates WHERE id = ?', [$candidateId]) ?? '');

    if ($current === $value) {
        return t('That is already what is on file.');
    }

    if (val("SELECT COUNT(*) FROM profile_change_requests WHERE candidate_id = ? AND field = ? AND status = 'pending'", [$candidateId, $field])) {
        return t('A change to this detail is already waiting to be checked.');
    }

    q('INSERT INTO profile_change_requests (candidate_id, field, old_value, new_value, reason, submitted_by) VALUES (?,?,?,?,?,?)',
      [$candidateId, $field, $current !== '' ? $current : null, $value, mb_substr(trim($reason), 0, 500) ?: null, $userId]);
    self_service_notify('recruiter', 'A change to personal details is waiting to be checked');

    return null;
}

/** A recruiter's decision on a detail change. The caller checks the role. */
function self_detail_decide(int $requestId, string $decision, string $note, int $userId): ?string
{
    $r = row('SELECT * FROM profile_change_requests WHERE id = ? FOR UPDATE', [$requestId]);

    if (! $r || $r['status'] !== 'pending') {
        return t('That change has already been decided.');
    }

    if (! array_key_exists((string) $r['field'], self_service_fields())) {
        return t('Unknown action.');
    }

    if ($decision === 'reject' && mb_strlen(trim($note)) < 3) {
        return t('Say why the change is refused.');
    }

    if ($decision === 'approve') {
        // Checked against the fixed list above before it names a column.
        q('UPDATE candidates SET `' . (string) $r['field'] . '` = ? WHERE id = ?', [$r['new_value'], (int) $r['candidate_id']]);
    } elseif ($decision !== 'reject') {
        return t('Unknown action.');
    }

    q('UPDATE profile_change_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?',
      [$decision === 'approve' ? 'approved' : 'rejected', $userId, trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null, $requestId]);
    q('INSERT INTO candidate_events (candidate_id, user_id, event_type, detail) VALUES (?,?,?,?)',
      [(int) $r['candidate_id'], $userId, 'detail change ' . ($decision === 'approve' ? 'approved' : 'refused'), $r['field'] . ': ' . $r['old_value'] . ' -> ' . $r['new_value']]);
    q('INSERT INTO notifications (user_id, message, target) VALUES (?,?,?)',
      [(int) $r['submitted_by'], $decision === 'approve' ? 'Your change to your details was accepted' : 'Your change to your details was refused: ' . mb_substr(trim($note), 0, 200), '/employee-folder']);

    return null;
}

/** "Nothing has changed since my last job" - recorded, with the job it was said for. */
function self_confirm_unchanged(int $candidateId, int $userId): void
{
    $placement = val("SELECT id FROM placements WHERE candidate_id = ? AND status NOT IN ('completed','cancelled') ORDER BY id DESC LIMIT 1", [$candidateId]);
    q('INSERT INTO details_confirmations (candidate_id, placement_id, confirmed_by) VALUES (?,?,?)', [$candidateId, $placement ?: null, $userId]);
}

/** What one person has waiting or recently decided, and when they last confirmed. */
function self_service_state(int $candidateId): array
{
    return [
        'bank'    => rows('SELECT id, last_four, bank_label, status, submitted_at, reviewed_at, review_note FROM worker_bank_change_requests
                           WHERE candidate_id = ? ORDER BY id DESC LIMIT 5', [$candidateId]),
        'details' => rows('SELECT id, field, old_value, new_value, status, submitted_at, review_note FROM profile_change_requests
                           WHERE candidate_id = ? ORDER BY id DESC LIMIT 20', [$candidateId]),
        'confirmed' => val('SELECT MAX(confirmed_at) FROM details_confirmations WHERE candidate_id = ?', [$candidateId]),
    ];
}
