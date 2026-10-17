<?php
/**
 * Project tasks (P5-M01): milestones, the tasks that reach them, who does
 * each, by when, how urgent, where it stands, and everything that happened
 * to it.
 *
 * Who manages a project's tasks: administrators and recruiters on any
 * project; a supervisor on a project they supervise a crew on (the rule
 * P4-M06 set for purchasing). The desks see the tasks. Whoever is assigned
 * moves their own task along and writes notes on it, a worker included,
 * from My tasks.
 *
 * A task is assigned to someone who can sign in: a member of staff, or a
 * worker placed on that same project. Nothing goes out of Crewvia: an
 * assignment is an internal notification.
 */

declare(strict_types=1);

/** @return array<string,string> */
function pt_statuses(): array
{
    return ['todo' => t('To do'), 'in_progress' => t('In progress'), 'blocked' => t('Blocked'), 'done' => t('Done'), 'cancelled' => t('Cancelled')];
}

/** @return array<string,string> */
function pt_priorities(): array
{
    return ['urgent' => t('Urgent'), 'high' => t('High'), 'normal' => t('Normal'), 'low' => t('Low')];
}

const PT_OPEN = ['todo', 'in_progress', 'blocked'];

/**
 * P5-M02: progress counts the work itself. A task with live subtasks is
 * counted through them, not as one more task beside them (alias t).
 */
const PT_LEAF = "NOT EXISTS (SELECT 1 FROM project_tasks c WHERE c.parent_id = t.id AND c.status <> 'cancelled')";

function pt_can_manage(int $jobId): bool
{
    if (($u = user()) === null || $jobId <= 0) {
        return false;
    }
    if (($u['role'] ?? '') === 'supervisor') {
        return in_array($jobId, supervised_job_ids(uid()), true);
    }
    return can('recruiter');
}

function pt_can_view(int $jobId): bool
{
    if (in_array(user()['role'] ?? '', ['worker', 'client'], true)) {
        return false;
    }
    return pt_can_manage($jobId) || can('recruiter', 'hotels', 'payroll');
}

