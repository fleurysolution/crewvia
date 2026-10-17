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

/**
 * P5-M54: an application that is settled (rejected, withdrawn, hired) closes what was still open on it:
 * a manager's review still waiting is taken back, an interview still to come is cancelled, and each
 * person concerned is told, so nobody decides on, or waits for, somebody who is no longer in the running.
 */
function review_close_for_application(int $applicationId, int $by, string $why): void
{
    $who = (string) val('SELECT c.full_name FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE a.id = ?', [$applicationId]);
    foreach (rows("SELECT id, reviewer_id FROM application_reviews WHERE application_id = ? AND status = 'pending'", [$applicationId]) as $r) {
        q("UPDATE application_reviews SET status = 'withdrawn', decided_at = NOW(), decision_note = ? WHERE id = ?", [mb_substr($why, 0, 2000), (int) $r['id']]);
        review_notify((int) $r['reviewer_id'], t('A review was taken back: nothing to decide') . ' (' . $who . ': ' . $why . ')', '/reviews');
    }
    foreach (rows("SELECT id, interviewer_id, scheduled_at FROM interviews WHERE application_id = ? AND status = 'scheduled'", [$applicationId]) as $i) {
        q("UPDATE interviews SET status = 'cancelled' WHERE id = ?", [(int) $i['id']]);
        review_notify((int) $i['interviewer_id'], t('Interview cancelled: :name, :date.', ['name' => $who, 'date' => date('D j M, g:i a', strtotime((string) $i['scheduled_at']))]) . ' ' . $why, '/reviews');
    }
}

/** The recruiter passes a screened applicant to a manager. @return ?string an error */
function review_request(int $applicationId, int $reviewerId, int $rating, string $recommendation, int $by): ?string
{
    $a = review_application($applicationId);
    $recommendation = trim($recommendation);
    if (! $a) { return t('Application unavailable in this project.'); }
    if (in_array($a['stage'], ['offered', 'accepted', 'rejected', 'withdrawn'], true)) { return t('Only an application still being screened can be passed to a manager.'); }
    if (! review_person_ok($reviewerId)) { return t('Choose who reviews this applicant.'); }
    // P5-M87: a hiring manager (or the owner) may send to the review they decide themself - the flow stays the same.
    if ($reviewerId === $by && ! can('admin') && ! in_array($by, vacancy_managers((int) $a['vacancy_id']), true)) { return t('Pass the applicant to somebody else: the manager reviews what the recruiter screened.'); }
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
    if ((int) $r['reviewer_id'] !== $by && ! can('admin') && ! in_array($by, vacancy_managers((int) val('SELECT vacancy_id FROM applications WHERE id = ?', [(int) $r['application_id']])), true)) { return t('Only the person it was passed to decides.'); }
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
    // P5-M84: approved, the applicant goes to HR; the recruiter's part is done.
    $hr = $decision === 'validated' ? review_hand_to_hr($applicationId, $by, ! empty($GLOBALS['review_expedite'])) : null;
    review_notify((int) $r['requested_by'], $hr
                      ? t(':name approved by :who and handed to HR (:hr). Nothing more for you.', ['name' => (string) ($a['full_name'] ?? ''), 'who' => $who, 'hr' => $hr])
                      : t(':name: :decision', ['name' => (string) ($a['full_name'] ?? ''), 'decision' => review_statuses()[$decision]]),
                  $hr ? '/hiring?v=' . (int) ($a['vacancy_id'] ?? 0) . '&app=' . $applicationId : '/candidates/' . (int) ($a['candidate_id'] ?? 0));
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
    // P5-M52: the candidate hears of it too, in their portal and by email.
    require_once __DIR__ . '/notices.php';
    candidate_tell((int) $a['candidate_id'], t('your interview for :job', ['job' => $a['title']]),
                   t('Your interview for :job: :date, :minutes minutes, :mode:place.', ['job' => $a['title'], 'date' => date('l, F j, g:i a', $at), 'minutes' => $duration,
                     'mode' => strtolower(interview_modes()[$mode]), 'place' => trim($place) !== '' ? ' (' . trim($place) . ')' : '']), '/my-jobs');
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
        review_notify((int) $i['created_by'], t('Interview recorded for :name', ['name' => (string) ($a['full_name'] ?? '')]), '/candidates/' . (int) ($a['candidate_id'] ?? 0));
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
    // P5-M92: the recruiter's checklist answers, for the person who decides.
    require_once __DIR__ . '/checklists.php';
    $a['checklist'] = cl_for_application($applicationId);

    return $a;
}

