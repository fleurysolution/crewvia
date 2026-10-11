<?php
/**
 * Training (P5-M04): agency courses, their assessments, plans that sequence
 * them, requirements by desk role, crew trade or project, each person's
 * assignments, attempts, completions and renewals, and compliance.
 *
 * Recruiters (the HR desk) and administrators manage it. Everyone signed
 * in, workers included, takes what is assigned to them under My training.
 *
 * Certifications: a passed course that awards a qualification writes it,
 * verified and with its expiry, into candidate_qualifications for a worker,
 * so it is tracked, required per project and renewed where it already was
 * (Qualifications, the renewal alerts). Never a second register.
 */

declare(strict_types=1);

const TR_SOON_DAYS = 30;

function tr_can_manage(): bool
{
    return can('recruiter');
}

/** @return array<string,string> */
function tr_scopes(): array
{
    return ['staff_role' => t('Everyone of a desk role'), 'trade' => t('Every worker of a trade'), 'project' => t('Every worker on a project')];
}

/** @return array<string,string> */
function tr_staff_roles(): array
{
    return ['recruiter' => t('Recruiter'), 'payroll' => t('Payroll'), 'hotels' => t('Logistics'), 'supervisor' => t('Supervisor'), 'admin' => t('Administrator')];
}

/** @return array<string,string> */
function tr_statuses(): array
{
    return ['due' => t('To do'), 'overdue' => t('Overdue'), 'done' => t('Done'), 'expiring' => t('Expiring'), 'expired' => t('Expired'), 'none' => t('Not assigned')];
}

function tr_notify(int $userId, string $message, string $target): void
{
    q('INSERT INTO notifications (user_id, message, target) VALUES (?,?,?)', [$userId, mb_substr($message, 0, 255), $target]);
}

// ── courses and questions ───────────────────────────────────────────────

