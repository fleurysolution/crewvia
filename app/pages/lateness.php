<?php
/**
 * Lateness (T19): supervisors record a late arrival with the reason, the
 * worker reads and may dispute it, payroll sets the project's rules and
 * settles disputes. See app/lateness.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lateness.php';

require_role('payroll', 'supervisor', 'worker');
$ltOwn = is_worker_account();
$ltPayroll = can('payroll');
$ltSupervisor = user()['role'] === 'supervisor';
$ltJob = current_job();
$ltJobId = (int) ($ltJob['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    if (in_array($do, ['policy', 'assignment_shift', 'decide'], true) && ! $ltPayroll) {
        refuse(403, t('Payroll settles this.'), '/lateness');
    }
    if ($do === 'record' && ! $ltPayroll && ! $ltSupervisor) {
        refuse(403, t('A supervisor or payroll records late arrivals.'), '/lateness');
    }
    $why = match ($do) {
        'record'   => lateness_record((int) ($_POST['placement_id'] ?? 0), (string) ($_POST['work_date'] ?? ''), (string) ($_POST['arrived_at'] ?? ''), (string) ($_POST['reason'] ?? ''), uid()),
        'dispute'  => lateness_dispute((int) ($_POST['lateness_id'] ?? 0), (string) ($_POST['note'] ?? ''), uid()),
        'decide'   => lateness_decide((int) ($_POST['lateness_id'] ?? 0), (string) ($_POST['decision'] ?? ''), (int) ($_POST['minutes'] ?? -1), (string) ($_POST['note'] ?? ''), uid()),
        'policy'   => $ltJobId ? lateness_policy_save($ltJobId, $_POST, uid()) : t('Choose a working project first.'),
        'assignment_shift' => (static function () use ($ltJobId): ?string {
            $pid = (int) ($_POST['placement_id'] ?? 0);
            if (! row('SELECT id FROM placements WHERE id = ? AND job_id = ?', [$pid, $ltJobId])) {
                return t('Select a placement in this project.');
            }
            $given = trim((string) ($_POST['shift_start'] ?? ''));
            $at = $given === '' ? null : lateness_time($given);
            if ($given !== '' && ! $at) {
                return t('Enter the shift start as a time, such as 07:00.');
            }
            if (! row('SELECT placement_id FROM assignment_details WHERE placement_id = ?', [$pid])) {
                return t('Give this assignment its details (trade, supervisor) first.');
            }
            q('UPDATE assignment_details SET shift_start = ? WHERE placement_id = ?', [$at, $pid]);
            log_activity('set an assignment\'s shift start', 'placement', $pid, $at ?? 'project default');
            return null;
        })(),
        default    => t('Unknown action.'),
    };
    if ($why !== null) {
        refuse(422, $why, '/lateness');
    }
    flash(match ($do) {
        'record'  => t('Late arrival recorded. The worker sees it with your reason.'),
        'dispute' => t('Dispute sent to payroll. Nothing is docked until they settle it.'),
        'decide'  => t('Settled. The worker sees your decision.'),
        default   => t('Saved.'),
    });
    redirect('/lateness');
}

$ltPolicy = $ltJobId ? lateness_policy($ltJobId) : null;
$select = "SELECT l.*, c.full_name, j.title AS project, u.name AS recorded_by_name, x.name AS decided_by_name
           FROM attendance_lateness l JOIN placements p ON p.id = l.placement_id JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id
           LEFT JOIN users u ON u.id = l.recorded_by LEFT JOIN users x ON x.id = l.decided_by";
$ltCrew = [];
if ($ltOwn) {
    $ltList = rows($select . ' JOIN worker_accounts w ON w.candidate_id = p.candidate_id WHERE w.user_id = ? ORDER BY l.work_date DESC, l.id DESC LIMIT 200', [uid()]);
} elseif ($ltPayroll) {
    $ltList = rows($select . " WHERE p.job_id = ? ORDER BY l.status = 'disputed' DESC, l.work_date DESC, l.id DESC LIMIT 300", [$ltJobId]);
    $ltCrew = rows("SELECT p.id, c.full_name, d.shift_start, d.placement_id AS has_details FROM placements p JOIN candidates c ON c.id = p.candidate_id
                    LEFT JOIN assignment_details d ON d.placement_id = p.id WHERE p.job_id = ? AND p.status IN ('confirmed','travelling','on_site') ORDER BY c.full_name", [$ltJobId]);
} else {
    $ltList = rows($select . ' JOIN assignment_details d ON d.placement_id = p.id WHERE d.supervisor_id = ? ORDER BY l.work_date DESC, l.id DESC LIMIT 300', [uid()]);
    $ltCrew = rows("SELECT p.id, c.full_name, d.shift_start, d.placement_id AS has_details FROM placements p JOIN candidates c ON c.id = p.candidate_id
                    JOIN assignment_details d ON d.placement_id = p.id WHERE d.supervisor_id = ? AND p.status IN ('confirmed','travelling','on_site') ORDER BY c.full_name", [uid()]);
}
foreach ($ltList as &$l) {
    $l['frozen'] = attendance_week_frozen((int) $l['placement_id'], (string) $l['work_date']);
}
unset($l);
$ltFocus = (int) ($_GET['id'] ?? 0);
$pageTitle = t('Lateness') . ' · ' . $config['app_name'];
render('lateness', compact('ltOwn', 'ltPayroll', 'ltSupervisor', 'ltJob', 'ltPolicy', 'ltList', 'ltCrew', 'ltFocus'));
