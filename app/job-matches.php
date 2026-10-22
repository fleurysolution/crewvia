<?php
/**
 * Jobs for you (T10, R18): the open jobs a worker's file fits, and
 * "I'm interested".
 *
 * A job is offered when it is open on a running project and its posting has
 * not expired (the same rule as the public job board), and the worker's
 * file fits what the requisition asks:
 *   - its trade (discipline), when the requisition names one ("other"
 *     on a requisition means any trade; on a person, a trade not yet known);
 *   - its years of experience, when it asks for a number.
 * What the file does not say counts in the worker's favour and is shown.
 * Nobody on the do-not-rehire register is offered anything; the page says
 * nothing about the register.
 *
 * "I'm interested" is an application like any other, from source "Worker
 * portal", with the job's screening questions answered and scored: it
 * arrives in the recruiter's Hiring workspace as a new applicant. While it
 * is still new, the worker can take it back.
 */

declare(strict_types=1);

require_once __DIR__ . '/screening.php';
require_once __DIR__ . '/geo.php';

const JM_SOURCE = 'Worker portal';

/** The worker's own crew record, or null. */
function jm_worker(): ?array
{
    return row('SELECT c.*, ep.rehire_status FROM worker_accounts w JOIN candidates c ON c.id = w.candidate_id
                LEFT JOIN employee_profiles ep ON ep.candidate_id = c.id WHERE w.user_id = ?', [uid()]) ?: null;
}

/** Open jobs, as the public job board lists them; one when $vacancyId is given. */
function jm_open_jobs(?int $vacancyId = null): array
{
    return rows("SELECT v.id, v.job_id, v.title, v.description, v.discipline, v.openings, v.shift, v.degree, v.years_experience, v.starts_on,
                        v.requirements, COALESCE(v.pay_rate, l.pay_rate) AS pay_rate, COALESCE(v.per_diem_rate, l.per_diem_rate) AS per_diem_rate,
                        j.site_city, j.site_state, j.site_zip, j.starts_on AS job_starts, j.lodging_provided, j.travel_provided
                 FROM vacancies v
                 JOIN jobs j ON j.id = v.job_id
                 LEFT JOIN job_order_lines l ON l.id = v.order_line_id
                 LEFT JOIN requisition_publication r ON r.vacancy_id = v.id
                 WHERE v.is_open = 1 AND v.posting_status = 'published' AND j.status <> 'closed' AND (r.expires_on IS NULL OR r.expires_on >= CURDATE())"
                 . ($vacancyId ? ' AND v.id = ' . (int) $vacancyId : '') . '
                 GROUP BY v.id ORDER BY COALESCE(v.starts_on, j.starts_on) IS NULL, COALESCE(v.starts_on, j.starts_on), v.id DESC LIMIT 200');
}

/**
 * Whether the worker's file fits a job.
 * @return array{fits:bool, notes:string[]} notes say what the file leaves open
 */
function jm_fit(array $worker, array $job): array
{
    $notes = [];
    $trade = (string) ($job['discipline'] ?? '');
    if ($trade !== '' && $trade !== 'other') {
        if (in_array((string) ($worker['discipline'] ?? ''), ['', 'other'], true)) {
            $notes[] = t('Your trade is not on your file yet.');
        } elseif ($worker['discipline'] !== $trade) {
            return ['fits' => false, 'notes' => []];
        }
    }
    $years = (int) ($job['years_experience'] ?? 0);
    if ($years > 0) {
        if ($worker['years_exp'] === null) {
            $notes[] = t('Your years of experience are not on your file yet.');
        } elseif ((int) $worker['years_exp'] < $years) {
            return ['fits' => false, 'notes' => []];
        }
    }

    return ['fits' => true, 'notes' => $notes];
}

/** Whether the worker may be offered work at all. */
function jm_may_be_offered(array $worker): bool
{
    return ! in_array((string) ($worker['rehire_status'] ?? ''), ['ineligible', 'do_not_use'], true);
}

/**
 * The jobs for this worker: each fitting open job with its notes, distance,
 * questions, and the worker's application to it if there is one.
 */
function jm_jobs_for(array $worker): array
{
    if (! jm_may_be_offered($worker)) {
        return [];
    }
    geo_refresh_candidates();
    $here = row('SELECT geo_lat, geo_lng FROM candidates WHERE id = ?', [(int) $worker['id']]);
    $mine = [];
    foreach (rows('SELECT id, vacancy_id, stage, source, created_at FROM applications WHERE candidate_id = ?', [(int) $worker['id']]) as $a) {
        $mine[(int) $a['vacancy_id']] = $a;
    }
    $out = [];
    foreach (jm_open_jobs() as $job) {
        $fit = jm_fit($worker, $job);
        if (! $fit['fits'] && ! isset($mine[(int) $job['id']])) {
            continue;
        }
        $job['notes'] = $fit['notes'];
        $job['application'] = $mine[(int) $job['id']] ?? null;
        $job['questions'] = screening_questions((int) $job['job_id'], (int) $job['id']);
        $job['miles'] = null;
        if ($here && $here['geo_lat'] !== null && ($site = geo_site($job))) {
            $job['miles'] = (int) round(GEO_EARTH_MILES * 2 * asin(sqrt(sin(deg2rad($site['lat'] - (float) $here['geo_lat']) / 2) ** 2
                + cos(deg2rad((float) $here['geo_lat'])) * cos(deg2rad($site['lat'])) * sin(deg2rad($site['lng'] - (float) $here['geo_lng']) / 2) ** 2)));
        }
        $out[] = $job;
    }

    return $out;
}

/** "I'm interested": an application from the worker portal. Returns the refusal, or null. */
function jm_interest(array $worker, int $vacancyId, array $answers): ?string
{
    $job = jm_open_jobs($vacancyId)[0] ?? null;
    if (! $job) {
        return t('That job is no longer open.');
    }
    if (! jm_may_be_offered($worker) || ! jm_fit($worker, $job)['fits']) {
        return t('This job does not fit your file. Ask your recruiter if you think it should.');
    }
    $earlier = row('SELECT id, stage FROM applications WHERE candidate_id = ? AND vacancy_id = ?', [(int) $worker['id'], $vacancyId]);
    if ($earlier && $earlier['stage'] !== 'withdrawn') {
        return t('You have already told us you are interested in this job.');
    }
    $given = [];
    foreach (screening_questions((int) $job['job_id'], $vacancyId) as $question) {
        $answer = trim((string) ($answers[(int) $question['id']] ?? ''));
        if ((int) $question['required'] && $answer === '') {
            return t('Answer every required question.');
        }
        if ($answer !== '') {
            $given[(int) $question['id']] = $answer;
        }
    }
    db()->beginTransaction();
    try {
        // Taken back earlier and wanted again: the same application, new again.
        if ($earlier) {
            $id = (int) $earlier['id'];
            q("UPDATE applications SET stage = 'new' WHERE id = ?", [$id]);
        } else {
            q('INSERT INTO applications (candidate_id, vacancy_id, source) VALUES (?,?,?)', [(int) $worker['id'], $vacancyId, JM_SOURCE]);
            $id = (int) db()->lastInsertId();
        }
        q('INSERT INTO application_events (application_id, user_id, stage, note) VALUES (?,?,?,?)', [$id, uid(), 'new', t('Interested, from the worker portal')]);
        q('INSERT INTO candidate_events (candidate_id, user_id, event_type, detail) VALUES (?,?,?,?)', [(int) $worker['id'], uid(), 'application received', 'Worker portal: ' . mb_substr((string) $job['title'], 0, 180)]);
        if ($given) {
            screening_record_answers($id, $given, uid());
        }
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) { db()->rollBack(); }
        throw $error;
    }
    log_activity('said they are interested in a job', 'application', $id, (string) $job['title']);

    return null;
}

/** "No longer interested", while the application is still new. Returns the refusal, or null. */
function jm_withdraw(array $worker, int $applicationId): ?string
{
    $a = row('SELECT id, stage FROM applications WHERE id = ? AND candidate_id = ?', [$applicationId, (int) $worker['id']]);
    if (! $a) {
        return t('Application unavailable.');
    }
    if ($a['stage'] !== 'new') {
        return t('A recruiter is already working on this application. Contact them to withdraw.');
    }
    q("UPDATE applications SET stage = 'withdrawn' WHERE id = ?", [$applicationId]);
    q('INSERT INTO application_events (application_id, user_id, stage, note) VALUES (?,?,?,?)', [$applicationId, uid(), 'withdrawn', t('No longer interested, from the worker portal')]);
    log_activity('withdrew their interest in a job', 'application', $applicationId);

    return null;
}
