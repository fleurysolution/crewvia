<?php
/**
 * Benefits (P2-M04): plans, who to offer them to, and each person's
 * enrollments and waivers. Payroll and administrators manage it; a worker
 * sees their own coverage. Nobody else sees it.
 */

require_once __DIR__ . '/../benefits.php';

require_login();

$role = (string) (user()['role'] ?? '');
if ($role !== 'worker') {
    require_role('payroll');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('payroll');
    $do = (string) ($_POST['do'] ?? '');
    $candidate = (int) ($_POST['candidate_id'] ?? 0);

    db()->beginTransaction();
    $id = 0;
    switch ($do) {
        case 'plan':
            [$id, $why] = benefit_plan_create($_POST);
            break;
        case 'retire':
        case 'restore':
            $why = val('SELECT COUNT(*) FROM benefit_plans WHERE id = ?', [(int) ($_POST['plan_id'] ?? 0)]) ? null : t('That plan does not exist.');
            if ($why === null) {
                q('UPDATE benefit_plans SET is_active = ? WHERE id = ?', [$do === 'restore' ? 1 : 0, (int) $_POST['plan_id']]);
            }
            break;
        case 'enroll':
        case 'waive':
            $why = val('SELECT COUNT(*) FROM candidates WHERE id = ?', [$candidate]) ? null : t('That person does not exist.');
            if ($why === null) {
                [$id, $why] = benefit_enroll($candidate, (int) ($_POST['plan_id'] ?? 0), $_POST, $do === 'waive');
            }
            break;
        case 'end':
            $why = benefit_end((int) ($_POST['enrollment_id'] ?? 0), trim((string) ($_POST['ends_on'] ?? '')), (string) ($_POST['reason'] ?? ''));
            break;
        case 'change':
            [$id, $why] = benefit_change((int) ($_POST['enrollment_id'] ?? 0), $_POST);
            break;
        default:
            $why = t('Unknown action.');
    }
    if ($why !== null) {
        db()->rollBack();
        refuse(422, $why);
    }
    db()->commit();
    log_activity('benefits ' . $do, $candidate ? 'candidate' : 'benefit_plan', $candidate ?: $id, (string) ($_POST['plan_id'] ?? $_POST['name'] ?? ''));
    flash(t('Recorded.'));
    redirect($candidate ? '/benefits?candidate=' . $candidate : '/benefits');
}

$candidate = $role === 'worker' ? (int) val('SELECT candidate_id FROM worker_accounts WHERE user_id = ?', [uid()]) : (int) ($_GET['candidate'] ?? 0);
$plans = benefit_plans(false);
$person = null;
if ($candidate) {
    $person = [
        'id'      => $candidate,
        'name'    => (string) val('SELECT full_name FROM candidates WHERE id = ?', [$candidate]),
        'hired'   => benefit_hire_date($candidate),
        'type'    => (string) (val('SELECT employment_type FROM employee_profiles WHERE candidate_id = ?', [$candidate]) ?: 'hourly'),
        'history' => rows('SELECT e.*, p.name AS plan, p.kind, p.method FROM benefit_enrollments e JOIN benefit_plans p ON p.id = e.plan_id WHERE e.candidate_id = ? ORDER BY e.id DESC', [$candidate]),
    ];
}
$toOffer = $role === 'worker' ? [] : benefit_eligibility(array_filter($plans, fn($p) => (int) $p['is_active'] === 1));

render('benefits', compact('role', 'plans', 'person', 'toOffer'));
