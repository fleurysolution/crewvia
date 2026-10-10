<?php
/**
 * Performance (P2-M03): evaluation cycles for recruiters, goals and
 * development plans person by person, and what is overdue. A supervisor
 * sees their crew; a worker sees their own goals and plan and reports on
 * their goals. Payroll and clients do not see it.
 */

require_once __DIR__ . '/../performance.php';

require_login();

$role = (string) (user()['role'] ?? '');
if (! in_array($role, ['worker', 'supervisor'], true)) {
    require_role('recruiter');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $candidate = (int) ($_POST['candidate_id'] ?? 0);
    if (str_starts_with($do, 'cycle_')) {
        require_role('recruiter');
    }

    db()->beginTransaction();
    $id = 0;
    $extra = '';
    switch ($do) {
        case 'cycle_create':
            [$id, $why] = cycle_create($_POST);
            break;
        case 'cycle_open':
            [$opened, $skipped, $why] = cycle_open((int) ($_POST['cycle_id'] ?? 0));
            if ($why === null) {
                $extra = t(':n review(s) opened.', ['n' => $opened]) . ($skipped ? ' ' . t('Not opened: :list', ['list' => implode('; ', array_map(fn($k, $v) => $k . ' (' . $v . ')', array_keys($skipped), $skipped))]) : '');
            }
            break;
        case 'cycle_close':
            $why = cycle_close((int) ($_POST['cycle_id'] ?? 0), (string) ($_POST['note'] ?? ''));
            break;
        case 'goal_add':
            [$id, $why] = goal_add($candidate, $_POST);
            break;
        case 'goal_update':
            $why = goal_update((int) ($_POST['goal_id'] ?? 0), trim((string) ($_POST['progress'] ?? '')), (string) ($_POST['note'] ?? ''));
            break;
        case 'goal_close':
            $why = goal_close((int) ($_POST['goal_id'] ?? 0), (string) ($_POST['status'] ?? ''), (string) ($_POST['note'] ?? ''));
            break;
        case 'action_add':
            [$id, $why] = action_add($candidate, $_POST);
            break;
        case 'action_close':
            $why = action_close((int) ($_POST['action_id'] ?? 0), (string) ($_POST['as'] ?? ''), (string) ($_POST['outcome'] ?? ''));
            break;
        default:
            $why = t('Unknown action.');
    }
    if ($why !== null) {
        db()->rollBack();
        refuse(perf_forbidden($why) ? 403 : 422, $why);
    }
    db()->commit();
    log_activity('performance ' . $do, $candidate ? 'candidate' : 'cycle', $candidate ?: $id, (string) ($_POST['title'] ?? $_POST['description'] ?? $_POST['note'] ?? ''));
    flash($extra !== '' ? $extra : t('Recorded.'));
    redirect($candidate ? '/performance?candidate=' . $candidate : '/performance');
}

$candidate = (int) ($_GET['candidate'] ?? 0);
if ($role === 'worker') {
    $candidate = perf_self_candidate();
}

$person = null;
if ($candidate) {
    if (! perf_can_see($candidate)) {
        refuse(404, t('That person is not yours to see.'));
    }
    $person = [
        'id'       => $candidate,
        'name'     => (string) val('SELECT full_name FROM candidates WHERE id = ?', [$candidate]),
        'manage'   => perf_can_manage($candidate),
        'goals'    => perf_goals(['g.candidate_id = ?'], [$candidate]),
        'actions'  => perf_actions(['a.candidate_id = ?'], [$candidate]),
        'record'   => $role === 'worker' ? [] : progress_record($candidate),
        'reviews'  => rows("SELECT a.id, t.label, a.status FROM appraisals a JOIN appraisal_templates t ON t.id = a.template_id WHERE a.candidate_id = ? AND a.status <> 'cancelled' ORDER BY a.id DESC", [$candidate]),
        'appraisal'=> (int) ($_GET['appraisal'] ?? 0),
    ];
}

$owners = rows("SELECT id, name FROM users WHERE is_active = 1 AND role IN ('admin','recruiter','supervisor') ORDER BY name");
$mine = perf_actions(['a.owner_id = ?', "a.status = 'open'"], [uid()]);
$crew = $role === 'supervisor'
    ? rows("SELECT DISTINCT c.id, c.full_name, j.title FROM placements p JOIN assignment_details d ON d.placement_id = p.id JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id
            WHERE d.supervisor_id = ? AND p.status NOT IN ('completed','cancelled') ORDER BY c.full_name", [uid()]) : [];
$cycles = can('recruiter') ? cycles() : [];
$templates = can('recruiter') ? appraisal_templates() : [];
$jobs = can('recruiter') ? rows("SELECT id, title FROM jobs ORDER BY title") : [];
$overdueGoals = can('recruiter') ? perf_goals(["g.status = 'open'", 'g.target_on < CURDATE()'], []) : [];
$overdueActions = can('recruiter') ? perf_actions(["a.status = 'open'", 'a.due_on < CURDATE()'], []) : [];

render('performance', compact('role', 'person', 'owners', 'mine', 'crew', 'cycles', 'templates', 'jobs', 'overdueGoals', 'overdueActions'));
