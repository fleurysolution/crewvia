<?php
/**
 * HR records (P2-M06): recognition, disciplinary cases and separations,
 * behind controlled access. See app/hr-records.php for who sees what.
 */

require_once __DIR__ . '/../hr-records.php';

require_login();

$role = (string) (user()['role'] ?? '');
if (in_array($role, ['payroll', 'hotels'], true) && ! hr_access()) {
    require_role('recruiter');
}
if ($role === 'client') {
    require_role('admin');
}

$forbidden = [
    t('Only a recruiter, or this person\'s supervisor, records recognition.'), t('Only HR, or this person\'s supervisor, opens a case.'),
    t('Only HR adds to a case.'), t('Only HR records an outcome.'), t('Only an administrator decides a termination.'),
    t('Only the person, once the outcome is recorded, answers a case.'), t('Only HR closes a case.'), t('Only HR records a separation.'),
    t('Only an administrator grants HR access.'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $candidate = (int) ($_POST['candidate_id'] ?? 0);
    $case = (int) ($_POST['case_id'] ?? 0);
    $c = $case ? row('SELECT * FROM disciplinary_cases WHERE id = ? FOR UPDATE', [$case]) : null;
    if ($case && (! $c || ! case_visible($c))) {
        refuse(404, t('That case does not exist.'));
    }

    db()->beginTransaction();
    $id = 0;
    switch ($do) {
        case 'recognise':
            [$id, $why] = recognition_add($candidate, $_POST);
            break;
        case 'case_open':
            [$id, $why] = case_open($candidate, $_POST);
            break;
        case 'case_note':
            $why = case_add_note($c, (string) ($_POST['note'] ?? ''));
            break;
        case 'case_decide':
            $why = case_decide($c, (string) ($_POST['outcome'] ?? ''), (string) ($_POST['note'] ?? ''));
            break;
        case 'case_respond':
            $why = case_respond($c, (string) ($_POST['response'] ?? ''), ! empty($_POST['acknowledge']));
            break;
        case 'case_close':
            $why = case_close($c, (string) ($_POST['note'] ?? ''));
            break;
        case 'separation':
            [$id, $why] = separation_record((int) ($_POST['placement_id'] ?? 0), $_POST);
            break;
        case 'grant':
            $why = hr_grant((int) ($_POST['user_id'] ?? 0), (string) ($_POST['reason'] ?? ''));
            break;
        case 'revoke':
            $why = hr_revoke((int) ($_POST['user_id'] ?? 0), (string) ($_POST['reason'] ?? ''));
            break;
        default:
            $why = t('Unknown action.');
    }
    if ($why !== null) {
        db()->rollBack();
        refuse(in_array($why, $forbidden, true) ? 403 : 422, $why);
    }
    db()->commit();
    log_activity('hr records ' . $do, 'candidate', $candidate ?: (int) ($c['candidate_id'] ?? 0));
    flash(t('Recorded.'));
    redirect($do === 'case_open' ? '/hr-records?case=' . $id : ($c ? '/hr-records?case=' . (int) $c['id'] : ($candidate ? '/hr-records?candidate=' . $candidate : '/hr-records')));
}

$hr = hr_access();
$self = hr_self_candidate();

if (isset($_GET['case'])) {
    $c = row('SELECT d.*, p.full_name, o.name AS opened_by_name, x.name AS decided_by_name FROM disciplinary_cases d JOIN candidates p ON p.id = d.candidate_id
              LEFT JOIN users o ON o.id = d.opened_by LEFT JOIN users x ON x.id = d.decided_by WHERE d.id = ?', [(int) $_GET['case']]);
    if (! $c || ! case_visible($c)) {
        refuse(404, t('That case does not exist.'));
    }
    if ($hr) {
        hr_log('opened a case', (int) $c['candidate_id'], $c['reference']);
    }
    $notes = rows('SELECT n.*, u.name AS by_name FROM disciplinary_notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.case_id = ? ORDER BY n.id', [(int) $c['id']]);
    render('hr-records', ['mode' => 'case', 'c' => $c, 'notes' => $notes, 'hr' => $hr, 'self' => $self, 'role' => $role]);
    return;
}

$candidate = $self ?: (int) ($_GET['candidate'] ?? 0);
if ($candidate) {
    if (! hr_may_see_recognition($candidate) && ! $hr) {
        refuse(404, t('That person is not yours to see.'));
    }
    if ($hr) {
        hr_log('opened the HR record', $candidate);
    }
    $person = [
        'id'           => $candidate,
        'name'         => (string) val('SELECT full_name FROM candidates WHERE id = ?', [$candidate]),
        'recognition'  => rows('SELECT r.*, u.name AS by_name FROM recognitions r LEFT JOIN users u ON u.id = r.created_by WHERE r.candidate_id = ? ORDER BY r.awarded_on DESC, r.id DESC', [$candidate]),
        'cases'        => array_values(array_filter(rows('SELECT * FROM disciplinary_cases WHERE candidate_id = ? ORDER BY id DESC', [$candidate]), 'case_visible')),
        'separations'  => $hr ? rows('SELECT s.*, j.title AS project, d.reference AS case_reference FROM separations s JOIN placements p ON p.id = s.placement_id JOIN jobs j ON j.id = p.job_id
                                       LEFT JOIN disciplinary_cases d ON d.id = s.case_id WHERE s.candidate_id = ? ORDER BY s.separated_on DESC', [$candidate]) : [],
        'unrecorded'   => $hr ? rows("SELECT p.id, p.end_date, p.status, j.title FROM placements p JOIN jobs j ON j.id = p.job_id
                                       WHERE p.candidate_id = ? AND p.status IN ('completed','cancelled') AND NOT EXISTS (SELECT 1 FROM separations s WHERE s.placement_id = p.id)", [$candidate]) : [],
        'terminations' => $hr ? rows("SELECT id, reference FROM disciplinary_cases WHERE candidate_id = ? AND outcome = 'termination'", [$candidate]) : [],
        'incidents'    => rows('SELECT i.id, i.created_at, i.severity FROM safety_incidents i JOIN placements p ON p.job_id = i.job_id WHERE p.candidate_id = ? GROUP BY i.id ORDER BY i.id DESC LIMIT 20', [$candidate]),
        'may_recognise'=> can('recruiter') || hr_supervises($candidate),
        'may_report'   => $hr || hr_supervises($candidate),
    ];
    render('hr-records', ['mode' => 'person', 'person' => $person, 'hr' => $hr, 'self' => $self, 'role' => $role]);
    return;
}

$overview = [
    'open'       => $hr ? rows("SELECT d.*, p.full_name FROM disciplinary_cases d JOIN candidates p ON p.id = d.candidate_id WHERE d.status <> 'closed' ORDER BY d.opened_at DESC LIMIT 100")
                        : ($role === 'supervisor' ? rows('SELECT d.*, p.full_name FROM disciplinary_cases d JOIN candidates p ON p.id = d.candidate_id WHERE d.opened_by = ? ORDER BY d.id DESC', [uid()]) : []),
    'to_record'  => $hr ? rows("SELECT p.id, p.candidate_id, p.end_date, c.full_name, j.title FROM placements p JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id
                                WHERE p.status IN ('completed','cancelled') AND NOT EXISTS (SELECT 1 FROM separations s WHERE s.placement_id = p.id) ORDER BY p.end_date DESC LIMIT 100") : [],
    'crew'       => $role === 'supervisor' ? rows("SELECT DISTINCT c.id, c.full_name FROM placements p JOIN assignment_details d ON d.placement_id = p.id JOIN candidates c ON c.id = p.candidate_id
                                                    WHERE d.supervisor_id = ? AND p.status NOT IN ('completed','cancelled') ORDER BY c.full_name", [uid()]) : [],
    'grants'     => can('admin') ? rows('SELECT g.*, u.name, u.role, b.name AS granted_by_name FROM hr_access_grants g JOIN users u ON u.id = g.user_id LEFT JOIN users b ON b.id = g.granted_by ORDER BY u.name') : [],
    'grantable'  => can('admin') ? rows("SELECT id, name, role FROM users WHERE is_active = 1 AND role IN ('recruiter','payroll','hotels') AND id NOT IN (SELECT user_id FROM hr_access_grants) ORDER BY name") : [],
    'log'        => can('admin') ? rows('SELECT l.*, u.name AS by_name, c.full_name, s.name AS subject FROM hr_access_log l LEFT JOIN users u ON u.id = l.user_id LEFT JOIN candidates c ON c.id = l.candidate_id
                                         LEFT JOIN users s ON s.id = l.subject_user_id ORDER BY l.id DESC LIMIT 100') : [],
];
render('hr-records', ['mode' => 'overview', 'overview' => $overview, 'hr' => $hr, 'self' => $self, 'role' => $role]);
