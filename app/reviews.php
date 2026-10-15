<?php
/**
 * P5-M44: from the recruiter to the manager.
 *
 * The recruiting desk screens first. When an applicant meets the
 * requirements, the recruiter passes them to a manager with a rating of the
 * fit (1 to 5) and a recommendation in words. The manager, who need not be
 * on the recruiting desk (a supervisor, the owner), sees what the recruiter
 * saw on /reviews and decides: validate, ask for another interview, or
 * decline. An interview is scheduled with an interviewer, who records how it
 * went on a short scorecard.
 *
 * Nothing here talks to the candidate and nothing here moves an application
 * to an offer or a rejection: those stay the recruiter's, in Hiring, with
 * the manager's decision in front of them. Everybody involved is told in
 * the app (notifications), not by email.
 */

declare(strict_types=1);

/** @return array<string,string> */
function review_statuses(): array
{
    return ['pending' => t('With the manager'), 'validated' => t('Validated by the manager'), 'interview' => t('Another interview asked'),
            'declined' => t('Declined by the manager'), 'withdrawn' => t('Taken back')];
}

/** @return array<string,string> */
function interview_modes(): array
{
    return ['video' => t('Video call'), 'phone' => t('Phone'), 'in_person' => t('In person')];
}

/** @return array<string,string> */
function interview_verdicts(): array
{
    return ['hire' => t('Hire'), 'maybe' => t('Maybe'), 'no' => t('Do not hire')];
}

/** The people a candidate can be passed to, or interviewed by: active office staff, owners and supervisors. */
function review_people(): array
{
    return rows("SELECT id, name, role FROM users WHERE is_active = 1 AND role IN ('admin','recruiter','supervisor') ORDER BY role = 'recruiter', name");
}

function review_person_ok(int $userId): bool
{
    return (bool) val("SELECT id FROM users WHERE id = ? AND is_active = 1 AND role IN ('admin','recruiter','supervisor')", [$userId]);
}

