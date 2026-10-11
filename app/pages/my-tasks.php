<?php
/**
 * My tasks (P5-M01): what is assigned to me, on every project, open first.
 * Anybody signed in may have tasks, a worker placed on a project included;
 * they move their own along and write on them here.
 */

declare(strict_types=1);

require_login();
require_once __DIR__ . '/../project-tasks.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $taskId = (int) ($_POST['task_id'] ?? 0);
    $task = pt_task($taskId);
    if (! $task || (int) $task['assignee_id'] !== uid()) {
        refuse(404, t('That task is not yours.'));
    }
    $in = array_intersect_key($_POST, array_flip(['status', 'blocked_reason', 'note']));
    if (($why = pt_update($taskId, $in, uid())) !== null) {
        refuse(422, $why);
    }
    flash(t('Task updated.'));
    redirect('/my-tasks?task=' . $taskId);
}

$tasks = rows("SELECT t.*, j.title AS job_title, m.title AS milestone_title FROM project_tasks t
                 JOIN jobs j ON j.id = t.job_id LEFT JOIN project_milestones m ON m.id = t.milestone_id
                WHERE t.assignee_id = ? AND (t.status IN ('todo','in_progress','blocked') OR t.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) OR t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))
                ORDER BY FIELD(t.status,'blocked','in_progress','todo','done','cancelled'), FIELD(t.priority,'urgent','high','normal','low'),
                         t.due_on IS NULL, t.due_on, t.id LIMIT 300", [uid()]);
$focus = (int) ($_GET['task'] ?? 0);
$events = [];
foreach ($tasks as $tk) {
    if ((int) $tk['id'] === $focus) {
        $events = pt_events($focus);
    }
}
$today = date('Y-m-d');

render('my-tasks', compact('tasks', 'focus', 'events', 'today'));
