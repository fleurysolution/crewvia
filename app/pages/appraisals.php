<?php
/**
 * Performance reviews (P2-M02). Recruiters open them and see them all; a
 * supervisor sees the ones they score or whose crew they run; a worker sees
 * their own while it asks for their view, and once it is approved. Payroll
 * and clients do not see them.
 */

require_once __DIR__ . '/../appraisals.php';

require_login();

$role = (string) (user()['role'] ?? '');
if (! in_array($role, ['worker', 'supervisor'], true)) {
    require_role('recruiter');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['appraisal_id'] ?? 0);

    if ($do === 'open') {
        require_role('recruiter');
        db()->beginTransaction();
        [$id, $why] = appraisal_open((int) ($_POST['placement_id'] ?? 0), (int) ($_POST['template_id'] ?? 0), (int) ($_POST['reviewer_id'] ?? 0),
                                     trim((string) ($_POST['period_from'] ?? '')), trim((string) ($_POST['period_to'] ?? '')));
        if ($why !== null) {
            db()->rollBack();
            refuse(422, $why);
        }
        db()->commit();
        log_activity('opened a performance review', 'appraisal', $id);
        flash(t('Review opened.'));
        redirect('/appraisals?id=' . $id);
    }

    $a = $id ? appraisal($id) : null;
    if (! $a || ! appraisal_can_view($a)) {
        refuse(404, t('That review does not exist.'));
    }

    $scores = is_array($_POST['score'] ?? null) ? $_POST['score'] : [];
    $notes = is_array($_POST['score_comment'] ?? null) ? $_POST['score_comment'] : [];

    if (in_array($do, ['skip', 'decide', 'cancel'], true)) {
        require_role('recruiter');
    }

    db()->beginTransaction();
    $why = match ($do) {
        'self'   => appraisal_self_submit($id, $scores, $notes, (string) ($_POST['comment'] ?? '')),
        'score'  => appraisal_supervisor_submit($id, $scores, $notes, (string) ($_POST['comment'] ?? ''), (string) ($_POST['would_rehire'] ?? '')),
        'skip'   => appraisal_skip_self($id, (string) ($_POST['reason'] ?? '')),
        'decide' => appraisal_decide($id, (string) ($_POST['decision'] ?? ''), (string) ($_POST['note'] ?? '')),
        'cancel' => appraisal_cancel($id, (string) ($_POST['reason'] ?? '')),
        default  => t('Unknown action.'),
    };
    if ($why !== null) {
        db()->rollBack();
        refuse(422, $why);
    }
    db()->commit();
    log_activity('performance review ' . $do, 'appraisal', $id);
    flash(t('Recorded.'));
    // A worker who has sent their view no longer sees the review until it
    // is approved, so they go back to their list.
    $after = appraisal($id);
    redirect($after && appraisal_can_view($after) ? '/appraisals?id=' . $id : '/appraisals');
}

$statuses = appraisal_statuses();

if (isset($_GET['id'])) {
    $a = appraisal((int) $_GET['id']);
    if (! $a || ! appraisal_can_view($a)) {
        refuse(404, t('That review does not exist.'));
    }
    $criteria = appraisal_template_criteria((int) $a['template_id']);
    $given = ['self' => [], 'supervisor' => []];
    foreach (rows('SELECT * FROM appraisal_scores WHERE appraisal_id = ?', [(int) $a['id']]) as $s) {
        $given[$s['rater']][$s['criterion_slug']] = $s;
    }
    // A worker never sees the reviewer's scores before approval, and the
    // reviewer sees the worker's own view only once it is submitted.
    $showSupervisor = $role !== 'worker' || $a['status'] === 'approved';
    $events = rows('SELECT e.*, u.name AS by_name FROM appraisal_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.appraisal_id = ? ORDER BY e.id', [(int) $a['id']]);
    $mayScore = $a['status'] === 'supervisor_review' && ! appraisal_is_subject($a) && (appraisal_is_reviewer($a) || can('recruiter'));
    $mayDecide = $a['status'] === 'awaiting_approval' && can('recruiter') && (int) $a['supervisor_by'] !== uid() && (int) $a['reviewer_id'] !== uid();
    $maySelf = $a['status'] === 'self_review' && appraisal_is_subject($a);
    render('appraisals', compact('a', 'criteria', 'given', 'showSupervisor', 'events', 'mayScore', 'mayDecide', 'maySelf', 'statuses', 'role'));
    return;
}

$status = (string) ($_GET['status'] ?? '');
$where = [];
$args = [];
if ($role === 'worker') {
    $where[] = "a.candidate_id = (SELECT candidate_id FROM worker_accounts WHERE user_id = ?) AND a.status IN ('self_review','approved')";
    $args[] = uid();
} elseif ($role === 'supervisor') {
    $where[] = '(a.reviewer_id = ? OR d.supervisor_id = ?)';
    array_push($args, uid(), uid());
}
if (isset($statuses[$status])) {
    $where[] = 'a.status = ?';
    $args[] = $status;
}

$list = rows('SELECT a.*, t.label AS template, c.full_name, j.title AS project, r.name AS reviewer
              FROM appraisals a JOIN appraisal_templates t ON t.id = a.template_id JOIN candidates c ON c.id = a.candidate_id
              JOIN placements p ON p.id = a.placement_id JOIN jobs j ON j.id = p.job_id
              LEFT JOIN assignment_details d ON d.placement_id = p.id LEFT JOIN users r ON r.id = a.reviewer_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY FIELD(a.status, \'awaiting_approval\', \'supervisor_review\', \'self_review\', \'approved\', \'cancelled\'), a.id DESC LIMIT 300', $args);

$crew = $templates = $reviewers = [];
if (can('recruiter')) {
    $job = current_job();
    $crew = rows("SELECT p.id, c.full_name, d.trade, u.name AS supervisor FROM placements p JOIN candidates c ON c.id = p.candidate_id
                  LEFT JOIN assignment_details d ON d.placement_id = p.id LEFT JOIN users u ON u.id = d.supervisor_id
                  WHERE p.job_id = ? AND p.status <> 'cancelled' ORDER BY c.full_name", [(int) ($job['id'] ?? 0)]);
    $templates = appraisal_templates();
    $reviewers = rows("SELECT id, name, role FROM users WHERE is_active = 1 AND role IN ('supervisor','recruiter','admin') ORDER BY name");
}

render('appraisals', compact('list', 'statuses', 'status', 'crew', 'templates', 'reviewers', 'role'));