function tr_course_save(array $in, int $by, int $id = 0): array
{
    $title = trim((string) ($in['title'] ?? ''));
    $description = trim((string) ($in['description'] ?? ''));
    $content = trim((string) ($in['content'] ?? ''));
    $url = trim((string) ($in['resource_url'] ?? ''));
    $valid = trim((string) ($in['valid_months'] ?? ''));
    $awards = (int) ($in['awards_type_id'] ?? 0);
    $pass = trim((string) ($in['pass_mark'] ?? '80'));
    if (mb_strlen($title) < 3 || mb_strlen($title) > 190) { return [t('Give the course a title of 3 to 190 characters.'), 0]; }
    if (mb_strlen($description) > 1000 || mb_strlen($content) > 100000) { return [t('A field is too long.'), 0]; }
    if ($url !== '' && (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https')) { return [t('Use an HTTPS resource URL.'), 0]; }
    if ($valid !== '' && (! ctype_digit($valid) || (int) $valid < 1 || (int) $valid > 120)) { return [t('A completion counts for 1 to 120 months, or for good when left empty.'), 0]; }
    if ($awards && ! row('SELECT id FROM qualification_types WHERE id = ?', [$awards])) { return [t('Choose a qualification from the list, or none.'), 0]; }
    if (! ctype_digit($pass) || (int) $pass < 1 || (int) $pass > 100) { return [t('The pass mark is 1 to 100%.'), 0]; }
    $values = [$title, $description ?: null, $content ?: null, $url ?: null, $valid !== '' ? (int) $valid : null, $awards ?: null, (int) $pass];
    if ($id) {
        if (! row('SELECT id FROM training_courses WHERE id = ?', [$id])) { return [t('That course does not exist.'), 0]; }
        q('UPDATE training_courses SET title = ?, description = ?, content = ?, resource_url = ?, valid_months = ?, awards_type_id = ?, pass_mark = ?, is_active = ? WHERE id = ?',
          [...$values, ! empty($in['is_active']) ? 1 : 0, $id]);
        log_activity('changed a training course', 'training_course', $id, $title);
        return [null, $id];
    }
    q('INSERT INTO training_courses (title, description, content, resource_url, valid_months, awards_type_id, pass_mark, created_by) VALUES (?,?,?,?,?,?,?,?)', [...$values, $by]);
    $id = (int) db()->lastInsertId();
    log_activity('created a training course', 'training_course', $id, $title);
    return [null, $id];
}

function tr_question_add(int $courseId, array $in): ?string
{
    if (! row('SELECT id FROM training_courses WHERE id = ?', [$courseId])) { return t('That course does not exist.'); }
    $question = trim((string) ($in['question'] ?? ''));
    $options = array_values(array_filter(array_map(fn($o) => trim((string) $o), (array) ($in['options'] ?? [])), fn($o) => $o !== ''));
    $correct = (string) ($in['correct'] ?? '');
    if (mb_strlen($question) < 5 || mb_strlen($question) > 500) { return t('Ask the question in 5 to 500 characters.'); }
    if (count($options) < 2 || count($options) > 6) { return t('Give 2 to 6 answers.'); }
    foreach ($options as $o) { if (mb_strlen($o) > 300) { return t('An answer is at most 300 characters.'); } }
    if (count(array_unique(array_map('mb_strtolower', $options))) !== count($options)) { return t('Two answers are the same.'); }
    if (! ctype_digit($correct) || (int) $correct >= count($options)) { return t('Say which answer is right.'); }
    $order = (int) val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM training_questions WHERE course_id = ?', [$courseId]);
    q('INSERT INTO training_questions (course_id, question, options_json, correct_index, sort_order) VALUES (?,?,?,?,?)',
      [$courseId, $question, json_encode($options, JSON_UNESCAPED_UNICODE), (int) $correct, $order]);
    log_activity('added an assessment question', 'training_course', $courseId, mb_substr($question, 0, 120));
    return null;
}

function tr_question_remove(int $questionId): ?string
{
    $q = row('SELECT course_id FROM training_questions WHERE id = ?', [$questionId]);
    if (! $q) { return t('That question does not exist.'); }
    q('DELETE FROM training_questions WHERE id = ?', [$questionId]);
    log_activity('removed an assessment question', 'training_course', (int) $q['course_id'], '#' . $questionId);
    return null;
}

/** The questions as a learner sees them: no answer key. */
function tr_questions_for_learner(int $courseId): array
{
    return array_map(fn($r) => ['id' => (int) $r['id'], 'question' => $r['question'], 'options' => json_decode($r['options_json'], true)],
                     rows('SELECT id, question, options_json FROM training_questions WHERE course_id = ? ORDER BY sort_order, id', [$courseId]));
}

// ── assignments ─────────────────────────────────────────────────────────

/** The latest assignment of a person to a course, or null. */
function tr_latest(int $userId, int $courseId): ?array
{
    return row('SELECT * FROM training_assignments WHERE user_id = ? AND course_id = ? ORDER BY id DESC LIMIT 1', [$userId, $courseId]) ?: null;
}

/** Where an assignment stands: due, overdue, done, expiring, expired. */
function tr_status(?array $a): string
{
    $today = date('Y-m-d');
    if ($a === null) { return 'none'; }
    if ($a['completed_at'] === null) { return $a['due_on'] < $today ? 'overdue' : 'due'; }
    if ($a['expires_on'] !== null && $a['expires_on'] <= $today) { return 'expired'; }
    if ($a['expires_on'] !== null && $a['expires_on'] <= date('Y-m-d', strtotime('+' . TR_SOON_DAYS . ' days'))) { return 'expiring'; }
    return 'done';
}

/**
 * Assigns a course, unless the person already has it open, or has done it
 * and it still counts beyond the coming month. Returns the new id, or 0.
 */
function tr_assign(int $userId, int $courseId, string $source, string $dueOn, ?int $requirementId = null, ?int $planId = null, ?int $by = null): int
{
    $latest = tr_latest($userId, $courseId);
    if ($latest && in_array(tr_status($latest), ['due', 'overdue', 'done'], true)) {
        return 0;
    }
    $course = row('SELECT title, is_active FROM training_courses WHERE id = ?', [$courseId]);
    if (! $course || ! (int) $course['is_active']) {
        return 0;
    }
    // Done before and running out (or run out): this is its renewal. Running
    // out within the month, it is due the day it runs out.
    if ($latest && $latest['completed_at'] !== null) {
        $source = 'renewal';
        if (tr_status($latest) === 'expiring') {
            $dueOn = (string) $latest['expires_on'];
        }
    }
    q('INSERT INTO training_assignments (user_id, course_id, source, requirement_id, plan_id, due_on, assigned_by) VALUES (?,?,?,?,?,?,?)',
      [$userId, $courseId, $source, $requirementId, $planId, $dueOn, $by]);
    $id = (int) db()->lastInsertId();
    tr_notify($userId, t('Training to do by :date: :title', ['date' => d($dueOn), 'title' => $course['title']]), '/my-training?take=' . $id);
    return $id;
}

/** Assigns one course to people by hand. */
function tr_assign_manual(int $courseId, array $userIds, string $dueOn, int $by): array
{
    if (! valid_date($dueOn) || $dueOn < date('Y-m-d')) { return [t('Give a due date, today or later.'), 0]; }
    if (! row('SELECT id FROM training_courses WHERE id = ? AND is_active = 1', [$courseId])) { return [t('Choose a course in use.'), 0]; }
    $n = 0;
    foreach (array_unique(array_map('intval', $userIds)) as $u) {
        if ($u && row('SELECT id FROM users WHERE id = ? AND is_active = 1', [$u])) {
            $n += tr_assign($u, $courseId, 'manual', $dueOn, null, null, $by) ? 1 : 0;
        }
    }
    log_activity('assigned a training course', 'training_course', $courseId, $n . ' person(s)');
    return [null, $n];
}

// ── plans ───────────────────────────────────────────────────────────────

function tr_plan_save(array $in, int $by, int $id = 0): array
{
    $name = trim((string) ($in['name'] ?? ''));
    $description = trim((string) ($in['description'] ?? ''));
    if (mb_strlen($name) < 3 || mb_strlen($name) > 190) { return [t('Give the plan a name of 3 to 190 characters.'), 0]; }
    if (mb_strlen($description) > 1000) { return [t('A field is too long.'), 0]; }
    if ($id) {
        if (! row('SELECT id FROM training_plans WHERE id = ?', [$id])) { return [t('That plan does not exist.'), 0]; }
        q('UPDATE training_plans SET name = ?, description = ?, is_active = ? WHERE id = ?', [$name, $description ?: null, ! empty($in['is_active']) ? 1 : 0, $id]);
        log_activity('changed a training plan', 'training_plan', $id, $name);
        return [null, $id];
    }
    q('INSERT INTO training_plans (name, description, created_by) VALUES (?,?,?)', [$name, $description ?: null, $by]);
    $id = (int) db()->lastInsertId();
    log_activity('created a training plan', 'training_plan', $id, $name);
    return [null, $id];
}

function tr_plan_course_add(int $planId, int $courseId, string $dueDays): ?string
{
    if (! row('SELECT id FROM training_plans WHERE id = ?', [$planId])) { return t('That plan does not exist.'); }
    if (! row('SELECT id FROM training_courses WHERE id = ? AND is_active = 1', [$courseId])) { return t('Choose a course in use.'); }
    if (! ctype_digit($dueDays) || (int) $dueDays < 1 || (int) $dueDays > 365) { return t('A course in a plan is due 1 to 365 days after the plan is given.'); }
    if (row('SELECT plan_id FROM training_plan_courses WHERE plan_id = ? AND course_id = ?', [$planId, $courseId])) { return t('That course is already in the plan.'); }
    $order = (int) val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM training_plan_courses WHERE plan_id = ?', [$planId]);
    q('INSERT INTO training_plan_courses (plan_id, course_id, sort_order, due_days) VALUES (?,?,?,?)', [$planId, $courseId, $order, (int) $dueDays]);
    log_activity('added a course to a training plan', 'training_plan', $planId, '#' . $courseId . ' due ' . (int) $dueDays . ' days');
    return null;
}

function tr_plan_course_remove(int $planId, int $courseId): ?string
{
    if (! q('DELETE FROM training_plan_courses WHERE plan_id = ? AND course_id = ?', [$planId, $courseId])->rowCount()) { return t('That course is not in the plan.'); }
    log_activity('removed a course from a training plan', 'training_plan', $planId, '#' . $courseId);
    return null;
}

/** Gives a plan to people: each course of it, due its days from today, unless already open or still counting. */
function tr_plan_assign(int $planId, array $userIds, int $by): array
{
    $plan = row('SELECT * FROM training_plans WHERE id = ? AND is_active = 1', [$planId]);
    if (! $plan) { return [t('Choose a plan in use.'), 0]; }
    $courses = rows('SELECT course_id, due_days FROM training_plan_courses WHERE plan_id = ? ORDER BY sort_order', [$planId]);
    if (! $courses) { return [t('The plan has no course yet.'), 0]; }
    $people = array_values(array_filter(array_unique(array_map('intval', $userIds)), fn($u) => $u && row('SELECT id FROM users WHERE id = ? AND is_active = 1', [$u])));
    if (! $people) { return [t('Choose who the plan is for.'), 0]; }
    $n = 0;
    foreach ($people as $u) {
        foreach ($courses as $c) {
            $n += tr_assign($u, (int) $c['course_id'], 'plan', date('Y-m-d', strtotime('+' . (int) $c['due_days'] . ' days')), null, $planId, $by) ? 1 : 0;
        }
    }
    log_activity('gave a training plan', 'training_plan', $planId, count($people) . ' person(s), ' . $n . ' assignment(s)');
    return [null, $n];
}

// ── requirements and sync ───────────────────────────────────────────────

function tr_requirement_save(array $in, int $by): ?string
{
    $course = (int) ($in['course_id'] ?? 0);
    $scope = (string) ($in['scope'] ?? '');
    $role = (string) ($in['staff_role'] ?? '');
    $trade = trim((string) ($in['trade'] ?? ''));
    $job = (int) ($in['job_id'] ?? 0);
    $due = trim((string) ($in['due_days'] ?? '30'));
    if (! row('SELECT id FROM training_courses WHERE id = ? AND is_active = 1', [$course])) { return t('Choose a course in use.'); }
    if (! isset(tr_scopes()[$scope])) { return t('Say who the training is required of.'); }
    if ($scope === 'staff_role' && ! isset(tr_staff_roles()[$role])) { return t('Choose a desk role.'); }
    if ($scope === 'trade' && (mb_strlen($trade) < 2 || mb_strlen($trade) > 190)) { return t('Give the trade as it is written on assignments.'); }
    if ($scope === 'project' && ! row('SELECT id FROM jobs WHERE id = ?', [$job])) { return t('Choose a project.'); }
    if (! ctype_digit($due) || (int) $due < 1 || (int) $due > 365) { return t('Required training is due 1 to 365 days after it is assigned.'); }
    q('INSERT INTO training_requirements (course_id, scope, staff_role, trade, job_id, due_days, created_by) VALUES (?,?,?,?,?,?,?)',
      [$course, $scope, $scope === 'staff_role' ? $role : null, $scope === 'trade' ? $trade : null, $scope === 'project' ? $job : null, (int) $due, $by]);
    $id = (int) db()->lastInsertId();
    log_activity('required a training course', 'training_requirement', $id, $scope . ': ' . ($role ?: $trade ?: '#' . $job));
    return null;
}

function tr_requirement_stop(int $id): ?string
{
    if (! q('UPDATE training_requirements SET is_active = 0 WHERE id = ? AND is_active = 1', [$id])->rowCount()) { return t('That requirement does not exist or is already stopped.'); }
    log_activity('stopped a training requirement', 'training_requirement', $id);
    return null;
}

/** The active people a requirement applies to. */
function tr_requirement_people(array $r): array
{
    if ($r['scope'] === 'staff_role') {
        return array_map('intval', array_column(rows('SELECT u.id FROM users u WHERE u.is_active = 1 AND u.role = ? AND NOT EXISTS (SELECT 1 FROM worker_accounts w WHERE w.user_id = u.id)', [$r['staff_role']]), 'id'));
    }
    $join = 'FROM worker_accounts w JOIN users u ON u.id = w.user_id AND u.is_active = 1 JOIN placements p ON p.candidate_id = w.candidate_id AND p.status NOT IN (\'completed\',\'cancelled\')';
    if ($r['scope'] === 'trade') {
        return array_map('intval', array_column(rows("SELECT DISTINCT w.user_id $join JOIN assignment_details d ON d.placement_id = p.id WHERE LOWER(TRIM(d.trade)) = LOWER(TRIM(?))", [$r['trade']]), 'user_id'));
    }
    return array_map('intval', array_column(rows("SELECT DISTINCT w.user_id $join WHERE p.job_id = ?", [(int) $r['job_id']]), 'user_id'));
}

/**
 * Brings assignments in line with the requirements: whoever a requirement
 * applies to and has not got the course open or still counting is given it;
 * a completion that runs out within the month becomes a renewal, due on the
 * day it runs out. Run by install/run-workflows.php, and from Training.
 */
function tr_sync(): int
{
    $n = 0;
    foreach (rows('SELECT r.* FROM training_requirements r JOIN training_courses c ON c.id = r.course_id WHERE r.is_active = 1 AND c.is_active = 1 ORDER BY r.id') as $r) {
        foreach (tr_requirement_people($r) as $u) {
            $n += tr_assign($u, (int) $r['course_id'], 'requirement', date('Y-m-d', strtotime('+' . (int) $r['due_days'] . ' days')), (int) $r['id']) ? 1 : 0;
        }
    }
    return $n;
}

// ── taking a course ─────────────────────────────────────────────────────

/** A person's own assignment, or null. */
function tr_own(int $assignmentId, int $userId): ?array
{
    return row('SELECT a.*, c.title, c.description, c.content, c.resource_url, c.pass_mark, c.valid_months, c.awards_type_id
                  FROM training_assignments a JOIN training_courses c ON c.id = a.course_id WHERE a.id = ? AND a.user_id = ?', [$assignmentId, $userId]) ?: null;
}

/**
 * Hands in an assessment, or confirms a course without one. Every attempt
 * is kept. Passed: the course is complete, counts until valid_months on,
 * and awards its qualification to a worker. Returns [refusal, score, passed].
 */
function tr_submit(int $assignmentId, int $userId, array $answers, bool $confirmed): array
{
    db()->beginTransaction();
    $a = row('SELECT a.*, c.title, c.pass_mark, c.valid_months, c.awards_type_id FROM training_assignments a JOIN training_courses c ON c.id = a.course_id
               WHERE a.id = ? AND a.user_id = ? FOR UPDATE', [$assignmentId, $userId]);
    if (! $a) { db()->rollBack(); return [t('That training is not yours.'), null, false]; }
    if ($a['completed_at'] !== null) { db()->rollBack(); return [t('That training is already complete.'), null, false]; }
    $questions = rows('SELECT id, options_json, correct_index FROM training_questions WHERE course_id = ? ORDER BY sort_order, id', [(int) $a['course_id']]);
    if ($questions) {
        $given = [];
        $right = 0;
        foreach ($questions as $q) {
            $v = $answers[(string) $q['id']] ?? null;
            if ($v === null || ! ctype_digit((string) $v) || (int) $v >= count(json_decode($q['options_json'], true))) {
                db()->rollBack();
                return [t('Answer every question.'), null, false];
            }
            $given[(int) $q['id']] = (int) $v;
            $right += (int) $v === (int) $q['correct_index'] ? 1 : 0;
        }
        $score = (int) round($right * 100 / count($questions));
        $passed = $score >= (int) $a['pass_mark'];
        q('INSERT INTO training_attempts (assignment_id, score, passed, answers_json) VALUES (?,?,?,?)', [$assignmentId, $score, $passed ? 1 : 0, json_encode($given)]);
    } else {
        if (! $confirmed) { db()->rollBack(); return [t('Confirm that you have read and understood it.'), null, false]; }
        $score = null;
        $passed = true;
    }
    if ($passed) {
        $expires = $a['valid_months'] !== null ? date('Y-m-d', strtotime('+' . (int) $a['valid_months'] . ' months')) : null;
        q('UPDATE training_assignments SET completed_at = NOW(), score = ?, expires_on = ? WHERE id = ?', [$score, $expires, $assignmentId]);
        if ($a['awards_type_id']) {
            $qid = tr_award((int) $a['user_id'], (int) $a['awards_type_id'], $expires, (string) $a['title'], $assignmentId);
            if ($qid) { q('UPDATE training_assignments SET qualification_id = ? WHERE id = ?', [$qid, $assignmentId]); }
        }
    }
    db()->commit();
    log_activity($passed ? 'completed a training course' : 'did not pass a training assessment', 'training_assignment', $assignmentId,
                 $a['title'] . ($score !== null ? ' ' . $score . '%' : ''));
    return [null, $score, $passed];
}

/**
 * Writes the qualification a passed course awards into a worker's record,
 * verified, with the course as evidence. One that is already verified and
 * runs as long or longer is kept as it is. Returns its id, or 0 (not a
 * worker, or kept).
 */
function tr_award(int $userId, int $typeId, ?string $expires, string $courseTitle, int $assignmentId): int
{
    $cid = (int) val('SELECT candidate_id FROM worker_accounts WHERE user_id = ?', [$userId]);
    if (! $cid) { return 0; }
    $existing = row('SELECT id, status, expires_on FROM candidate_qualifications WHERE candidate_id = ? AND type_id = ?', [$cid, $typeId]);
    if ($existing && $existing['status'] === 'verified' && ($existing['expires_on'] === null || ($expires !== null && $existing['expires_on'] >= $expires))) {
        return 0;
    }
    $evidence = mb_substr('Crewvia training: ' . $courseTitle . ' (#' . $assignmentId . ')', 0, 190);
    q("INSERT INTO candidate_qualifications (candidate_id, type_id, detail, issued_on, expires_on, evidence_reference, status, reviewed_at)
       VALUES (?,?,?,CURDATE(),?,?,'verified',NOW())
       ON DUPLICATE KEY UPDATE detail = VALUES(detail), issued_on = VALUES(issued_on), expires_on = VALUES(expires_on), evidence_reference = VALUES(evidence_reference),
                               status = 'verified', reviewed_by = NULL, reviewed_at = NOW()",
      [$cid, $typeId, mb_substr($courseTitle, 0, 190), $expires, $evidence]);
    $qid = (int) val('SELECT id FROM candidate_qualifications WHERE candidate_id = ? AND type_id = ?', [$cid, $typeId]);
    q('INSERT INTO qualification_events (qualification_id, user_id, action, note) VALUES (?,?,?,?)',
      [$qid, $userId, 'verified', json_encode(['by' => 'training', 'course' => $courseTitle, 'assignment' => $assignmentId, 'expires_on' => $expires], JSON_UNESCAPED_UNICODE)]);
    return $qid;
}

// ── compliance ──────────────────────────────────────────────────────────

/**
 * Each active requirement and each person it applies to, with where they
 * stand on its course. $filter: scope, status.
 */
function tr_compliance(array $filter = []): array
{
    $out = [];
    foreach (rows('SELECT r.*, c.title FROM training_requirements r JOIN training_courses c ON c.id = r.course_id WHERE r.is_active = 1 AND c.is_active = 1 ORDER BY c.title, r.id') as $r) {
        if (! empty($filter['scope']) && $filter['scope'] !== $r['scope']) { continue; }
        foreach (tr_requirement_people($r) as $u) {
            $latest = tr_latest($u, (int) $r['course_id']);
            // A refresher still open shows the completion it renews while that still counts.
            if ($latest && $latest['completed_at'] === null && $latest['source'] === 'renewal') {
                $done = row('SELECT * FROM training_assignments WHERE user_id = ? AND course_id = ? AND completed_at IS NOT NULL ORDER BY id DESC LIMIT 1', [$u, (int) $r['course_id']]);
                if ($done && tr_status($done) === 'expiring') { $latest = $done; }
            }
            $status = tr_status($latest);
            if (! empty($filter['status']) && $filter['status'] !== $status) { continue; }
            $out[] = ['requirement' => $r, 'user_id' => $u, 'name' => (string) val('SELECT name FROM users WHERE id = ?', [$u]), 'assignment' => $latest, 'status' => $status];
        }
    }
    return $out;
}
