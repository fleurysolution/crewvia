<?php
/**
 * P5-M33: identity documents, and the W-4 signed in person.
 *
 * Decided with the owner, 14 Oct 2026:
 *  - The scans that prove who a worker is and that they may work (passport,
 *    Social Security card, driver's license or state ID, birth or
 *    citizenship papers, permanent resident card, I-9) are restricted. They
 *    stay encrypted at rest, as every document is. Once a document is
 *    validated, only the worker, an administrator (the VPs) and the people
 *    an administrator authorizes can open it. The coordinator who scanned
 *    and checked it loses access at that moment.
 *  - A W-4 is signed in person: the coordinator shows a one-time code (or
 *    its QR code), valid 15 minutes, and the worker enters it from their own
 *    portal with the form. The record says who witnessed it, and when.
 */

declare(strict_types=1);

require_once __DIR__ . '/notices.php';

const IDENTITY_TYPE_PATTERN = '/\b(passport|birth\s*certificate|social\s*security|ssn|driver|licen[cs]e|state\s*id|id\s*card|identity|permanent\s*resident|green\s*card|i-?9|citizenship|naturali[sz]ation|visa|work\s*permit|ead)\b/i';
const SIGNING_CODE_MINUTES = 15;

/** Whether a document of this type proves who someone is or that they may work. */
function doc_is_identity(string $type): bool
{
    return (bool) preg_match(IDENTITY_TYPE_PATTERN, $type);
}

/** Whether this staff member was authorized by an administrator to open identity documents. */
function id_is_reader(int $userId): bool
{
    return (bool) val('SELECT 1 FROM identity_readers WHERE user_id = ?', [$userId]);
}

/**
 * Who may open a document. The worker: their own. A restricted (identity)
 * document: an administrator or an authorized reader always; the office
 * (recruiting) only while it waits for validation. Anything else: as before,
 * recruiting all, payroll its forms and receipts.
 */
function doc_can_open(array $doc, bool $isWorker, ?int $workerCandidate): bool
{
    if ($isWorker) {
        return (int) $doc['candidate_id'] === (int) $workerCandidate;
    }
    if ((int) ($doc['restricted'] ?? 0)) {
        if (can('admin') || id_is_reader(uid())) {
            return true;
        }

        return can('recruiter') && $doc['status'] === 'submitted';
    }
    if (can('recruiter')) {
        return true;
    }

    return (bool) row("SELECT d.id FROM worker_documents d WHERE d.id = ? AND (d.document_type IN ('W4','payroll_authorization') OR EXISTS (SELECT 1 FROM expense_claims e WHERE e.receipt_document_id = d.id))", [(int) $doc['id']]);
}

/** An administrator authorizes a staff member to open identity documents, or withdraws it. */
function id_reader_set(int $userId, bool $allowed, int $by): ?string
{
    if (! val("SELECT id FROM users WHERE id = ? AND is_active = 1 AND role NOT IN ('worker','client')", [$userId])) {
        return t('Choose an active staff member.');
    }
    if ($allowed) {
        q('INSERT IGNORE INTO identity_readers (user_id, granted_by) VALUES (?,?)', [$userId, $by]);
    } else {
        q('DELETE FROM identity_readers WHERE user_id = ?', [$userId]);
    }
    log_activity($allowed ? 'authorized a reader of identity documents' : 'withdrew a reader of identity documents', 'user', $userId);

    return null;
}

/** The coordinator makes a one-time code for the worker in front of them. Returns [code, expires], the code shown once. */
function signing_code_make(int $candidateId, int $by): array
{
    q("UPDATE signing_codes SET used_at = NOW(), outcome = 'replaced' WHERE candidate_id = ? AND purpose = 'w4' AND used_at IS NULL", [$candidateId]);
    $code = (string) random_int(10000000, 99999999);
    $expires = date('Y-m-d H:i:s', time() + SIGNING_CODE_MINUTES * 60);
    q("INSERT INTO signing_codes (candidate_id, purpose, code_hash, created_by, expires_at) VALUES (?, 'w4', ?, ?, ?)", [$candidateId, hash('sha256', $code), $by, $expires]);
    log_activity('made a W-4 signing code', 'candidate', $candidateId);

    return [$code, $expires];
}

/**
 * The worker signs their W-4 in person: the code the coordinator shows, their
 * scanned W-4, and their attestation. Returns the refusal, or null.
 */
function signing_code_use(int $candidateId, string $code, int $documentId, bool $attest, int $by): ?string
{
    require_once __DIR__ . '/security.php';
    if (! security_rate_limit('signing-code', (string) $by, 5, 900)) {
        return t('Too many tries. Ask the coordinator for a new code in 15 minutes.');
    }
    $code = preg_replace('/\D/', '', $code);
    $c = row("SELECT * FROM signing_codes WHERE candidate_id = ? AND purpose = 'w4' AND code_hash = ? AND used_at IS NULL AND expires_at > NOW()", [$candidateId, hash('sha256', (string) $code)]);
    if (! $c) {
        return t('That code is not valid. Ask the coordinator in front of you for a new one.');
    }
    if (! val("SELECT id FROM worker_documents WHERE id = ? AND candidate_id = ?", [$documentId, $candidateId])) {
        return t('Choose your scanned W-4.');
    }
    if (! $attest) {
        return t('Confirm that you completed and signed this W-4 yourself.');
    }
    if (val("SELECT w4_status FROM employment_clearance WHERE candidate_id = ?", [$candidateId]) === 'reviewed') {
        return t('Your W-4 is already reviewed. Ask payroll to change it.');
    }
    q("UPDATE signing_codes SET used_at = NOW(), outcome = 'signed' WHERE id = ?", [(int) $c['id']]);
    q("INSERT INTO employment_clearance (candidate_id) VALUES (?) ON DUPLICATE KEY UPDATE candidate_id = candidate_id", [$candidateId]);
    q("UPDATE employment_clearance SET w4_document_id = ?, w4_status = 'submitted', w4_in_person_at = NOW(), w4_witness_id = ? WHERE candidate_id = ?", [$documentId, (int) $c['created_by'], $candidateId]);
    q('INSERT INTO candidate_events (candidate_id, event_type, detail) VALUES (?,?,?)', [$candidateId, 'W-4 signed in person', 'Witnessed by user ' . (int) $c['created_by'] . ' with a one-time code']);
    $who = (string) val('SELECT full_name FROM candidates WHERE id = ?', [$candidateId]);
    notify_user((int) $c['created_by'], t(':name signed their W-4 in front of you.', ['name' => $who]), '/employment?candidate_id=' . $candidateId);

    return null;
}