/**
 * P5-M84: an approved applicant goes to HR - the project's coordinator, or whoever runs onboarding - with a task
 * (paperwork in the portal, drug test and background if needed, group and supervisor) and the portal invitation,
 * so the person can fill in their forms. Returns who has it.
 */
function review_hand_to_hr(int $applicationId, int $by, bool $expedite = false): ?string
{
    $a = row('SELECT a.id, a.candidate_id, c.full_name, v.title, v.job_id, j.coordinator_id FROM applications a JOIN candidates c ON c.id = a.candidate_id
              JOIN vacancies v ON v.id = a.vacancy_id JOIN jobs j ON j.id = v.job_id WHERE a.id = ?', [$applicationId]);
    if (! $a) {
        return null;
    }
    $hr = (int) ($a['coordinator_id'] ?? 0);
    if (! $hr || ! val('SELECT is_active FROM users WHERE id = ?', [$hr])) {
        $hr = (int) val("SELECT u.id FROM users u WHERE u.is_active = 1 AND u.role IN ('hr','recruiter','admin') AND u.id <> ?
                         AND NOT EXISTS (SELECT 1 FROM worker_accounts w WHERE w.user_id = u.id)
                         ORDER BY u.role <> 'hr', u.role = 'admin', (SELECT COUNT(*) FROM project_tasks t WHERE t.assignee_id = u.id AND t.status IN ('todo','in_progress','blocked')), u.id LIMIT 1", [$by]);
    }
    $name = $hr ? (string) val('SELECT name FROM users WHERE id = ?', [$hr]) : '';
    q("UPDATE candidates SET stage = 'offered' WHERE id = ? AND stage IN ('new','contacted','screening')", [(int) $a['candidate_id']]);
    require_once __DIR__ . '/project-tasks.php';
    $title = t('Onboard :name: paperwork, drug test, group and supervisor', ['name' => $a['full_name']]);
    if (! val('SELECT COUNT(*) FROM project_tasks WHERE job_id = ? AND title = ?', [(int) $a['job_id'], $title])) {
        q("INSERT INTO project_tasks (job_id, title, description, assignee_id, priority, due_on, created_by) VALUES (?,?,?,?, 'high', ?, ?)",
          [(int) $a['job_id'], $title, t('Approved for :role. The portal invitation has gone to them for the W-4, I-9 and direct deposit. Send the drug test and background check details if this job needs them, then set their group and supervisor on the placement.', ['role' => $a['title']]),
           $hr ?: null, date('Y-m-d', strtotime('+2 days')), $by]);
        $task = (int) db()->lastInsertId();
        pt_event($task, $by, 'created', null, $title);
        try {
            // P5-M92: the task opens the person's forms, where they are signed in person.
            q('UPDATE project_tasks SET link = ? WHERE id = ?', ['/tax-forms?person=' . (int) $a['candidate_id'], $task]);
        } catch (Throwable $e) {
            // Before the upgrade: the task without its link.
        }
        if ($hr) {
            pt_notify($hr, $by, t('To onboard: :name, approved for :role', ['name' => $a['full_name'], 'role' => $a['title']]), '/my-tasks?task=' . $task);
        }
        // P5-M85: travel follows HR's clearance - at once when the manager expedites.
        try {
            q('INSERT IGNORE INTO hiring_handoffs (application_id, candidate_id, job_id, expedited, hr_task_id, created_by) VALUES (?,?,?,?,?,?)',
              [$applicationId, (int) $a['candidate_id'], (int) $a['job_id'], $expedite ? 1 : 0, $task, $by]);
            if ($expedite) {
                review_travel_task((int) val('SELECT id FROM hiring_handoffs WHERE application_id = ?', [$applicationId]), $by);
            }
        } catch (Throwable $e) {
            // Before the upgrade has run, the HR task still goes.
        }
    }
    try {
        require_once __DIR__ . '/invitations.php';
        workforce_invite_candidate((int) $a['candidate_id']);
    } catch (Throwable $e) {
        // The task says what to do; an invitation that could not go is sent again from Onboarding.
    }
    log_activity('handed an approved applicant to HR', 'application', $applicationId, $name);

    return $name !== '' ? $name : t('Onboarding');
}

/**
 * P5-M85: the project coordinator's task - flight, hotel, pickup - once HR has cleared the person, or at once when
 * expedited. Made once per hand-over.
 */
function review_travel_task(int $handoffId, int $by): ?int
{
    $h = row('SELECT h.*, c.full_name, j.title AS job FROM hiring_handoffs h JOIN candidates c ON c.id = h.candidate_id JOIN jobs j ON j.id = h.job_id WHERE h.id = ?', [$handoffId]);
    if (! $h || $h['travel_task_id']) {
        return $h ? (int) $h['travel_task_id'] : null;
    }
    require_once __DIR__ . '/stage-tasks.php';
    $who = stage_task_assignee(row('SELECT * FROM jobs WHERE id = ?', [(int) $h['job_id']]) ?: [], 'hotels');
    $title = t('Book the flight and hotel for :name', ['name' => $h['full_name']]);
    q("INSERT INTO project_tasks (job_id, title, description, assignee_id, priority, due_on, created_by) VALUES (?,?,?,?, 'high', ?, ?)",
      [(int) $h['job_id'], $title, $h['expedited'] ? t('Expedited by the manager: book now, while HR finishes the background check and drug test. The arrival shows on Flight arrivals once the flight is entered.')
                                                     : t('HR has cleared them. Book the flight and the hotel; the arrival shows on Flight arrivals once the flight is entered.'),
       $who, date('Y-m-d', strtotime('+1 day')), $by]);
    $task = (int) db()->lastInsertId();
    pt_event($task, $by, 'created', null, $title);
    try {
        q("UPDATE project_tasks SET link = '/travel' WHERE id = ?", [$task]);
    } catch (Throwable $e) {
        // Before the upgrade: the task without its link.
    }
    if ($who) {
        pt_notify($who, $by, t('Travel to book: :name for :job', ['name' => $h['full_name'], 'job' => $h['job']]), '/my-tasks?task=' . $task);
    }
    q('UPDATE hiring_handoffs SET travel_task_id = ? WHERE id = ?', [$task, $handoffId]);

    return $task;
}

/** P5-M85: HR's task done - the person is cleared, and travel goes to the project coordinator. */
function review_hr_task_done(int $taskId, int $by): void
{
    try {
        $h = row('SELECT id FROM hiring_handoffs WHERE hr_task_id = ? AND hr_done_at IS NULL', [$taskId]);
    } catch (Throwable $e) {
        return;
    }
    if ($h) {
        q('UPDATE hiring_handoffs SET hr_done_at = NOW() WHERE id = ?', [(int) $h['id']]);
        review_travel_task((int) $h['id'], $by);
    }
}

/** P5-M85: the hiring managers of a requisition (ids), active ones only. */
function vacancy_managers(int $vacancyId): array
{
    try {
        return array_map('intval', array_column(rows('SELECT m.user_id FROM vacancy_managers m JOIN users u ON u.id = m.user_id AND u.is_active = 1 WHERE m.vacancy_id = ?', [$vacancyId]), 'user_id'));
    } catch (Throwable $e) {
        return [];
    }
}

function vacancy_managers_save(int $vacancyId, array $ids): void
{
    q('DELETE FROM vacancy_managers WHERE vacancy_id = ?', [$vacancyId]);
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        if ($id && in_array($id, array_map('intval', array_column(hiring_managers(), 'id')), true)) {
            q('INSERT IGNORE INTO vacancy_managers (vacancy_id, user_id) VALUES (?, ?)', [$vacancyId, $id]);
        }
    }
}

/** The SQL that says a review (alias r, its application a) is this person's to decide: theirs, or their requisition's. */
function review_mine_sql(int $userId): string
{
    return '(r.reviewer_id = ' . $userId . ' OR EXISTS (SELECT 1 FROM vacancy_managers vm WHERE vm.vacancy_id = a.vacancy_id AND vm.user_id = ' . $userId . '))';
}

/** P5-M87: how full the requisition of an application is: [asked, placed]. */
function review_requisition_fill(int $applicationId): array
{
    $v = row('SELECT v.id, v.openings FROM applications a JOIN vacancies v ON v.id = a.vacancy_id WHERE a.id = ?', [$applicationId]);
    if (! $v) {
        return [0, 0];
    }
    $placed = (int) val("SELECT COUNT(*) FROM placements WHERE vacancy_id = ? AND status NOT IN ('cancelled','completed')", [(int) $v['id']]);

    return [max(1, (int) ($v['openings'] ?? 1)), $placed];
}

/**
 * P5-M87: the hiring manager assigns the approved person to the requisition's job (an offered placement on its terms),
 * unless the requisition already has the people it asked for. Returns the refusal, or null.
 */
function review_assign_to_job(int $applicationId, int $by): ?string
{
    $a = row('SELECT a.candidate_id, a.vacancy_id, v.job_id, v.starts_on FROM applications a JOIN vacancies v ON v.id = a.vacancy_id WHERE a.id = ?', [$applicationId]);
    if (! $a) {
        return t('Application unavailable in this project.');
    }
    [$asked, $placed] = review_requisition_fill($applicationId);
    if ($placed >= $asked) {
        return t('The requisition is full: :placed of :asked placed. Approved, but not assigned.', ['placed' => $placed, 'asked' => $asked]);
    }
    if (val("SELECT id FROM placements WHERE candidate_id = ? AND job_id = ? AND status NOT IN ('completed','cancelled')", [(int) $a['candidate_id'], (int) $a['job_id']])) {
        return null;
    }
    require_once __DIR__ . '/placement-rates.php';
    $rates = placement_rates((int) $a['job_id'], (int) $a['vacancy_id']);
    q("INSERT INTO placements (candidate_id, job_id, vacancy_id, order_line_id, status, start_date, created_by, pay_rate, bill_rate, per_diem_rate, guarantee_hours)
       VALUES (?,?,?,?, 'offered', ?,?,?,?,?,?)",
      [(int) $a['candidate_id'], (int) $a['job_id'], $rates['vacancy_id'], $rates['order_line_id'], $a['starts_on'] ?: null, $by,
       $rates['pay_rate'], $rates['bill_rate'], $rates['per_diem_rate'], $rates['guarantee_hours']]);
    log_activity('assigned an approved applicant to the job', 'placement', (int) db()->lastInsertId());

    return null;
}

/** P5-M88: the people who may hire - marked on Team; with nobody marked yet, the people with full access. */
function hiring_managers(): array
{
    try {
        $marked = rows("SELECT id, name, role FROM users WHERE is_active = 1 AND hiring_manager = 1 AND role NOT IN ('worker','client') ORDER BY name");
    } catch (Throwable $e) {
        $marked = [];
    }

    return $marked ?: rows("SELECT id, name, role FROM users WHERE is_active = 1 AND role = 'admin' ORDER BY name");
}
