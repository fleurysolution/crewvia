<?php
/**
 * Evaluation cycles, performance goals and their progress, development
 * plans and their follow-up (P2-M03), built on the appraisals of P2-M02.
 *
 *   - a cycle is a round of reviews: one template, one period, every
 *     assignment in scope (one project, or all). Opening it opens a review
 *     for each, reviewed by the assignment's supervisor; those it cannot
 *     open are listed with the reason. Opening twice opens nobody twice.
 *   - a goal says what, how it is measured and by when. Every progress
 *     update is kept: that is the person's progress record. A worker may
 *     report on their own goals; it is marked as their own account.
 *   - a development action (training, coaching, a certification) has an
 *     owner who follows it up and a due date. The owner is told, and it is
 *     overdue once the date passes.
 *
 * Who manages a person's goals and plan: recruiters, and the supervisor of
 * one of the person's live assignments. Payroll and clients see none of it.
 */

declare(strict_types=1);

require_once __DIR__ . '/appraisals.php';

function goal_statuses(): array
{
    return ['open' => t('Open'), 'achieved' => t('Achieved'), 'missed' => t('Missed'), 'dropped' => t('Dropped')];
}

function action_kinds(): array
{
    return ['training' => t('Training'), 'coaching' => t('Coaching'), 'certification' => t('Certification'), 'other' => t('Other')];
}

/** The candidate the signed-in worker is, or 0. */
function perf_self_candidate(): int
{
    return (int) val('SELECT candidate_id FROM worker_accounts WHERE user_id = ?', [uid()]);
}

/** May the signed-in person set and follow this person's goals and plan? */
function perf_can_manage(int $candidateId): bool
{
    if (can('recruiter')) {
        return true;
    }
    if ((user()['role'] ?? '') !== 'supervisor') {
        return false;
    }

    return (bool) val("SELECT COUNT(*) FROM placements p JOIN assignment_details d ON d.placement_id = p.id
                       WHERE p.candidate_id = ? AND d.supervisor_id = ? AND p.status NOT IN ('completed','cancelled')", [$candidateId, uid()]);
}

/** Is this refusal about who may act, rather than what was asked? Then it is a 403. */
function perf_forbidden(string $why): bool
{
    return in_array($why, [
        t('Only a recruiter, or this person\'s supervisor, sets their goals.'),
        t('Only a recruiter, this person\'s supervisor, or the person, reports on a goal.'),
        t('Only a recruiter, or this person\'s supervisor, closes a goal.'),
        t('Only a recruiter, or this person\'s supervisor, plans their development.'),
        t('Only its owner, a recruiter, or this person\'s supervisor, closes an action.'),
    ], true);
}

/** May the signed-in person see this person's goals and plan? */
function perf_can_see(int $candidateId): bool
{
    return perf_can_manage($candidateId) || ((user()['role'] ?? '') === 'worker' && perf_self_candidate() === $candidateId);
}

// ── cycles ──────────────────────────────────────────────────────────────

function cycle_create(array $in): array
{
    $name = trim((string) ($in['name'] ?? ''));
    $template = (int) ($in['template_id'] ?? 0);
    $job = (int) ($in['job_id'] ?? 0);
    $from = (string) ($in['period_from'] ?? '');
    $to = (string) ($in['period_to'] ?? '');
    $due = (string) ($in['due_on'] ?? '');

    if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
        return [0, t('Name the cycle in 3 to 120 characters.')];
    }
    if (! val('SELECT COUNT(*) FROM appraisal_templates WHERE id = ? AND is_active = 1', [$template])) {
        return [0, t('Choose a template in use, with at least one criterion.')];
    }
    if ($job && ! val('SELECT COUNT(*) FROM jobs WHERE id = ?', [$job])) {
        return [0, t('Choose a project, or all of them.')];
    }
    if (! valid_date($from) || ! valid_date($to) || $from > $to || ! valid_date($due) || $due < $to) {
        return [0, t('A cycle covers a period, the start first, and is due on or after its end.')];
    }

    q('INSERT INTO appraisal_cycles (name, template_id, job_id, period_from, period_to, due_on, created_by) VALUES (?,?,?,?,?,?,?)',
      [$name, $template, $job ?: null, $from, $to, $due, uid() ?: null]);

    return [(int) db()->lastInsertId(), null];
}

/**
 * Open the cycle: a review for every live assignment in scope that has none
 * in this cycle. Returns [opened, skipped (name => reason), refusal].
 */