/** An application still being worked: not settled, not offered yet. */
function review_application(int $applicationId): ?array
{
    return row("SELECT a.id, a.stage, a.vacancy_id, a.candidate_id, c.full_name, v.title, v.job_id FROM applications a
                JOIN candidates c ON c.id = a.candidate_id JOIN vacancies v ON v.id = a.vacancy_id WHERE a.id = ?", [$applicationId]);
}

function review_notify(int $userId, string $message, string $target): void
{
    q('INSERT INTO notifications (user_id, message, target) VALUES (?,?,?)', [$userId, mb_substr($message, 0, 255), $target]);
}

/** The recruiter passes a screened applicant to a manager. @return ?string an error */
function review_request(int $applicationId, int $reviewerId, int $rating, string $recommendation, int $by): ?string
{
    $a = review_application($applicationId);
    $recommendation = trim($recommendation);
    if (! $a) { return t('Application unavailable in this project.'); }
    if (in_array($a['stage'], ['offered', 'accepted', 'rejected', 'withdrawn'], true)) { return t('Only an application still being screened can be passed to a manager.'); }
    if (! review_person_ok($reviewerId)) { return t('Choose who reviews this applicant.'); }
    if ($reviewerId === $by) { return t('Pass the applicant to somebody else: the manager reviews what the recruiter screened.'); }
    if ($rating < 1 || $rating > 5) { return t('Rate the fit from 1 to 5.'); }
    if ($recommendation === '') { return t('Write your recommendation: why this applicant, and anything to watch.'); }
    if (val("SELECT id FROM application_reviews WHERE application_id = ? AND status = 'pending'", [$applicationId])) { return t('This applicant is already with a manager.'); }
    require_once __DIR__ . '/rehire.php';
    if (($held = rehire_refusal((int) $a['candidate_id'])) !== null) { return $held; }
    q('INSERT INTO application_reviews (application_id, requested_by, reviewer_id, rating, recommendation) VALUES (?,?,?,?,?)',
      [$applicationId, $by, $reviewerId, $rating, mb_substr($recommendation, 0, 4000)]);
    $id = (int) db()->lastInsertId();
    $reviewer = (string) val('SELECT name FROM users WHERE id = ?', [$reviewerId]);
    q('INSERT INTO application_events (application_id, user_id, stage, note) VALUES (?,?,?,?)',
      [$applicationId, $by, $a['stage'], 'Passed to ' . $reviewer . ' for review. Fit ' . $rating . '/5. ' . $recommendation]);
    review_notify($reviewerId, t(':name to review for :role', ['name' => $a['full_name'], 'role' => $a['title']]), '/reviews?id=' . $id);
    log_activity('passed an applicant to a manager', 'application', $applicationId, $reviewer);

    return null;
}

/** The recruiter takes a pending review back. @return ?string an error */
function review_withdraw(int $reviewId, int $by): ?string
{
    $r = row("SELECT * FROM application_reviews WHERE id = ? AND status = 'pending'", [$reviewId]);
    if (! $r) { return t('That review is no longer waiting.'); }
    q("UPDATE application_reviews SET status = 'withdrawn', decided_at = NOW() WHERE id = ?", [$reviewId]);
    q('INSERT INTO application_events (application_id, user_id, stage, note) VALUES (?,?,?,?)',
      [(int) $r['application_id'], $by, (string) val('SELECT stage FROM applications WHERE id = ?', [(int) $r['application_id']]), 'Taken back from the manager\'s review.']);
    review_notify((int) $r['reviewer_id'], t('A review was taken back: nothing to decide'), '/reviews');

    return null;
}

/**
 * The manager decides. Another interview can be scheduled in the same step.
 *
 * @param array<string,mixed> $interview when, duration, mode, place, interviewer (when "interview")
 * @return ?string an error
 */
function review_decide(int $reviewId, string $decision, string $note, int $by, array $interview = []): ?string
{
    $r = row("SELECT * FROM application_reviews WHERE id = ? AND status = 'pending'", [$reviewId]);
    $note = trim($note);
    if (! $r) { return t('That review is no longer waiting.'); }
    if ((int) $r['reviewer_id'] !== $by && ! can('admin')) { return t('Only the person it was passed to decides.'); }
    if (! in_array($decision, ['validated', 'interview', 'declined'], true)) { return t('Choose a decision.'); }
    if ($decision !== 'validated' && $note === '') { return t('Say why, so the recruiter knows what to do next.'); }
    $applicationId = (int) $r['application_id'];
    if ($decision === 'interview' && ! empty($interview['when'])) {
        $error = interview_schedule($applicationId, (int) ($interview['interviewer'] ?? $by), (string) $interview['when'], (int) ($interview['duration'] ?? 30),
                                    (string) ($interview['mode'] ?? 'video'), (string) ($interview['place'] ?? ''), $by);
        if ($error !== null) { return $error; }
    }
    q('UPDATE application_reviews SET status = ?, decision_note = ?, decided_at = NOW() WHERE id = ?', [$decision, $note !== '' ? mb_substr($note, 0, 4000) : null, $reviewId]);
    $who = (string) val('SELECT name FROM users WHERE id = ?', [$by]);
    $words = ['validated' => 'Validated by ', 'interview' => 'Another interview asked by ', 'declined' => 'Declined by '][$decision];
    q('INSERT INTO application_events (application_id, user_id, stage, note) VALUES (?,?,?,?)',
      [$applicationId, $by, (string) val('SELECT stage FROM applications WHERE id = ?', [$applicationId]), $words . $who . '.' . ($note !== '' ? ' ' . $note : '')]);
    $a = review_application($applicationId);
    review_notify((int) $r['requested_by'], t(':name: :decision', ['name' => (string) ($a['full_name'] ?? ''), 'decision' => review_statuses()[$decision]]),
                  '/hiring?v=' . (int) ($a['vacancy_id'] ?? 0) . '&app=' . $applicationId);
    log_activity('decided on an applicant', 'application', $applicationId, $decision);

    return null;
}

/** Schedule an interview; the application moves to Interview. @return ?string an error */
function interview_schedule(int $applicationId, int $interviewerId, string $when, int $duration, string $mode, string $place, int $by): ?string
{
    $a = review_application($applicationId);
    if (! $a) { return t('Application unavailable in this project.'); }
    if (in_array($a['stage'], ['accepted', 'rejected', 'withdrawn'], true)) { return t('That application is settled.'); }
    if (! review_person_ok($interviewerId)) { return t('Choose who interviews.'); }
    require_once __DIR__ . '/rehire.php';
    if (($held = rehire_refusal((int) $a['candidate_id'])) !== null) { return $held; }
    $at = strtotime(str_replace('T', ' ', $when));
    if (! $at || $at < time() - 3600) { return t('Choose a date and time for the interview, not in the past.'); }
    $mode = isset(interview_modes()[$mode]) ? $mode : 'video';
    $duration = max(10, min(480, $duration ?: 30));
    q('INSERT INTO interviews (application_id, interviewer_id, scheduled_at, duration_min, mode, place, created_by) VALUES (?,?,?,?,?,?,?)',
      [$applicationId, $interviewerId, date('Y-m-d H:i:00', $at), $duration, $mode, mb_substr(trim($place), 0, 255) ?: null, $by]);
    $id = (int) db()->lastInsertId();
    if (in_array($a['stage'], ['new', 'screening'], true)) {
        q("UPDATE applications SET stage = 'interview' WHERE id = ?", [$applicationId]);
        require_once __DIR__ . '/contracts.php';
        candidate_follow_application($applicationId);
    }
    q('INSERT INTO application_events (application_id, user_id, stage, note) VALUES (?,?,?,?)',
      [$applicationId, $by, 'interview', 'Interview scheduled: ' . date('Y-m-d H:i', $at) . ', ' . $duration . ' min, ' . str_replace('_', ' ', $mode) . ', with '
       . val('SELECT name FROM users WHERE id = ?', [$interviewerId]) . (trim($place) !== '' ? ' (' . trim($place) . ')' : '') . '.']);
    if ($interviewerId !== $by) {
        review_notify($interviewerId, t('Interview with :name on :date', ['name' => $a['full_name'], 'date' => date('M j, g:i a', $at)]), '/reviews?interview=' . $id);
    }
    log_activity('scheduled an interview', 'application', $applicationId, date('Y-m-d H:i', $at));

    return null;
}

/** How the interview went: done (with a rating and a verdict), no-show, or cancelled. @return ?string an error */
function interview_record(int $interviewId, string $status, int $rating, string $verdict, string $feedback, int $by): ?string
{
    $i = row("SELECT * FROM interviews WHERE id = ? AND status = 'scheduled'", [$interviewId]);
    $feedback = trim($feedback);
    if (! $i) { return t('That interview is already recorded.'); }
    if ((int) $i['interviewer_id'] !== $by && ! can('recruiter')) { return t('Only the interviewer or the recruiting desk records it.'); }
    if (! in_array($status, ['done', 'no_show', 'cancelled'], true)) { return t('Say how it went.'); }
    if ($status === 'done' && ($rating < 1 || $rating > 5 || ! isset(interview_verdicts()[$verdict]))) { return t('Rate the interview from 1 to 5 and give your verdict.'); }
    if ($status === 'done' && $feedback === '') { return t('Write a few words on the interview for the next person.'); }
    q('UPDATE interviews SET status = ?, rating = ?, verdict = ?, feedback = ?, completed_at = NOW() WHERE id = ?',
      [$status, $status === 'done' ? $rating : null, $status === 'done' ? $verdict : null, $feedback !== '' ? mb_substr($feedback, 0, 4000) : null, $interviewId]);
    $note = ['done' => 'Interview done: ' . $rating . '/5, ' . interview_verdicts()[$verdict] . '. ', 'no_show' => 'Did not come to the interview. ', 'cancelled' => 'Interview cancelled. '][$status] . $feedback;
    q('INSERT INTO application_events (application_id, user_id, stage, note) VALUES (?,?,?,?)', [(int) $i['application_id'], $by, 'interview', trim($note)]);
    if ((int) $i['created_by'] !== $by) {
        $a = review_application((int) $i['application_id']);
        review_notify((int) $i['created_by'], t('Interview recorded for :name', ['name' => (string) ($a['full_name'] ?? '')]), '/hiring?v=' . (int) ($a['vacancy_id'] ?? 0) . '&app=' . (int) $i['application_id']);
    }
    log_activity('recorded an interview', 'application', (int) $i['application_id'], $status);

    return null;
}

/** Can this person see a candidate through a review or an interview given to them? */
function review_can_see_candidate(int $userId, int $candidateId): bool
{
    return (bool) val('SELECT 1 FROM applications a WHERE a.candidate_id = ?
                       AND (EXISTS (SELECT 1 FROM application_reviews r WHERE r.application_id = a.id AND r.reviewer_id = ?)
                         OR EXISTS (SELECT 1 FROM interviews i WHERE i.application_id = a.id AND i.interviewer_id = ?)) LIMIT 1', [$candidateId, $userId, $userId]);
}

/** Everything the manager needs to decide, for one application. */
function review_dossier(int $applicationId): ?array
{
    $a = row('SELECT a.*, c.full_name, c.city, c.state, c.phone, c.email, c.degree, c.years_exp, v.title, v.job_id, j.title AS project
              FROM applications a JOIN candidates c ON c.id = a.candidate_id JOIN vacancies v ON v.id = a.vacancy_id JOIN jobs j ON j.id = v.job_id WHERE a.id = ?', [$applicationId]);
    if (! $a) { return null; }
    require_once __DIR__ . '/screening.php';
    $a['questions'] = rows('SELECT q.*, s.answer FROM screening_questions q JOIN screening_answers s ON s.question_id = q.id AND s.application_id = ? ORDER BY q.sort_order, q.id', [$applicationId]);
    $a['documents'] = rows('SELECT id, kind, original_name, created_at FROM candidate_resumes WHERE candidate_id = ? ORDER BY id DESC', [(int) $a['candidate_id']]);
    $a['notes'] = rows("SELECT e.note, e.created_at, u.name FROM application_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.application_id = ? AND e.note IS NOT NULL AND e.note <> '' ORDER BY e.id DESC LIMIT 20", [$applicationId]);
    $a['reviews'] = rows('SELECT r.*, q.name AS requester, m.name AS reviewer FROM application_reviews r JOIN users q ON q.id = r.requested_by JOIN users m ON m.id = r.reviewer_id WHERE r.application_id = ? ORDER BY r.id DESC', [$applicationId]);
    $a['interviews'] = rows('SELECT i.*, u.name AS interviewer FROM interviews i JOIN users u ON u.id = i.interviewer_id WHERE i.application_id = ? ORDER BY i.scheduled_at DESC', [$applicationId]);

    return $a;
}
