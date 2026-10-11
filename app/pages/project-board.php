<?php
/**
 * Project board (P5-M02): the working project's tasks in columns by status,
 * and its sprints. A move goes through pt_update(): the same rules as any
 * change (who may move it, what it waits for, its subtasks) and a line in
 * the task's history.
 */

declare(strict_types=1);

require_login();
require_once __DIR__ . '/../project-planning.php';

$job = current_job();
$jobId = (int) ($job['id'] ?? 0);
if (! $jobId) {
    render('project-board', ['job' => null]);
    return;
}
if (! pt_can_view($jobId)) {
    refuse(403, t('Project tasks are open to the desks and to the supervisors of this project\'s crews.'));
}
$manage = pt_can_manage($jobId);

$sprints = pp_sprints($jobId);
$active = null;
foreach ($sprints as $s) { if ($s['status'] === 'active') { $active = $s; } }
$filter = [
    'sprint'    => isset($_REQUEST['sprint']) && $_REQUEST['sprint'] !== '' ? (int) $_REQUEST['sprint'] : ($active ? (int) $active['id'] : 0),
    'assignee'  => (int) ($_REQUEST['assignee'] ?? 0),
    'milestone' => (int) ($_REQUEST['milestone'] ?? 0),
];
$back = '/project-board?' . http_build_query(array_filter($filter, fn($v) => $v !== 0) + ['sprint' => $filter['sprint']]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'move') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $task = pt_task($taskId);
        if (! $task || (int) $task['job_id'] !== $jobId) { refuse(404, t('That task is not on this project.')); }
        if (($why = pt_update($taskId, array_intersect_key($_POST, array_flip(['status', 'blocked_reason'])), uid())) !== null) { refuse(422, $why); }
        flash(t('Task moved.'));
        redirect($back);
    }
    if ($do === 'sprint_save') {
        if (($why = pp_sprint_save($jobId, $_POST, uid(), (int) ($_POST['sprint_id'] ?? 0))) !== null) { refuse(422, $why); }
        flash(t('Sprint saved.'));
        redirect($back);
    }
    if ($do === 'sprint_start') {
        if (($why = pp_sprint_start($jobId, (int) ($_POST['sprint_id'] ?? 0), uid())) !== null) { refuse(422, $why); }
        flash(t('Sprint started.'));
        redirect('/project-board?sprint=' . (int) $_POST['sprint_id']);
    }
    if ($do === 'sprint_close') {
        [$why, $moved] = pp_sprint_close($jobId, (int) ($_POST['sprint_id'] ?? 0), uid());
        if ($why !== null) { refuse(422, $why); }
        flash(t('Sprint closed: :n open task(s) back to the backlog.', ['n' => $moved]));
        redirect('/project-board?sprint=-1');
    }
    refuse(400, t('Unknown action.'));
}

$columns = pp_board($jobId, $filter);
$milestones = pt_milestones($jobId);
$assignable = pt_assignable($jobId);
$today = date('Y-m-d');

render('project-board', compact('job', 'manage', 'sprints', 'active', 'filter', 'columns', 'milestones', 'assignable', 'today'));
