<?php
/**
 * Privacy controls (P4-M06).
 *
 *   notice      the agency's privacy notice, published by an administrator
 *               in versions; Crewvia shows the current one on /privacy and
 *               links it from every public form. The wording is the
 *               agency's: Crewvia writes none of it.
 *   consent     each consent given on a public form is recorded with the
 *               notice version shown, the form, the time and the address.
 *   access      everything held about one person, as one file, for an
 *               administrator answering that person's request.
 *   erasure     an applicant who was never placed can be anonymised:
 *               name, contact details, notes and résumé text are removed
 *               and résumé files deleted. Anyone placed keeps their record:
 *               pay, tax and employment records must be kept by law.
 */

declare(strict_types=1);

function privacy_notice_current(): ?array
{
    return row('SELECT * FROM privacy_notices ORDER BY id DESC LIMIT 1') ?: null;
}

function privacy_publish(string $body): ?string
{
    $body = trim(str_replace("\r\n", "\n", $body));
    if (mb_strlen($body) < 200 || mb_strlen($body) > 60000) {
        return t('A privacy notice is 200 to 60,000 characters: who the agency is, what it keeps, why, for how long, and how to ask for it.');
    }
    $current = privacy_notice_current();
    if ($current && hash_equals($current['sha256'], hash('sha256', $body))) {
        return t('That is the notice already published.');
    }
    q('INSERT INTO privacy_notices (body, sha256, published_by) VALUES (?,?,?)', [$body, hash('sha256', $body), uid() ?: null]);

    return null;
}