/** People a task on this project can go to: active staff, and the crew placed on it with an account. */
function pt_assignable(int $jobId): array
{
    return rows("SELECT u.id, u.name, u.role, 'staff' AS kind FROM users u
                  WHERE u.is_active = 1 AND u.role IN ('admin','recruiter','hr','payroll','hotels','supervisor')
                    AND NOT EXISTS (SELECT 1 FROM worker_accounts w WHERE w.user_id = u.id)
                 UNION
                 SELECT DISTINCT u.id, u.name, u.role, 'crew' AS kind FROM users u
                   JOIN worker_accounts w ON w.user_id = u.id
                   JOIN placements p ON p.candidate_id = w.candidate_id AND p.job_id = ? AND p.status <> 'cancelled'
                  WHERE u.is_active = 1
                 ORDER BY kind DESC, name", [$jobId]);
}

function pt_assignee_refusal(int $jobId, int $userId): ?string
{
    foreach (pt_assignable($jobId) as $a) {
        if ((int) $a['id'] === $userId) {
            return null;
        }
    }
    return t('A task goes to a member of staff, or to a worker placed on this project.');
}

function pt_task(int $id): ?array
{
    return row('SELECT t.*, u.name AS assignee_name, m.title AS milestone_title, j.title AS job_title
                  FROM project_tasks t
                  LEFT JOIN users u ON u.id = t.assignee_id
                  LEFT JOIN project_milestones m ON m.id = t.milestone_id
                  JOIN jobs j ON j.id = t.job_id
                 WHERE t.id = ?', [$id]) ?: null;
}

function pt_events(int $taskId): array
{
    return rows('SELECT e.*, u.name AS by_name FROM project_task_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.task_id = ? ORDER BY e.id', [$taskId]);
}

/** One line of a task's history, in words. */
function pt_event_text(array $ev): string
{
    $label = ['created' => t('Created'), 'assigned' => t('Assigned'), 'status' => t('Status'), 'priority' => t('Priority'), 'deadline' => t('Deadline'),
              'milestone' => t('Milestone'), 'title' => t('Title of the task'), 'description' => t('Description changed'), 'note' => t('Note'),
              'estimate' => t('Estimate'), 'parent' => t('Subtask of'), 'sprint' => t('Sprint'), 'dependency' => t('Waits for')][$ev['kind']] ?? $ev['kind'];
    $value = function (?string $v) use ($ev): string {
        if ($v === null || $v === '') { return '–'; }
        return match ($ev['kind']) {
            'status' => pt_statuses()[$v] ?? $v,
            'priority' => pt_priorities()[$v] ?? $v,
            'deadline' => d($v),
            'estimate' => t(':h h', ['h' => rtrim(rtrim($v, '0'), '.')]),
            default => $v,
        };
    };
    if ($ev['kind'] === 'created') { return $label . ': ' . (string) $ev['new_value']; }
    if (in_array($ev['kind'], ['note', 'description'], true)) { return $label; }
    return $label . ': ' . $value($ev['old_value']) . ' → ' . $value($ev['new_value']);
}

function pt_event(int $taskId, int $by, string $kind, ?string $old, ?string $new, ?string $note = null): void
{
    q('INSERT INTO project_task_events (task_id, user_id, kind, old_value, new_value, note) VALUES (?,?,?,?,?,?)',
      [$taskId, $by ?: null, $kind, $old === null ? null : mb_substr($old, 0, 255), $new === null ? null : mb_substr($new, 0, 255), $note === null ? null : mb_substr($note, 0, 1000)]);
}

function pt_notify(?int $userId, int $by, string $message, string $target): void
{
    if ($userId && $userId !== $by) {
        q('INSERT INTO notifications (user_id, message, target) VALUES (?,?,?)', [$userId, mb_substr($message, 0, 255), $target]);
    }
}

/** A milestone of this project still open to tasks, or a refusal. */
function pt_milestone_refusal(int $jobId, int $milestoneId): ?string
{
    $m = row('SELECT job_id, cancelled_at FROM project_milestones WHERE id = ?', [$milestoneId]);
    return $m && (int) $m['job_id'] === $jobId && $m['cancelled_at'] === null ? null : t('Choose a milestone of this project.');
}

function pt_date_refusal(string $date): ?string
{
    return $date === '' || (valid_date($date) && $date >= '2000-01-01' && $date <= '2100-12-31') ? null : t('Give the deadline as a date.');
}

/** P5-M02: why $parentId cannot hold $taskId (0 for a new task) as a subtask, or null. */
function pt_parent_refusal(int $jobId, int $parentId, int $taskId = 0): ?string
{
    $p = row('SELECT id, job_id, parent_id, status FROM project_tasks WHERE id = ?', [$parentId]);
    if (! $p || (int) $p['job_id'] !== $jobId) { return t('A subtask belongs to a task of the same project.'); }
    if ($taskId && $parentId === $taskId) { return t('A task cannot be its own subtask.'); }
    if ($p['parent_id'] !== null) { return t('Subtasks go one level deep: choose a task that is not itself a subtask.'); }
    if ($taskId && val('SELECT COUNT(*) FROM project_tasks WHERE parent_id = ?', [$taskId])) { return t('This task has subtasks of its own, so it cannot become a subtask.'); }
    if (in_array($p['status'], ['done', 'cancelled'], true)) { return t('That task is closed: reopen it before adding subtasks to it.'); }
    return null;
}

/** An estimate in hours: empty, or more than 0 and at most 999. */
function pt_estimate_refusal(string $hours): ?string
{
    return $hours === '' || (is_numeric($hours) && (float) $hours > 0 && (float) $hours <= 999) ? null : t('Give the estimate in hours, more than 0 and at most 999.');
}

/** A sprint of this project that is not closed, or a refusal. */
function pt_sprint_refusal(int $jobId, int $sprintId): ?string
{
    $s = row('SELECT job_id, status FROM project_sprints WHERE id = ?', [$sprintId]);
    return $s && (int) $s['job_id'] === $jobId && $s['status'] !== 'closed' ? null : t('Choose a sprint of this project that is not closed.');
}

/** What a task still waits for: its prerequisites not done or cancelled. */
function pt_waiting_on(int $taskId): array
{
    return rows("SELECT p.id, p.title, p.status FROM project_task_dependencies d JOIN project_tasks p ON p.id = d.depends_on_id
                  WHERE d.task_id = ? AND p.status IN ('todo','in_progress','blocked') ORDER BY p.id", [$taskId]);
}

/** Does $from already lead, through what it waits for, to $target? */
function pt_depends_path(int $from, int $target): bool
{
    $seen = [];
    $queue = [$from];
    while ($queue) {
        $id = array_shift($queue);
        if ($id === $target) { return true; }
        if (isset($seen[$id])) { continue; }
        $seen[$id] = true;
        foreach (array_column(rows('SELECT depends_on_id FROM project_task_dependencies WHERE task_id = ?', [$id]), 'depends_on_id') as $next) {
            $queue[] = (int) $next;
        }
    }
    return false;
}

/** $taskId waits for $dependsOn. Managers only; same project; no loop. */
function pt_dependency_add(int $taskId, int $dependsOn, int $by): ?string
{
    $a = row('SELECT id, job_id, title FROM project_tasks WHERE id = ?', [$taskId]);
    $b = row('SELECT id, job_id, title FROM project_tasks WHERE id = ?', [$dependsOn]);
    if (! $a || ! $b || (int) $a['job_id'] !== (int) $b['job_id']) { return t('A task waits only for another task of the same project.'); }
    if (! pt_can_manage((int) $a['job_id'])) { return t('Dependencies are set by whoever manages this project\'s tasks.'); }
    if ($taskId === $dependsOn) { return t('A task cannot wait for itself.'); }
    if (val('SELECT COUNT(*) FROM project_task_dependencies WHERE task_id = ? AND depends_on_id = ?', [$taskId, $dependsOn])) { return t('This task already waits for that one.'); }
    if (pt_depends_path($dependsOn, $taskId)) { return t('That would make a loop: :b already waits, directly or not, for :a.', ['a' => $a['title'], 'b' => $b['title']]); }
    q('INSERT INTO project_task_dependencies (task_id, depends_on_id, created_by) VALUES (?,?,?)', [$taskId, $dependsOn, $by]);
    pt_event($taskId, $by, 'dependency', null, $b['title']);
    log_activity('made a task wait for another', 'project_task', $taskId, '#' . $taskId . ' waits for #' . $dependsOn);
    return null;
}

function pt_dependency_remove(int $taskId, int $dependsOn, int $by): ?string
{
    $a = row('SELECT job_id FROM project_tasks WHERE id = ?', [$taskId]);
    if (! $a || ! pt_can_manage((int) $a['job_id'])) { return t('Dependencies are set by whoever manages this project\'s tasks.'); }
    if (! q('DELETE FROM project_task_dependencies WHERE task_id = ? AND depends_on_id = ?', [$taskId, $dependsOn])->rowCount()) { return t('This task does not wait for that one.'); }
    pt_event($taskId, $by, 'dependency', (string) val('SELECT title FROM project_tasks WHERE id = ?', [$dependsOn]), null);
    log_activity('removed a task dependency', 'project_task', $taskId, '#' . $taskId . ' no longer waits for #' . $dependsOn);
    return null;
}

/** Creates a task. Returns [refusal, new id]. */
function pt_create(int $jobId, array $in, int $by): array
{
    if (! pt_can_manage($jobId)) {
        return [t('Tasks on this project are created by its managers: administrators, recruiters and the supervisors of its crews.'), null];
    }
    $title = trim((string) ($in['title'] ?? ''));
    $description = trim((string) ($in['description'] ?? ''));
    $priority = (string) ($in['priority'] ?? 'normal');
    $due = trim((string) ($in['due_on'] ?? ''));
    $milestone = (int) ($in['milestone_id'] ?? 0);
    $assignee = (int) ($in['assignee_id'] ?? 0);
    $parent = (int) ($in['parent_id'] ?? 0);
    $estimate = trim((string) ($in['estimate_hours'] ?? ''));
    $sprint = (int) ($in['sprint_id'] ?? 0);
    if ($title === '' || mb_strlen($title) > 190) { return [t('Give the task a title of at most 190 characters.'), null]; }
    if (mb_strlen($description) > 5000) { return [t('Keep the description under 5,000 characters.'), null]; }
    if (! isset(pt_priorities()[$priority])) { return [t('Choose a priority.'), null]; }
    if (($why = pt_date_refusal($due)) !== null) { return [$why, null]; }
    if ($milestone && ($why = pt_milestone_refusal($jobId, $milestone)) !== null) { return [$why, null]; }
    if ($assignee && ($why = pt_assignee_refusal($jobId, $assignee)) !== null) { return [$why, null]; }
    if ($parent && ($why = pt_parent_refusal($jobId, $parent)) !== null) { return [$why, null]; }
    if (($why = pt_estimate_refusal($estimate)) !== null) { return [$why, null]; }
    if ($sprint && ($why = pt_sprint_refusal($jobId, $sprint)) !== null) { return [$why, null]; }
    // A subtask sits under its parent's milestone and sprint unless told otherwise.
    if ($parent) {
        $up = row('SELECT milestone_id, sprint_id FROM project_tasks WHERE id = ?', [$parent]);
        $milestone = $milestone ?: (int) $up['milestone_id'];
        $sprint = $sprint ?: (int) $up['sprint_id'];
    }

    q('INSERT INTO project_tasks (job_id, milestone_id, parent_id, sprint_id, title, description, assignee_id, priority, due_on, estimate_hours, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
      [$jobId, $milestone ?: null, $parent ?: null, $sprint ?: null, $title, $description !== '' ? $description : null, $assignee ?: null, $priority, $due !== '' ? $due : null,
       $estimate !== '' ? round((float) $estimate, 1) : null, $by]);
    $id = (int) db()->lastInsertId();
    pt_event($id, $by, 'created', null, $title);
    if ($parent) {
        pt_event($id, $by, 'parent', null, (string) val('SELECT title FROM project_tasks WHERE id = ?', [$parent]));
    }
    if ($assignee) {
        pt_event($id, $by, 'assigned', null, (string) val('SELECT name FROM users WHERE id = ?', [$assignee]));
        pt_notify($assignee, $by, t('Task for you: :title', ['title' => $title]), '/my-tasks?task=' . $id);
    }
    log_activity('created a project task', 'project_task', $id, $title);
    return [null, $id];
}

/**
 * Changes a task. Only the keys present in $in are considered. Whoever is
 * assigned may change the status (but not cancel or reopen a cancelled task)
 * and add a note; the project's managers may change everything.
 */
function pt_update(int $taskId, array $in, int $by): ?string
{
    db()->beginTransaction();
    try {
        $t = row('SELECT * FROM project_tasks WHERE id = ? FOR UPDATE', [$taskId]);
        if (! $t) {
            db()->rollBack();
            return t('That task does not exist.');
        }
        $jobId = (int) $t['job_id'];
        $manage = pt_can_manage($jobId);
        $mine = (int) $t['assignee_id'] === $by && $by > 0;
        if (! $manage && ! $mine) {
            db()->rollBack();
            return t('Only the person assigned, or whoever manages this project\'s tasks, changes this task.');
        }
        if (! $manage && array_intersect_key($in, array_flip(['title', 'description', 'priority', 'due_on', 'milestone_id', 'assignee_id', 'parent_id', 'estimate_hours', 'sprint_id']))) {
            db()->rollBack();
            return t('You can move your task along and write on it; its other details are set by whoever manages the project\'s tasks.');
        }

        $set = [];
        $events = [];
        $statuses = pt_statuses();

        if (array_key_exists('title', $in)) {
            $title = trim((string) $in['title']);
            if ($title === '' || mb_strlen($title) > 190) { db()->rollBack(); return t('Give the task a title of at most 190 characters.'); }
            if ($title !== $t['title']) { $set['title'] = $title; $events[] = ['title', $t['title'], $title]; }
        }
        if (array_key_exists('description', $in)) {
            $d = trim((string) $in['description']);
            if (mb_strlen($d) > 5000) { db()->rollBack(); return t('Keep the description under 5,000 characters.'); }
            if ($d !== (string) $t['description']) { $set['description'] = $d !== '' ? $d : null; $events[] = ['description', null, null]; }
        }
        if (array_key_exists('priority', $in)) {
            $p = (string) $in['priority'];
            if (! isset(pt_priorities()[$p])) { db()->rollBack(); return t('Choose a priority.'); }
            if ($p !== $t['priority']) { $set['priority'] = $p; $events[] = ['priority', $t['priority'], $p]; }
        }
        if (array_key_exists('due_on', $in)) {
            $due = trim((string) $in['due_on']);
            if (($why = pt_date_refusal($due)) !== null) { db()->rollBack(); return $why; }
            if ($due !== (string) $t['due_on']) { $set['due_on'] = $due !== '' ? $due : null; $events[] = ['deadline', $t['due_on'], $due !== '' ? $due : null]; }
        }
        if (array_key_exists('milestone_id', $in)) {
            $m = (int) $in['milestone_id'];
            if ($m && ($why = pt_milestone_refusal($jobId, $m)) !== null) { db()->rollBack(); return $why; }
            if ($m !== (int) $t['milestone_id']) {
                $set['milestone_id'] = $m ?: null;
                $events[] = ['milestone', $t['milestone_id'] ? (string) val('SELECT title FROM project_milestones WHERE id = ?', [$t['milestone_id']]) : null,
                             $m ? (string) val('SELECT title FROM project_milestones WHERE id = ?', [$m]) : null];
            }
        }
        $newAssignee = null;
        if (array_key_exists('assignee_id', $in)) {
            $a = (int) $in['assignee_id'];
            if ($a && ($why = pt_assignee_refusal($jobId, $a)) !== null) { db()->rollBack(); return $why; }
            if ($a !== (int) $t['assignee_id']) {
                $set['assignee_id'] = $a ?: null;
                $newAssignee = $a ?: null;
                $events[] = ['assigned', $t['assignee_id'] ? (string) val('SELECT name FROM users WHERE id = ?', [$t['assignee_id']]) : null,
                             $a ? (string) val('SELECT name FROM users WHERE id = ?', [$a]) : null];
            }
        }
        if (array_key_exists('estimate_hours', $in)) {
            $h = trim((string) $in['estimate_hours']);
            if (($why = pt_estimate_refusal($h)) !== null) { db()->rollBack(); return $why; }
            $hv = $h !== '' ? number_format(round((float) $h, 1), 1, '.', '') : null;
            if ($hv !== ($t['estimate_hours'] === null ? null : number_format((float) $t['estimate_hours'], 1, '.', ''))) {
                $set['estimate_hours'] = $hv;
                $events[] = ['estimate', $t['estimate_hours'] === null ? null : number_format((float) $t['estimate_hours'], 1, '.', ''), $hv];
            }
        }
        if (array_key_exists('parent_id', $in)) {
            $pp = (int) $in['parent_id'];
            if ($pp && ($why = pt_parent_refusal($jobId, $pp, $taskId)) !== null) { db()->rollBack(); return $why; }
            if ($pp !== (int) $t['parent_id']) {
                $set['parent_id'] = $pp ?: null;
                $events[] = ['parent', $t['parent_id'] ? (string) val('SELECT title FROM project_tasks WHERE id = ?', [$t['parent_id']]) : null,
                             $pp ? (string) val('SELECT title FROM project_tasks WHERE id = ?', [$pp]) : null];
            }
        }
        if (array_key_exists('sprint_id', $in)) {
            $sp = (int) $in['sprint_id'];
            if ($sp && ($why = pt_sprint_refusal($jobId, $sp)) !== null) { db()->rollBack(); return $why; }
            if ($sp !== (int) $t['sprint_id']) {
                $set['sprint_id'] = $sp ?: null;
                $events[] = ['sprint', $t['sprint_id'] ? (string) val('SELECT name FROM project_sprints WHERE id = ?', [$t['sprint_id']]) : null,
                             $sp ? (string) val('SELECT name FROM project_sprints WHERE id = ?', [$sp]) : null];
            }
        }
        $statusNote = null;
        if (array_key_exists('status', $in)) {
            $s = (string) $in['status'];
            if (! isset($statuses[$s])) { db()->rollBack(); return t('Choose a status.'); }
            if (! $manage && ($s === 'cancelled' || $t['status'] === 'cancelled')) {
                db()->rollBack();
                return t('Only whoever manages this project\'s tasks cancels a task or reopens a cancelled one.');
            }
            $reason = trim((string) ($in['blocked_reason'] ?? ''));
            if ($s === 'blocked' && (mb_strlen($reason) < 3 || mb_strlen($reason) > 500)) {
                db()->rollBack();
                return t('Say what the task is waiting for, so someone can unblock it.');
            }
            // P5-M02: work does not start or finish ahead of what it waits for,
            // and a task does not close while its subtasks are open.
            if ($s !== $t['status'] && in_array($s, ['in_progress', 'done'], true) && ($waiting = pt_waiting_on($taskId))) {
                db()->rollBack();
                return t('This task waits for: :list. Finish those first, or remove the dependency.', ['list' => implode(', ', array_column($waiting, 'title'))]);
            }
            if ($s !== $t['status'] && in_array($s, ['done', 'cancelled'], true)
                && ($open = (int) val("SELECT COUNT(*) FROM project_tasks WHERE parent_id = ? AND status IN ('todo','in_progress','blocked')", [$taskId]))) {
                db()->rollBack();
                return tn($open, 'Close its open subtask first.', 'Close its :n open subtasks first.');
            }
            if ($s !== $t['status'] || ($s === 'blocked' && $reason !== (string) $t['blocked_reason'])) {
                $set['status'] = $s;
                $set['blocked_reason'] = $s === 'blocked' ? $reason : null;
                if ($s === 'done') { $set['completed_at'] = date('Y-m-d H:i:s'); }
                elseif ($t['status'] === 'done') { $set['completed_at'] = null; }
                $statusNote = $s === 'blocked' ? $reason : null;
                $events[] = ['status', $t['status'], $s, $statusNote];
            }
        }
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($note) > 1000) { db()->rollBack(); return t('Keep a note under 1,000 characters.'); }
        if (! $set && $note === '') {
            db()->rollBack();
            return t('Nothing changed.');
        }

        if ($set) {
            q('UPDATE project_tasks SET ' . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($set))) . ' WHERE id = ?', [...array_values($set), $taskId]);
        }
        foreach ($events as $e) {
            pt_event($taskId, $by, $e[0], $e[1], $e[2], $e[3] ?? null);
        }
        if ($note !== '') {
            pt_event($taskId, $by, 'note', null, null, $note);
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        throw $e;
    }

    $title = (string) ($set['title'] ?? $t['title']);
    if ($newAssignee) {
        pt_notify($newAssignee, $by, t('Task for you: :title', ['title' => $title]), '/my-tasks?task=' . $taskId);
    }
    // P5-M85: an onboarding task done hands travel to the project coordinator.
    if (isset($set['status']) && $set['status'] === 'done') {
        require_once __DIR__ . '/reviews.php';
        review_hr_task_done($taskId, $by);
    }
    if (isset($set['status']) && in_array($set['status'], ['blocked', 'done'], true)) {
        pt_notify((int) $t['created_by'], $by, ($set['status'] === 'done' ? t('Task done: :title', ['title' => $title]) : t('Task blocked: :title', ['title' => $title])),
                  '/project-tasks?task=' . $taskId);
    }
    log_activity('updated a project task', 'project_task', $taskId,
                 implode('; ', array_map(fn($e) => $e[0] . ($e[0] === 'description' ? '' : ': ' . ($e[1] ?? '-') . ' -> ' . ($e[2] ?? '-')), $events)) . ($note !== '' ? ($events ? '; ' : '') . 'note' : ''));
    return null;
}

/** Saves a milestone (new when $id is 0). */
function pt_milestone_save(int $jobId, array $in, int $by, int $id = 0): ?string
{
    if (! pt_can_manage($jobId)) {
        return t('Milestones on this project are set by its managers.');
    }
    $title = trim((string) ($in['title'] ?? ''));
    $description = trim((string) ($in['description'] ?? ''));
    $due = trim((string) ($in['due_on'] ?? ''));
    if ($title === '' || mb_strlen($title) > 190) { return t('Give the milestone a title of at most 190 characters.'); }
    if (mb_strlen($description) > 5000) { return t('Keep the description under 5,000 characters.'); }
    if (($why = pt_date_refusal($due)) !== null) { return $why; }
    if ($id) {
        if (($why = pt_milestone_refusal($jobId, $id)) !== null) { return $why; }
        q('UPDATE project_milestones SET title = ?, description = ?, due_on = ? WHERE id = ?', [$title, $description ?: null, $due ?: null, $id]);
        log_activity('changed a milestone', 'project_milestone', $id, $title . ($due ? ' by ' . $due : ''));
        return null;
    }
    q('INSERT INTO project_milestones (job_id, title, description, due_on, created_by) VALUES (?,?,?,?,?)', [$jobId, $title, $description ?: null, $due ?: null, $by]);
    log_activity('set a milestone', 'project_milestone', (int) db()->lastInsertId(), $title . ($due ? ' by ' . $due : ''));
    return null;
}

function pt_milestone_cancel(int $jobId, int $id, int $by): ?string
{
    if (! pt_can_manage($jobId)) {
        return t('Milestones on this project are set by its managers.');
    }
    if (($why = pt_milestone_refusal($jobId, $id)) !== null) {
        return $why;
    }
    if ((int) val("SELECT COUNT(*) FROM project_tasks WHERE milestone_id = ? AND status IN ('todo','in_progress','blocked')", [$id])) {
        return t('Tasks are still open under this milestone: close them or move them to another one first.');
    }
    q('UPDATE project_milestones SET cancelled_at = NOW(), cancelled_by = ? WHERE id = ?', [$by, $id]);
    log_activity('cancelled a milestone', 'project_milestone', $id);
    return null;
}

/**
 * The project's milestones, each with its live and done tasks. Reached: it has
 * live tasks and all are done. Overdue: its date has passed and it is not.
 */
function pt_milestones(int $jobId): array
{
    $today = date('Y-m-d');
    $out = rows("SELECT m.*, COALESCE(SUM(t.status <> 'cancelled'), 0) AS live, COALESCE(SUM(t.status = 'done'), 0) AS done
                   FROM project_milestones m LEFT JOIN project_tasks t ON t.milestone_id = m.id AND " . PT_LEAF . "
                  WHERE m.job_id = ? GROUP BY m.id ORDER BY m.cancelled_at IS NOT NULL, m.due_on IS NULL, m.due_on, m.id", [$jobId]);
    foreach ($out as &$m) {
        $m['live'] = (int) $m['live'];
        $m['done'] = (int) $m['done'];
        $m['reached'] = $m['cancelled_at'] === null && $m['live'] > 0 && $m['done'] === $m['live'];
        $m['overdue'] = $m['cancelled_at'] === null && ! $m['reached'] && $m['due_on'] !== null && $m['due_on'] < $today;
        $m['percent'] = $m['live'] ? (int) floor($m['done'] * 100 / $m['live']) : null;
    }
    return $out;
}

/** Progress of one project: tasks by status, done over live, overdue, milestones reached. */
function pt_progress(int $jobId): array
{
    $by = array_column(rows('SELECT t.status, COUNT(*) AS n FROM project_tasks t WHERE t.job_id = ? AND ' . PT_LEAF . ' GROUP BY t.status', [$jobId]), 'n', 'status');
    $counts = [];
    foreach (array_keys(pt_statuses()) as $s) {
        $counts[$s] = (int) ($by[$s] ?? 0);
    }
    $live = array_sum($counts) - $counts['cancelled'];
    $milestones = array_filter(pt_milestones($jobId), fn($m) => $m['cancelled_at'] === null);
    return [
        'counts' => $counts,
        'live' => $live,
        'done' => $counts['done'],
        'open' => $counts['todo'] + $counts['in_progress'] + $counts['blocked'],
        'percent' => $live ? (int) floor($counts['done'] * 100 / $live) : null,
        'overdue' => (int) val("SELECT COUNT(*) FROM project_tasks t WHERE t.job_id = ? AND t.status IN ('todo','in_progress','blocked') AND t.due_on < ? AND " . PT_LEAF, [$jobId, date('Y-m-d')]),
        'milestones' => count($milestones),
        'milestones_reached' => count(array_filter($milestones, fn($m) => $m['reached'])),
    ];
}

/** Progress of every project in one query: [job_id => [live, done, open, overdue, percent]]. */
function pt_progress_all(): array
{
    try {
        $rows = rows("SELECT t.job_id, SUM(t.status <> 'cancelled') AS live, SUM(t.status = 'done') AS done,
                             SUM(t.status IN ('todo','in_progress','blocked')) AS open,
                             SUM(t.status IN ('todo','in_progress','blocked') AND t.due_on < ?) AS overdue
                        FROM project_tasks t WHERE " . PT_LEAF . " GROUP BY t.job_id", [date('Y-m-d')]);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $live = (int) $r['live'];
        $out[(int) $r['job_id']] = ['live' => $live, 'done' => (int) $r['done'], 'open' => (int) $r['open'], 'overdue' => (int) $r['overdue'],
                                    'percent' => $live ? (int) floor((int) $r['done'] * 100 / $live) : null];
    }
    return $out;
}
