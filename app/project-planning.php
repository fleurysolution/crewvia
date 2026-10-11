<?php
/**
 * Project planning (P5-M02), on top of P5-M01's tasks:
 *
 *  - the board: a project's tasks in columns by status, moved through
 *    pt_update(), so a move is checked and recorded like any change;
 *  - sprints, for a project that wants them: planned, active (one at a
 *    time), closed; closing returns its open tasks to the backlog;
 *  - capacity: the hours a week each person gives to tasks, 40 unless set,
 *    less approved time off (Phase 2's time_off_requests, read where it is);
 *  - workload: each person's open hours per week against that capacity,
 *    and who could take a task from someone over it.
 *
 * Counts here are of the work itself: a task with live subtasks is counted
 * through them (PT_LEAF).
 */

declare(strict_types=1);

require_once __DIR__ . '/project-tasks.php';

const PP_DEFAULT_HOURS = 40.0;
const PP_WEEKS = 4;

/** The board: open and done tasks of a project by status, with subtask and waiting counts (three queries in all). */
function pp_board(int $jobId, array $filter): array
{
    $where = ["t.job_id = ?", "t.status <> 'cancelled'"];
    $args = [$jobId];
    if ($filter['sprint'] === -1) { $where[] = 't.sprint_id IS NULL'; }
    elseif ($filter['sprint'] > 0) { $where[] = 't.sprint_id = ?'; $args[] = $filter['sprint']; }
    if ($filter['assignee'] > 0) { $where[] = 't.assignee_id = ?'; $args[] = $filter['assignee']; }
    if ($filter['milestone'] > 0) { $where[] = 't.milestone_id = ?'; $args[] = $filter['milestone']; }
    $tasks = rows('SELECT t.id, t.title, t.status, t.priority, t.due_on, t.estimate_hours, t.assignee_id, t.parent_id, t.blocked_reason, u.name AS assignee_name, p.title AS parent_title
                     FROM project_tasks t LEFT JOIN users u ON u.id = t.assignee_id LEFT JOIN project_tasks p ON p.id = t.parent_id
                    WHERE ' . implode(' AND ', $where) . "
                    ORDER BY FIELD(t.priority,'urgent','high','normal','low'), t.due_on IS NULL, t.due_on, t.id LIMIT 1000", $args);
    $subs = [];
    foreach (rows("SELECT parent_id, SUM(status <> 'cancelled') AS live, SUM(status = 'done') AS done FROM project_tasks WHERE job_id = ? AND parent_id IS NOT NULL GROUP BY parent_id", [$jobId]) as $r) {
        $subs[(int) $r['parent_id']] = ['live' => (int) $r['live'], 'done' => (int) $r['done']];
    }
    $waiting = [];
    foreach (rows("SELECT d.task_id, COUNT(*) AS n FROM project_task_dependencies d JOIN project_tasks p ON p.id = d.depends_on_id JOIN project_tasks t ON t.id = d.task_id
                    WHERE t.job_id = ? AND p.status IN ('todo','in_progress','blocked') GROUP BY d.task_id", [$jobId]) as $r) {
        $waiting[(int) $r['task_id']] = (int) $r['n'];
    }
    $columns = ['todo' => [], 'in_progress' => [], 'blocked' => [], 'done' => []];
    foreach ($tasks as $tk) {
        $tk['subtasks'] = $subs[(int) $tk['id']] ?? null;
        $tk['waiting'] = $waiting[(int) $tk['id']] ?? 0;
        $columns[$tk['status']][] = $tk;
    }
    return $columns;
}

/** The moves offered on a card, as status => label. */
function pp_moves(string $status): array
{
    $all = ['todo' => t('To do'), 'in_progress' => t('Start'), 'blocked' => t('Mark blocked'), 'done' => t('Done')];
    unset($all[$status]);
    if ($status === 'done') {
        return ['in_progress' => t('Reopen')];
    }
    return $all;
}

// ── sprints ──────────────────────────────────────────────────────────────

/** @return array<string,string> */
function pp_sprint_statuses(): array
{
    return ['planned' => t('Planned'), 'active' => t('Active'), 'closed' => t('Closed')];
}

/** The project's sprints, each with its tasks done and live, and hours. */
function pp_sprints(int $jobId): array
{
    return rows("SELECT s.*, COALESCE(SUM(t.status <> 'cancelled'), 0) AS live, COALESCE(SUM(t.status = 'done'), 0) AS done,
                        COALESCE(SUM(CASE WHEN t.status <> 'cancelled' THEN t.estimate_hours END), 0) AS hours,
                        COALESCE(SUM(CASE WHEN t.status = 'done' THEN t.estimate_hours END), 0) AS hours_done
                   FROM project_sprints s LEFT JOIN project_tasks t ON t.sprint_id = s.id AND " . PT_LEAF . "
                  WHERE s.job_id = ? GROUP BY s.id ORDER BY FIELD(s.status,'active','planned','closed'), s.starts_on, s.id", [$jobId]);
}

function pp_sprint_save(int $jobId, array $in, int $by, int $id = 0): ?string
{
    if (! pt_can_manage($jobId)) { return t('Sprints on this project are planned by its managers.'); }
    $name = trim((string) ($in['name'] ?? ''));
    $goal = trim((string) ($in['goal'] ?? ''));
    $from = (string) ($in['starts_on'] ?? '');
    $to = (string) ($in['ends_on'] ?? '');
    if ($name === '' || mb_strlen($name) > 120) { return t('Give the sprint a name of at most 120 characters.'); }
    if (mb_strlen($goal) > 500) { return t('Keep the goal under 500 characters.'); }
    if (! valid_date($from) || ! valid_date($to) || $to < $from) { return t('A sprint runs from a start date to an end date on or after it.'); }
    if ((strtotime($to) - strtotime($from)) / 86400 > 90) { return t('A sprint lasts at most 90 days.'); }
    if ($id) {
        $s = row('SELECT * FROM project_sprints WHERE id = ? AND job_id = ?', [$id, $jobId]);
        if (! $s || $s['status'] === 'closed') { return t('Choose a sprint of this project that is not closed.'); }
        q('UPDATE project_sprints SET name = ?, goal = ?, starts_on = ?, ends_on = ? WHERE id = ?', [$name, $goal ?: null, $from, $to, $id]);
        log_activity('changed a sprint', 'project_sprint', $id, $name . ' ' . $from . ' to ' . $to);
        return null;
    }
    q('INSERT INTO project_sprints (job_id, name, goal, starts_on, ends_on, created_by) VALUES (?,?,?,?,?,?)', [$jobId, $name, $goal ?: null, $from, $to, $by]);
    log_activity('planned a sprint', 'project_sprint', (int) db()->lastInsertId(), $name . ' ' . $from . ' to ' . $to);
    return null;
}

/** Starts a planned sprint; only one is active per project. */
function pp_sprint_start(int $jobId, int $id, int $by): ?string
{
    if (! pt_can_manage($jobId)) { return t('Sprints on this project are planned by its managers.'); }
    db()->beginTransaction();
    $s = row('SELECT * FROM project_sprints WHERE id = ? AND job_id = ? FOR UPDATE', [$id, $jobId]);
    if (! $s || $s['status'] !== 'planned') { db()->rollBack(); return t('Only a planned sprint of this project can start.'); }
    if (val("SELECT COUNT(*) FROM project_sprints WHERE job_id = ? AND status = 'active' FOR UPDATE", [$jobId])) {
        db()->rollBack();
        return t('Another sprint is active on this project: close it first.');
    }
    q("UPDATE project_sprints SET status = 'active' WHERE id = ?", [$id]);
    db()->commit();
    log_activity('started a sprint', 'project_sprint', $id, $s['name']);
    return null;
}

/** Closes the active sprint; its open tasks go back to the backlog, each change in its history. */
function pp_sprint_close(int $jobId, int $id, int $by): array
{
    if (! pt_can_manage($jobId)) { return [t('Sprints on this project are planned by its managers.'), 0]; }
    db()->beginTransaction();
    $s = row('SELECT * FROM project_sprints WHERE id = ? AND job_id = ? FOR UPDATE', [$id, $jobId]);
    if (! $s || $s['status'] !== 'active') { db()->rollBack(); return [t('Only the active sprint can be closed.'), 0]; }
    $open = rows("SELECT id FROM project_tasks WHERE sprint_id = ? AND status IN ('todo','in_progress','blocked') FOR UPDATE", [$id]);
    foreach ($open as $o) {
        q('UPDATE project_tasks SET sprint_id = NULL WHERE id = ?', [(int) $o['id']]);
        pt_event((int) $o['id'], $by, 'sprint', $s['name'], null, t('Back to the backlog when the sprint closed.'));
    }
    q("UPDATE project_sprints SET status = 'closed', closed_at = NOW(), returned_count = ? WHERE id = ?", [count($open), $id]);
    db()->commit();
    log_activity('closed a sprint', 'project_sprint', $id, $s['name'] . ': ' . count($open) . ' open task(s) back to the backlog');
    return [null, count($open)];
}

// ── capacity and workload ────────────────────────────────────────────────

/** Monday of the week holding $date. */
function pp_week_start(string $date): string
{
    return date('Y-m-d', strtotime($date . ' -' . ((int) date('N', strtotime($date)) - 1) . ' days'));
}

/** Hours a week each person gives to tasks: [user id => hours], 40 unless set. */
function pp_capacities(array $userIds): array
{
    $out = array_fill_keys($userIds, PP_DEFAULT_HOURS);
    if ($userIds) {
        foreach (rows('SELECT user_id, hours_per_week FROM user_capacity WHERE user_id IN (' . implode(',', array_map('intval', $userIds)) . ')') as $r) {
            $out[(int) $r['user_id']] = (float) $r['hours_per_week'];
        }
    }
    return $out;
}

function pp_capacity_set(int $userId, string $hours, int $by): ?string
{
    if (! can('recruiter')) { return t('Weekly capacity is set by administrators and recruiters.'); }
    if (! row('SELECT id FROM users WHERE id = ? AND is_active = 1', [$userId])) { return t('That person does not exist or is switched off.'); }
    if (! is_numeric($hours) || (float) $hours < 0 || (float) $hours > 80) { return t('Give the weekly capacity in hours, from 0 to 80.'); }
    $before = pp_capacities([$userId])[$userId];
    q('INSERT INTO user_capacity (user_id, hours_per_week, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE hours_per_week = VALUES(hours_per_week), updated_by = VALUES(updated_by)',
      [$userId, round((float) $hours, 1), $by]);
    log_activity('set a weekly capacity', 'user', $userId, $before . ' -> ' . round((float) $hours, 1) . ' h');
    return null;
}

/**
 * Approved time off, as weekdays per person per week: [user id => [week start => days]].
 * Read from Phase 2's time_off_requests, for the weeks shown.
 */
function pp_leave_days(array $userIds, array $weeks): array
{
    $out = [];
    if (! $userIds || ! $weeks) { return $out; }
    $from = $weeks[0];
    $to = date('Y-m-d', strtotime(end($weeks) . ' +6 days'));
    foreach (rows("SELECT user_id, starts_on, ends_on FROM time_off_requests WHERE status = 'approved' AND starts_on <= ? AND ends_on >= ?
                    AND user_id IN (" . implode(',', array_map('intval', $userIds)) . ')', [$to, $from]) as $r) {
        for ($d = max($r['starts_on'], $from); $d <= min($r['ends_on'], $to); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if ((int) date('N', strtotime($d)) <= 5) {
                $w = pp_week_start($d);
                $out[(int) $r['user_id']][$w] = ($out[(int) $r['user_id']][$w] ?? 0) + 1;
            }
        }
    }
    return $out;
}

/**
 * The workload of the projects $jobIds (null: every project): per person,
 * the open hours of their tasks per week for PP_WEEKS weeks from this one
 * (overdue counted in this week), later and undated hours, unestimated
 * tasks, and the capacity of each week less approved time off.
 */
function pp_workload(?array $jobIds): array
{
    $weeks = [];
    $monday = pp_week_start(date('Y-m-d'));
    for ($i = 0; $i < PP_WEEKS; $i++) { $weeks[] = date('Y-m-d', strtotime($monday . " +$i weeks")); }
    $last = date('Y-m-d', strtotime(end($weeks) . ' +6 days'));
    if ($jobIds === []) { return ['weeks' => $weeks, 'people' => [], 'tasks' => []]; }
    $tasks = rows("SELECT t.id, t.job_id, t.title, t.assignee_id, t.due_on, t.estimate_hours, t.status, j.title AS job_title, u.name AS assignee_name
                     FROM project_tasks t JOIN jobs j ON j.id = t.job_id JOIN users u ON u.id = t.assignee_id
                    WHERE t.status IN ('todo','in_progress','blocked') AND " . PT_LEAF
                  . ($jobIds !== null ? ' AND t.job_id IN (' . implode(',', array_map('intval', $jobIds)) . ')' : '') . ' ORDER BY t.id');
    $people = [];
    foreach ($tasks as &$tk) {
        $uid = (int) $tk['assignee_id'];
        $people[$uid] ??= ['user_id' => $uid, 'name' => $tk['assignee_name'], 'open' => 0, 'unestimated' => 0, 'later' => 0.0, 'undated' => 0.0, 'overdue' => 0,
                           'planned' => array_fill_keys($weeks, 0.0)];
        $h = $tk['estimate_hours'] === null ? 0.0 : (float) $tk['estimate_hours'];
        $people[$uid]['open']++;
        if ($tk['estimate_hours'] === null) { $people[$uid]['unestimated']++; }
        if ($tk['due_on'] === null) { $people[$uid]['undated'] += $h; $tk['week'] = null; }
        elseif ($tk['due_on'] > $last) { $people[$uid]['later'] += $h; $tk['week'] = null; }
        else {
            $w = $tk['due_on'] < $weeks[0] ? $weeks[0] : pp_week_start($tk['due_on']);
            if ($tk['due_on'] < date('Y-m-d')) { $people[$uid]['overdue']++; }
            $people[$uid]['planned'][$w] += $h;
            $tk['week'] = $w;
        }
    }
    unset($tk);
    $ids = array_keys($people);
    $cap = pp_capacities($ids);
    $leave = pp_leave_days($ids, $weeks);
    foreach ($people as $uid => &$p) {
        $p['hours_per_week'] = $cap[$uid];
        foreach ($weeks as $w) {
            $p['capacity'][$w] = max(0.0, round($cap[$uid] - ($leave[$uid][$w] ?? 0) * $cap[$uid] / 5, 1));
            $p['leave_days'][$w] = $leave[$uid][$w] ?? 0;
            $p['over'][$w] = $p['planned'][$w] > $p['capacity'][$w];
        }
    }
    unset($p);
    // Whoever is over capacity in any week first, then by name.
    uasort($people, fn($a, $b) => [in_array(true, $a['over'], true) ? 0 : 1, $a['name']] <=> [in_array(true, $b['over'], true) ? 0 : 1, $b['name']]);
    return ['weeks' => $weeks, 'people' => $people, 'tasks' => $tasks];
}

/**
 * For each week someone is over capacity: their largest estimated task that
 * week, and the person on that task's project with the most room that week
 * who could take it without going over. At most $limit suggestions.
 */
function pp_suggestions(array $workload, int $limit = 20): array
{
    $people = $workload['people'];
    $out = [];
    $assignable = [];
    foreach ($people as $uid => $p) {
        foreach ($workload['weeks'] as $w) {
            if (! $p['over'][$w]) { continue; }
            $mine = array_filter($workload['tasks'], fn($tk) => (int) $tk['assignee_id'] === $uid && ($tk['week'] ?? null) === $w && $tk['estimate_hours'] !== null);
            usort($mine, fn($a, $b) => (float) $b['estimate_hours'] <=> (float) $a['estimate_hours']);
            foreach ($mine as $tk) {
                $jobId = (int) $tk['job_id'];
                $assignable[$jobId] ??= pt_assignable($jobId);
                $best = null;
                $cap = pp_capacities(array_map(fn($a) => (int) $a['id'], $assignable[$jobId]));
                $leave = pp_leave_days(array_keys($cap), $workload['weeks']);
                foreach ($assignable[$jobId] as $a) {
                    $aid = (int) $a['id'];
                    if ($aid === $uid) { continue; }
                    $capacity = max(0.0, $cap[$aid] - ($leave[$aid][$w] ?? 0) * $cap[$aid] / 5);
                    $room = $capacity - ($people[$aid]['planned'][$w] ?? 0.0);
                    if ($room >= (float) $tk['estimate_hours'] && ($best === null || $room > $best['room'])) {
                        $best = ['user_id' => $aid, 'name' => $a['name'], 'room' => $room];
                    }
                }
                if ($best) {
                    $out[] = ['task' => $tk, 'week' => $w, 'from' => $p['name'], 'to' => $best];
                    break;
                }
            }
            if (count($out) >= $limit) { return $out; }
        }
    }
    return $out;
}

/** Projects whose workload a person may see: every one for the desks, their own for a supervisor. */
function pp_workload_scope(): ?array
{
    if ((user()['role'] ?? '') === 'supervisor') {
        return supervised_job_ids(uid());
    }
    return can('recruiter') ? null : [];
}