/** Record a consent given on a form, against the notice shown. */
function privacy_record_consent(int $candidateId, string $purpose, string $source): void
{
    $notice = privacy_notice_current();
    q('INSERT INTO consent_records (candidate_id, purpose, source, notice_id, ip) VALUES (?,?,?,?,?)',
      [$candidateId, $purpose, $source, $notice['id'] ?? null, mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
}

/** A person by email or by number, for a request. */
function privacy_find(string $who): ?array
{
    $who = trim($who);
    if (ctype_digit($who)) {
        return row('SELECT * FROM candidates WHERE id = ?', [(int) $who]) ?: null;
    }

    return row('SELECT * FROM candidates WHERE email = ? ORDER BY id LIMIT 1', [$who]) ?: null;
}

/**
 * Everything held about one person, as plain data. Encrypted values (bank
 * details, identity documents) are listed, not decrypted: the request is
 * answered with the files themselves through the usual pages.
 */
function privacy_subject_export(int $cid): array
{
    $one = static fn(string $sql) => rows($sql, [$cid]);
    $c = row('SELECT * FROM candidates WHERE id = ?', [$cid]);
    unset($c['normalized_email'], $c['normalized_name'], $c['normalized_phone']);
    $profile = row('SELECT * FROM employee_profiles WHERE candidate_id = ?', [$cid]) ?: null;
    $bank = row('SELECT bank_label, last_four, status, reviewed_at FROM worker_bank_details WHERE candidate_id = ?', [$cid]) ?: null;
    $out = [
        'generated_at' => date('c'),
        'person' => $c,
        'employee_profile' => $profile,
        'bank_details' => $bank ? $bank + ['note' => 'Stored encrypted; only the bank and the last four digits are shown here.'] : null,
        'applications' => $one('SELECT a.id, a.stage, a.source, a.created_at, v.title AS vacancy FROM applications a JOIN vacancies v ON v.id = a.vacancy_id WHERE a.candidate_id = ? ORDER BY a.id'),
        'assignments' => $one('SELECT p.id, j.title AS project, p.status, p.start_date, p.end_date, p.pay_rate FROM placements p JOIN jobs j ON j.id = p.job_id WHERE p.candidate_id = ? ORDER BY p.id'),
        'timesheets' => $one('SELECT t.week_ending, t.hours_worked, t.paid_leave_hours, t.per_diem_days, t.expenses, t.status FROM timesheets t JOIN placements p ON p.id = t.placement_id WHERE p.candidate_id = ? ORDER BY t.week_ending'),
        'time_off' => $one('SELECT r.starts_on, r.ends_on, r.leave_type, r.status FROM time_off_requests r JOIN placements p ON p.id = r.placement_id WHERE p.candidate_id = ? ORDER BY r.id'),
        'documents' => $one('SELECT id, document_type, original_name, status, created_at FROM worker_documents WHERE candidate_id = ? ORDER BY id'),
        'resumes' => $one('SELECT id, original_name, created_at FROM candidate_resumes WHERE candidate_id = ? ORDER BY id'),
        'events' => $one('SELECT event_type, detail, created_at FROM candidate_events WHERE candidate_id = ? ORDER BY id'),
        'consents' => $one('SELECT purpose, source, notice_id, given_at FROM consent_records WHERE candidate_id = ? ORDER BY id'),
        'activity' => $one("SELECT action, detail, created_at FROM activity WHERE entity = 'candidate' AND entity_id = ? ORDER BY id"),
    ];
    foreach (['wage_advances' => 'SELECT amount, kind, status, paid_out_on FROM wage_advances WHERE candidate_id = ? ORDER BY id',
              'benefits' => 'SELECT e.status, e.starts_on, e.ends_on, p.name AS plan FROM benefit_enrollments e JOIN benefit_plans p ON p.id = e.plan_id WHERE e.candidate_id = ? ORDER BY e.id',
              'hr_requests' => 'SELECT reference, kind, subject, status, created_at FROM hr_requests WHERE candidate_id = ? ORDER BY id'] as $k => $sql) {
        try {
            $out[$k] = $one($sql);
        } catch (Throwable $e) {
            $out[$k] = [];
        }
    }

    return $out;
}

/** Why a person may not be anonymised, or null. */
function privacy_erasure_refusal(int $cid): ?string
{
    if (val("SELECT COUNT(*) FROM placements WHERE candidate_id = ? AND status NOT IN ('offered','cancelled')", [$cid])) {
        return t('This person was placed: their pay, tax and employment records must be kept. Correct their details instead.');
    }
    if (val('SELECT COUNT(*) FROM worker_accounts WHERE candidate_id = ?', [$cid])) {
        return t('This person has a worker account: switch it off on Team first.');
    }

    return null;
}

/** Anonymise an applicant who was never placed. Returns the refusal, or null. */
function privacy_anonymise(int $cid, string $reason): ?string
{
    if (mb_strlen(trim($reason)) < 5) {
        return t('Say why: for example, the person asked to be removed on a date.');
    }
    if ($why = privacy_erasure_refusal($cid)) {
        return $why;
    }
    global $config;
    require_once __DIR__ . '/tenancy.php';
    foreach (rows('SELECT storage_name FROM candidate_resumes WHERE candidate_id = ?', [$cid]) as $r) {
        $path = workforce_storage_path($config) . '/resumes/' . basename((string) $r['storage_name']);
        if (is_file($path)) {
            @unlink($path);
        }
    }
    q('DELETE FROM candidate_resumes WHERE candidate_id = ?', [$cid]);
    q("UPDATE candidates SET full_name = ?, email = ?, phone = NULL, city = NULL, state = NULL, degree = NULL, resume_text = NULL, notes = NULL,
                             normalized_email = NULL, normalized_name = NULL, normalized_phone = NULL WHERE id = ?",
      ['Removed applicant ' . $cid, 'removed-' . $cid . '@removed.invalid', $cid]);
    q('UPDATE candidate_events SET detail = ? WHERE candidate_id = ?', ['(removed with the person, ' . date('Y-m-d') . ')', $cid]);
    q('INSERT INTO candidate_events (candidate_id, user_id, event_type, detail) VALUES (?,?,?,?)', [$cid, uid() ?: null, 'anonymised', mb_substr(trim($reason), 0, 500)]);

    return null;
}
