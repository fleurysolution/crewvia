<?php
/**
 * P5-M53: the offer letter, the candidate's answer, and the office's countersignature.
 *
 * The owner, 15 Oct 2026: an offer must come with what is offered - the job,
 * its description, the conditions - not only a few lines of terms. The
 * candidate may accept or decline it. Accepting is their signature; it goes
 * back to the office, which reviews it and countersigns, and only then is the
 * person hired (placement, onboarding). Before, the candidate's signature
 * hired them on the spot.
 *
 * Offers issued through the contract workflow (Contracts & signatures) keep
 * their own signing steps.
 */

declare(strict_types=1);

require_once __DIR__ . '/placement-rates.php';
require_once __DIR__ . '/notices.php';
require_once __DIR__ . '/reviews.php';

/** The agency's general conditions printed on every offer letter (Settings), or ''. */
function offer_conditions(): string
{
    return trim((string) val("SELECT setting_value FROM platform_settings WHERE setting_key = 'offer_conditions'"));
}

/**
 * Everything the offer letter shows, for an application at Offered.
 *
 * @return array{agency:string,candidate:string,position:string,site:string,terms:list<string>,description:string,note:string,conditions:string,text:string}
 */
function offer_letter(int $applicationId): array
{
    $a = row('SELECT a.vacancy_id, c.full_name, v.title, v.description, j.title AS site
              FROM applications a JOIN candidates c ON c.id = a.candidate_id JOIN vacancies v ON v.id = a.vacancy_id JOIN jobs j ON j.id = v.job_id
              WHERE a.id = ?', [$applicationId]) ?: [];
    $note = trim((string) val("SELECT note FROM application_events WHERE application_id = ? AND stage = 'offered' ORDER BY id DESC LIMIT 1", [$applicationId]));
    $letter = [
        'agency'      => agency_name(),
        'candidate'   => (string) ($a['full_name'] ?? ''),
        'position'    => (string) ($a['title'] ?? ''),
        'site'        => (string) ($a['site'] ?? ''),
        'terms'       => $a ? offer_terms((int) $a['vacancy_id']) : [],
        'description' => trim((string) ($a['description'] ?? '')),
        'note'        => $note,
        'conditions'  => offer_conditions(),
    ];
    // What is signed is what was shown, in this order.
    $parts = [t('Offer of employment') . ': ' . $letter['position'] . ' · ' . $letter['site'] . ' (' . $letter['agency'] . ')', implode("\n", $letter['terms'])];
    if ($letter['note'] !== '') { $parts[] = t('From recruiting:') . ' ' . $letter['note']; }
    if ($letter['description'] !== '') { $parts[] = t('The job') . ":\n" . $letter['description']; }
    if ($letter['conditions'] !== '') { $parts[] = t('General conditions') . ":\n" . $letter['conditions']; }
    $letter['text'] = trim(implode("\n\n", array_filter($parts, static fn($p) => trim($p) !== '')));

    return $letter;
}

/** The candidate's signature waiting for the office's countersignature, or null. */
function offer_pending_countersign(int $applicationId): ?array
{
    return row("SELECT o.*, a.stage FROM offer_acceptances o JOIN applications a ON a.id = o.application_id
                WHERE o.application_id = ? AND o.countersigned_at IS NULL AND a.stage = 'offered'", [$applicationId]);
}

/** Where the office handles this application. */
function offer_hiring_link(int $applicationId): string
{
    return '/hiring?v=' . (int) val('SELECT vacancy_id FROM applications WHERE id = ?', [$applicationId]) . '&app=' . $applicationId;
}

/**
 * The candidate signs the offer shown to them. Returns why not, or null.
 * The application stays at Offered until the office countersigns.
 */
function offer_sign(int $applicationId, int $candidateId, int $userId, string $name, bool $consent): ?string
{
    $name = trim($name);
    $a = row("SELECT a.id, c.full_name, v.title FROM applications a JOIN candidates c ON c.id = a.candidate_id JOIN vacancies v ON v.id = a.vacancy_id
              WHERE a.id = ? AND a.candidate_id = ? AND a.stage = 'offered' FOR UPDATE", [$applicationId, $candidateId]);
    if (! $a) { return t('This offer is no longer open.'); }
    if (row('SELECT application_id FROM offer_acceptances WHERE application_id = ?', [$applicationId])) { return t('You have already signed this offer. The office is countersigning it.'); }
    $text = offer_letter($applicationId)['text'];
    if ($text === '' || $name === '' || mb_strlen($name) > 190 || ! $consent) { return t('Offer terms, your signature and consent are required.'); }
    $hash = hash('sha256', $text);
    q('INSERT INTO offer_acceptances (application_id, user_id, offer_text, offer_hash, signer_name) VALUES (?,?,?,?,?)', [$applicationId, $userId, $text, $hash, $name]);
    q('INSERT INTO application_events (application_id, user_id, stage, note) VALUES (?,?,?,?)',
      [$applicationId, $userId, 'offered', 'Offer signed by the candidate (' . $name . '), waiting for the countersignature. Hash ' . $hash]);
    // P5-M54: committed to this job, a candidate (not an employee yet) is no longer in the running for the others.
    // An employee already working with us may hold applications for their next assignments; those stay.
    $others = candidate_is_employee($candidateId) ? 0 : offer_withdraw_others($applicationId, $candidateId, $userId, (string) $a['title']);
    notify_desks(['recruiter'], t(':name signed the offer for :job: review it and countersign it.', ['name' => $a['full_name'], 'job' => $a['title']])
        . ($others ? ' ' . tn($others, 'Their other application is withdrawn.', 'Their :n other applications are withdrawn.') : ''), offer_hiring_link($applicationId));

    return null;
}

/**
 * P5-M54: the candidate signed an offer; their other open applications are withdrawn, each saying why,
 * with the manager's reviews and the interviews still open on them closed. Returns how many.
 */
function offer_withdraw_others(int $applicationId, int $candidateId, int $by, string $job): int
{
    $others = rows("SELECT id FROM applications WHERE candidate_id = ? AND id <> ? AND stage IN ('new','screening','interview','offered') FOR UPDATE", [$candidateId, $applicationId]);
    $why = t('Withdrawn: the candidate signed the offer for :job.', ['job' => $job]);
    foreach ($others as $o) {
        q("UPDATE applications SET stage = 'withdrawn' WHERE id = ?", [(int) $o['id']]);
        candidate_follow_application((int) $o['id']);
        q('INSERT INTO application_events (application_id, user_id, stage, note) VALUES (?,?,?,?)', [(int) $o['id'], $by, 'withdrawn', $why]);
        review_close_for_application((int) $o['id'], $by, $why);
    }

    return count($others);
}

/** The candidate declines the offer. Returns why not, or null. */
function offer_decline(int $applicationId, int $candidateId, int $userId, string $reason): ?string
{
    $reason = mb_substr(trim($reason), 0, 2000);
    $a = row("SELECT a.id, c.full_name, v.title FROM applications a JOIN candidates c ON c.id = a.candidate_id JOIN vacancies v ON v.id = a.vacancy_id
              WHERE a.id = ? AND a.candidate_id = ? AND a.stage = 'offered' FOR UPDATE", [$applicationId, $candidateId]);
    if (! $a) { return t('This offer is no longer open.'); }
    if (row('SELECT application_id FROM offer_acceptances WHERE application_id = ?', [$applicationId])) { return t('You have already signed this offer. Contact the office to change it.'); }
    q("UPDATE applications SET stage = 'withdrawn' WHERE id = ?", [$applicationId]);
    candidate_follow_application($applicationId);
    q('INSERT INTO application_events (application_id, user_id, stage, note) VALUES (?,?,?,?)',
      [$applicationId, $userId, 'withdrawn', 'Offer declined by the candidate.' . ($reason !== '' ? ' ' . $reason : '')]);
    review_close_for_application($applicationId, $userId, t('The candidate declined the offer.'));
    notify_desks(['recruiter'], t(':name declined the offer for :job.', ['name' => $a['full_name'], 'job' => $a['title']]) . ($reason !== '' ? ' ' . $reason : ''), offer_hiring_link($applicationId));

    return null;
}

/**
 * The office countersigns the candidate's signed offer: the person is hired,
 * the assignment and its onboarding start, and they are told. Returns why not, or null.
 */
function offer_countersign(int $applicationId, int $by, string $name, bool $reviewed): ?string
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 190 || ! $reviewed) { return t('Read the signed offer, tick the box and sign with your name.'); }
    db()->beginTransaction();
    $a = row("SELECT a.*, v.job_id, v.title FROM applications a JOIN vacancies v ON v.id = a.vacancy_id WHERE a.id = ? FOR UPDATE", [$applicationId]);
    $signed = $a ? offer_pending_countersign($applicationId) : null;
    if (! $a || ! $signed) { db()->rollBack(); return t('There is no signed offer waiting for a countersignature here.'); }
    q('UPDATE offer_acceptances SET countersigned_by = ?, countersigned_at = NOW(), countersigner_name = ? WHERE application_id = ?', [$by, $name, $applicationId]);
    q("UPDATE applications SET stage = 'accepted' WHERE id = ?", [$applicationId]);
    candidate_follow_application($applicationId);
    q('INSERT INTO application_events (application_id, user_id, stage, note) VALUES (?,?,?,?)',
      [$applicationId, $by, 'accepted', 'Offer countersigned by ' . $name . '. Signed offer hash ' . $signed['offer_hash']]);
    $candidateId = (int) $a['candidate_id'];
    $jobId = (int) $a['job_id'];
    review_close_for_application($applicationId, $by, t('Hired: the offer is signed and countersigned.'));
    $assignment = row("SELECT id FROM placements WHERE candidate_id = ? AND job_id = ? AND status NOT IN ('completed','cancelled')", [$candidateId, $jobId]);
    if (! $assignment) {
        $rates = placement_rates($jobId, (int) ($a['vacancy_id'] ?? 0) ?: null);
        q("INSERT INTO placements (candidate_id, job_id, vacancy_id, order_line_id, status, created_by, pay_rate, bill_rate, per_diem_rate, guarantee_hours, start_date) VALUES (?,?,?,?,'offered',?,?,?,?,?,?)",
          [$candidateId, $jobId, $rates['vacancy_id'], $rates['order_line_id'], $by, $rates['pay_rate'], $rates['bill_rate'], $rates['per_diem_rate'], $rates['guarantee_hours'], offer_start_date((int) $rates['vacancy_id'])]);
        $assignment = ['id' => (int) db()->lastInsertId()];
        require_once __DIR__ . '/procurement.php';
        procurement_request_lodging_for((int) $assignment['id']);
    }
    q('INSERT IGNORE INTO onboarding_tasks (placement_id, requirement_id) SELECT ?, id FROM onboarding_requirements WHERE job_id = ?', [$assignment['id'], $jobId]);
    notify_desks(['recruiter'], t('Offer countersigned; onboarding started'), '/onboarding', $by);
    candidate_tell($candidateId, t('you are hired for :job', ['job' => $a['title']]),
                   t('Your offer for :job is countersigned: you are hired. Your next steps are in your portal.', ['job' => $a['title']]), '/portal');
    log_activity('countersigned an offer', 'application', $applicationId, (string) $a['title']);
    db()->commit();

    return null;
}
