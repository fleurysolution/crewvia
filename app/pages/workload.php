<?php
/**
 * Workload (P5-M02): each person's open task hours per week against what
 * they can give, over the next four weeks, and who could take a task from
 * someone over capacity. The desks see every project; a supervisor sees
 * the projects they supervise a crew on. Administrators and recruiters set
 * the weekly capacity; a reassignment goes through pt_update() like any.
 */

declare(strict_types=1);

require_login();
require_once __DIR__ . '/../project-planning.php';

if (! can('recruiter') && (user()['role'] ?? '') !== 'supervisor') {
    refuse(403, t('Workload is open to administrators, recruiters and supervisors.'));
}
$scope = pp_workload_scope();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'capacity') {
        if (($why = pp_capacity_set((int) ($_POST['user_id'] ?? 0), trim((string) ($_POST['hours_per_week'] ?? '')), uid())) !== null) { refuse(422, $why); }
        flash(t('Weekly capacity saved.'));
        redirect('/workload');
    }
    if ($do === 'reassign') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $task = pt_task($taskId);
        if (! $task || ($scope !== null && ! in_array((int) $task['job_id'], $scope, true))) { refuse(404, t('That task is not on a project you plan.')); }
        if (($why = pt_update($taskId, ['assignee_id' => (int) ($_POST['assignee_id'] ?? 0), 'note' => t('Reassigned to balance the workload.')], uid())) !== null) { refuse(422, $why); }
        flash(t('Task reassigned.'));
        redirect('/workload');
    }
    refuse(400, t('Unknown action.'));
}

$workload = pp_workload($scope);
$suggestions = pp_suggestions($workload);
$canSetCapacity = can('recruiter');

render('workload', compact('workload', 'suggestions', 'canSetCapacity'));