function cycle_open(int $cycleId): array
{
    $c = row('SELECT * FROM appraisal_cycles WHERE id = ? FOR UPDATE', [$cycleId]);
    if (! $c) {
        return [0, [], t('That cycle does not exist.')];
    }
    if ($c['status'] === 'closed') {
        return [0, [], t('That cycle is closed.')];
    }

    $opened = 0;
    $skipped = [];
    $args = [$cycleId];
    $scope = '';
    if ($c['job_id']) {
        $scope = ' AND p.job_id = ?';
        $args[] = (int) $c['job_id'];
    }
    foreach (rows("SELECT p.id, c.full_name, j.title FROM placements p JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id
                   WHERE p.status IN ('confirmed','travelling','on_site') AND j.status = 'active'
                     AND NOT EXISTS (SELECT 1 FROM appraisals a WHERE a.placement_id = p.id AND a.cycle_id = ?)$scope
                   ORDER BY j.title, c.full_name", $args) as $p) {
        [$id, $why] = appraisal_open((int) $p['id'], (int) $c['template_id'], 0, (string) $c['period_from'], (string) $c['period_to']);
        if ($why !== null) {
            $skipped[$p['full_name'] . ' · ' . $p['title']] = $why;
            continue;
        }
        q('UPDATE appraisals SET cycle_id = ? WHERE id = ?', [$cycleId, $id]);
        $opened++;
    }
    q("UPDATE appraisal_cycles SET status = 'open', opened_at = COALESCE(opened_at, NOW()) WHERE id = ?", [$cycleId]);

    return [$opened, $skipped, null];
}

function cycle_close(int $cycleId, string $note): ?string
{
    $c = row('SELECT * FROM appraisal_cycles WHERE id = ? FOR UPDATE', [$cycleId]);
    if (! $c || $c['status'] !== 'open') {
        return t('Only an open cycle is closed.');
    }
    $left = (int) val("SELECT COUNT(*) FROM appraisals WHERE cycle_id = ? AND status NOT IN ('approved','cancelled')", [$cycleId]);
    if ($left && mb_strlen(trim($note)) < 3) {
        return t(':n review(s) in this cycle are not finished. Say why it closes anyway.', ['n' => $left]);
    }
    q("UPDATE appraisal_cycles SET status = 'closed', closed_at = NOW(), close_note = ? WHERE id = ?", [trim($note) ?: null, $cycleId]);

    return null;
}

/** Each cycle with its reviews counted by state. */
function cycles(): array
{
    return rows("SELECT c.*, t.label AS template, j.title AS project,
                        (SELECT COUNT(*) FROM appraisals a WHERE a.cycle_id = c.id) AS reviews,
                        (SELECT COUNT(*) FROM appraisals a WHERE a.cycle_id = c.id AND a.status = 'approved') AS approved,
                        (SELECT COUNT(*) FROM appraisals a WHERE a.cycle_id = c.id AND a.status NOT IN ('approved','cancelled')) AS waiting
                 FROM appraisal_cycles c JOIN appraisal_templates t ON t.id = c.template_id LEFT JOIN jobs j ON j.id = c.job_id
                 ORDER BY FIELD(c.status,'open','planned','closed'), c.due_on DESC");
}

// ── goals ───────────────────────────────────────────────────────────────

function goal_add(int $candidateId, array $in): array
{
    if (! perf_can_manage($candidateId)) {
        return [0, t('Only a recruiter, or this person\'s supervisor, sets their goals.')];
    }
    $title = trim((string) ($in['title'] ?? ''));
    $measure = trim((string) ($in['measure'] ?? ''));
    $target = (string) ($in['target_on'] ?? '');
    $appraisal = (int) ($in['appraisal_id'] ?? 0);
    if (mb_strlen($title) < 3 || mb_strlen($title) > 190) {
        return [0, t('Name the goal in 3 to 190 characters.')];
    }
    if (mb_strlen($measure) < 3) {
        return [0, t('Say how the goal is measured.')];
    }
    if (! valid_date($target) || $target < date('Y-m-d')) {
        return [0, t('A goal is due today or later.')];
    }
    if ($appraisal && ! val('SELECT COUNT(*) FROM appraisals WHERE id = ? AND candidate_id = ?', [$appraisal, $candidateId])) {
        return [0, t('That review is not this person\'s.')];
    }
    q('INSERT INTO performance_goals (candidate_id, appraisal_id, title, measure, target_on, created_by) VALUES (?,?,?,?,?,?)',
      [$candidateId, $appraisal ?: null, $title, mb_substr($measure, 0, 500), $target, uid() ?: null]);
    $id = (int) db()->lastInsertId();
    $worker = val('SELECT user_id FROM worker_accounts WHERE candidate_id = ?', [$candidateId]);
    if ($worker) {
        q('INSERT INTO notifications (user_id, message, target) VALUES (?,?,?)', [(int) $worker, 'A goal was set for you: ' . $title, '/performance']);
    }

    return [$id, null];
}

function goal_update(int $goalId, string $progress, string $note): ?string
{
    $g = row('SELECT * FROM performance_goals WHERE id = ? FOR UPDATE', [$goalId]);
    if (! $g) {
        return t('That goal does not exist.');
    }
    $self = (user()['role'] ?? '') === 'worker' && perf_self_candidate() === (int) $g['candidate_id'];
    if (! $self && ! perf_can_manage((int) $g['candidate_id'])) {
        return t('Only a recruiter, this person\'s supervisor, or the person, reports on a goal.');
    }
    if ($g['status'] !== 'open') {
        return t('That goal is closed.');
    }
    if (! is_numeric($progress) || (int) $progress != $progress || (int) $progress < 0 || (int) $progress > 100) {
        return t('Progress is a percentage from 0 to 100.');
    }
    if (mb_strlen(trim($note)) < 3) {
        return t('Say what was done.');
    }
    q('INSERT INTO goal_updates (goal_id, progress, note, by_self, user_id) VALUES (?,?,?,?,?)', [$goalId, (int) $progress, mb_substr(trim($note), 0, 1000), $self ? 1 : 0, uid() ?: null]);
    q('UPDATE performance_goals SET progress = ? WHERE id = ?', [(int) $progress, $goalId]);

    return null;
}

function goal_close(int $goalId, string $status, string $note): ?string
{
    $g = row('SELECT * FROM performance_goals WHERE id = ? FOR UPDATE', [$goalId]);
    if (! $g || ! perf_can_manage((int) $g['candidate_id'])) {
        return t('Only a recruiter, or this person\'s supervisor, closes a goal.');
    }
    if ($g['status'] !== 'open') {
        return t('That goal is closed.');
    }
    if (! in_array($status, ['achieved', 'missed', 'dropped'], true)) {
        return t('Unknown action.');
    }
    if (mb_strlen(trim($note)) < 3) {
        return t('Say how it ended.');
    }
    q('UPDATE performance_goals SET status = ?, close_note = ?, closed_by = ?, closed_at = NOW(), progress = IF(? = \'achieved\', 100, progress) WHERE id = ?',
      [$status, mb_substr(trim($note), 0, 500), uid() ?: null, $status, $goalId]);

    return null;
}

// ── development actions ─────────────────────────────────────────────────

function action_add(int $candidateId, array $in): array
{
    if (! perf_can_manage($candidateId)) {
        return [0, t('Only a recruiter, or this person\'s supervisor, plans their development.')];
    }
    $kind = (string) ($in['kind'] ?? '');
    $desc = trim((string) ($in['description'] ?? ''));
    $owner = (int) ($in['owner_id'] ?? 0);
    $due = (string) ($in['due_on'] ?? '');
    $appraisal = (int) ($in['appraisal_id'] ?? 0);
    if (! isset(action_kinds()[$kind])) {
        return [0, t('Choose what kind of action it is.')];
    }
    if (mb_strlen($desc) < 3 || mb_strlen($desc) > 500) {
        return [0, t('Describe the action in 3 to 500 characters.')];
    }
    if (! val("SELECT COUNT(*) FROM users WHERE id = ? AND is_active = 1 AND role IN ('admin','recruiter','supervisor')", [$owner])) {
        return [0, t('Choose who follows it up: a recruiter or a supervisor.')];
    }
    if (! valid_date($due) || $due < date('Y-m-d')) {
        return [0, t('An action is due today or later.')];
    }
    if ($appraisal && ! val('SELECT COUNT(*) FROM appraisals WHERE id = ? AND candidate_id = ?', [$appraisal, $candidateId])) {
        return [0, t('That review is not this person\'s.')];
    }
    q('INSERT INTO development_actions (candidate_id, appraisal_id, kind, description, owner_id, due_on, created_by) VALUES (?,?,?,?,?,?,?)',
      [$candidateId, $appraisal ?: null, $kind, $desc, $owner, $due, uid() ?: null]);
    $id = (int) db()->lastInsertId();
    q('INSERT INTO notifications (user_id, message, target) VALUES (?,?,?)', [$owner, 'A development action is yours to follow up: ' . $desc, '/performance']);

    return [$id, null];
}

function action_close(int $actionId, string $as, string $outcome): ?string
{
    $a = row('SELECT * FROM development_actions WHERE id = ? FOR UPDATE', [$actionId]);
    if (! $a) {
        return t('That action does not exist.');
    }
    if ((int) $a['owner_id'] !== uid() && ! perf_can_manage((int) $a['candidate_id'])) {
        return t('Only its owner, a recruiter, or this person\'s supervisor, closes an action.');
    }
    if ($a['status'] !== 'open') {
        return t('That action is closed.');
    }
    if (! in_array($as, ['done', 'cancelled'], true)) {
        return t('Unknown action.');
    }
    if (mb_strlen(trim($outcome)) < 3) {
        return $as === 'done' ? t('Say what came of it.') : t('Say why it is cancelled.');
    }
    q('UPDATE development_actions SET status = ?, outcome = ?, closed_by = ?, closed_at = NOW() WHERE id = ?', [$as, mb_substr(trim($outcome), 0, 1000), uid() ?: null, $actionId]);

    return null;
}

// ── what the screens read ───────────────────────────────────────────────

function perf_goals(array $where, array $args): array
{
    $goals = rows('SELECT g.*, c.full_name, u.name AS set_by FROM performance_goals g JOIN candidates c ON c.id = g.candidate_id LEFT JOIN users u ON u.id = g.created_by
                   WHERE ' . implode(' AND ', $where ?: ['1=1']) . " ORDER BY FIELD(g.status,'open','achieved','missed','dropped'), g.target_on", $args);
    foreach ($goals as &$g) {
        $g['updates'] = rows('SELECT x.*, u.name AS by_name FROM goal_updates x LEFT JOIN users u ON u.id = x.user_id WHERE x.goal_id = ? ORDER BY x.id DESC', [(int) $g['id']]);
        $g['overdue'] = $g['status'] === 'open' && $g['target_on'] < date('Y-m-d');
    }
    unset($g);

    return $goals;
}

function perf_actions(array $where, array $args): array
{
    $actions = rows('SELECT a.*, c.full_name, o.name AS owner FROM development_actions a JOIN candidates c ON c.id = a.candidate_id JOIN users o ON o.id = a.owner_id
                     WHERE ' . implode(' AND ', $where ?: ['1=1']) . " ORDER BY FIELD(a.status,'open','done','cancelled'), a.due_on", $args);
    foreach ($actions as &$a) {
        $a['overdue'] = $a['status'] === 'open' && $a['due_on'] < date('Y-m-d');
    }
    unset($a);

    return $actions;
}

/** One person's progress record, newest first: reviews, goals, updates, actions. */
function progress_record(int $candidateId): array
{
    $events = [];
    foreach (rows("SELECT a.decided_at, a.score_percent, a.grade, t.label FROM appraisals a JOIN appraisal_templates t ON t.id = a.template_id
                   WHERE a.candidate_id = ? AND a.status = 'approved'", [$candidateId]) as $r) {
        $events[] = ['at' => (string) $r['decided_at'], 'what' => t('Review approved'), 'detail' => t($r['label']) . ' · ' . number_format((float) $r['score_percent'], 1) . '% · ' . $r['grade']];
    }
    foreach (rows('SELECT * FROM performance_goals WHERE candidate_id = ?', [$candidateId]) as $g) {
        $events[] = ['at' => (string) $g['created_at'], 'what' => t('Goal set'), 'detail' => $g['title'] . ' · ' . t('by :date', ['date' => d((string) $g['target_on'])])];
        if ($g['closed_at']) {
            $events[] = ['at' => (string) $g['closed_at'], 'what' => t('Goal :status', ['status' => mb_strtolower(goal_statuses()[$g['status']])]), 'detail' => $g['title'] . ' · ' . $g['close_note']];
        }
        foreach (rows('SELECT * FROM goal_updates WHERE goal_id = ?', [(int) $g['id']]) as $x) {
            $events[] = ['at' => (string) $x['created_at'], 'what' => $x['by_self'] ? t('Progress, own account') : t('Progress'), 'detail' => $g['title'] . ' · ' . $x['progress'] . '% · ' . $x['note']];
        }
    }
    foreach (rows('SELECT * FROM development_actions WHERE candidate_id = ?', [$candidateId]) as $a) {
        $events[] = ['at' => (string) $a['created_at'], 'what' => t('Development action planned'), 'detail' => action_kinds()[$a['kind']] . ' · ' . $a['description'] . ' · ' . t('by :date', ['date' => d((string) $a['due_on'])])];
        if ($a['closed_at']) {
            $events[] = ['at' => (string) $a['closed_at'], 'what' => $a['status'] === 'done' ? t('Development action done') : t('Development action cancelled'), 'detail' => $a['description'] . ' · ' . $a['outcome']];
        }
    }
    usort($events, fn($x, $y) => strcmp($y['at'], $x['at']));

    return $events;
}
