<?php
/**
 * Project tasks (P5-M01): the working project's milestones and tasks, its
 * progress, and one task's details and history. Seen by the desks and by
 * the supervisors of the project's crews; changed by its managers, and by
 * whoever a task is assigned to (see app/project-tasks.php).
 */

declare(strict_types=1);

require_login();
require_once __DIR__ . '/../project-tasks.php';

$job = current_job();
$jobId = (int) ($job['id'] ?? 0);

if (! $jobId) {
    render('project-tasks', ['job' => null]);
    return;
}
if (! pt_can_view($jobId)) {
    refuse(403, t('Project tasks are open to the desks and to the supervisors of this project\'s crews.'));
}
$manage = pt_can_manage($jobId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'create') {
        [$why, $id] = pt_create($jobId, $_POST, uid());
        if ($why !== null) { refuse(422, $why); }
        flash(t('Task created.'));
        redirect('/project-tasks?task=' . $id);
    }
    if ($do === 'update') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $task = pt_task($taskId);
        if (! $task || (int) $task['job_id'] !== $jobId) { refuse(404, t('That task is not on this project.')); }
        $in = array_intersect_key($_POST, array_flip(['title', 'description', 'priority', 'due_on', 'milestone_id', 'assignee_id', 'status', 'blocked_reason', 'note']));
        if (($why = pt_update($taskId, $in, uid())) !== null) { refuse(422, $why); }
        flash(t('Task updated.'));
        redirect('/project-tasks?task=' . $taskId);
    }
    if ($do === 'milestone_save') {
        if (($why = pt_milestone_save($jobId, $_POST, uid(), (int) ($_POST['milestone_id'] ?? 0))) !== null) { refuse(422, $why); }
        flash(t('Milestone saved.'));
        redirect('/project-tasks#milestones');
    }
    if ($do === 'milestone_cancel') {
        if (($why = pt_milestone_cancel($jobId, (int) ($_POST['milestone_id'] ?? 0), uid())) !== null) { refuse(422, $why); }
        flash(t('Milestone cancelled.'));
        redirect('/project-tasks#milestones');
    }
    refuse(400, t('Unknown action.'));
}

$filter = [
    'status'    => in_array($_GET['status'] ?? '', ['open', 'all', ...array_keys(pt_statuses())], true) ? (string) $_GET['status'] : 'open',
    'assignee'  => (int) ($_GET['assignee'] ?? 0),
    'milestone' => (int) ($_GET['milestone'] ?? 0),
    'priority'  => isset(pt_priorities()[$_GET['priority'] ?? '']) ? (string) $_GET['priority'] : '',
    'overdue'   => ! empty($_GET['overdue']),
];
$where = ['t.job_id = ?'];
$args = [$jobId];
if ($filter['status'] === 'open') { $where[] = "t.status IN ('todo','in_progress','blocked')"; }
elseif ($filter['status'] !== 'all') { $where[] = 't.status = ?'; $args[] = $filter['status']; }
if ($filter['assignee']) { $where[] = 't.assignee_id = ?'; $args[] = $filter['assignee']; }
if ($filter['milestone']) { $where[] = 't.milestone_id = ?'; $args[] = $filter['milestone']; }
if ($filter['priority'] !== '') { $where[] = 't.priority = ?'; $args[] = $filter['priority']; }
if ($filter['overdue']) { $where[] = "t.status IN ('todo','in_progress','blocked') AND t.due_on < ?"; $args[] = date('Y-m-d'); }

$tasks = rows('SELECT t.*, u.name AS assignee_name, m.title AS milestone_title FROM project_tasks t
                 LEFT JOIN users u ON u.id = t.assignee_id LEFT JOIN project_milestones m ON m.id = t.milestone_id
                WHERE ' . implode(' AND ', $where) . "
                ORDER BY FIELD(t.status,'blocked','in_progress','todo','done','cancelled'), FIELD(t.priority,'urgent','high','normal','low'),
                         t.due_on IS NULL, t.due_on, t.id LIMIT 500", $args);
$progress = pt_progress($jobId);
$milestones = pt_milestones($jobId);
$assignable = pt_assignable($jobId);
$detail = null;
$events = [];
if (($taskId = (int) ($_GET['task'] ?? 0)) && ($found = pt_task($taskId)) && (int) $found['job_id'] === $jobId) {
    $detail = $found;
    $events = pt_events($taskId);
}
$today = date('Y-m-d');

render('project-tasks', compact('job', 'manage', 'filter', 'tasks', 'progress', 'milestones', 'assignable', 'detail', 'events', 'today'));
