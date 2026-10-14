<?php
require_role('supervisor');
require_once __DIR__ . '/../hr.php';

// How the assignment went, graded by the supervisor who carried the person:
// their own crew only, for assignments finished in the last 60 days.
$finishedSql = "SELECT p.*, c.full_name, c.discipline, d.trade FROM assignment_details d JOIN placements p ON p.id = d.placement_id JOIN candidates c ON c.id = p.candidate_id
                WHERE d.supervisor_id = ? AND p.status = 'completed' AND COALESCE(p.end_date, DATE(p.updated_at), CURDATE()) >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'review') {
    $p = row($finishedSql . ' AND p.id = ?', [uid(), (int) ($_POST['placement_id'] ?? 0)]);
    if (! $p) {
        refuse(404, t('Grade only the finished assignments of your own crew.'), '/my-team');
    }
    if ($why = assignment_review_save($p, $_POST, uid())) {
        refuse(422, $why, '/my-team');
    }
    flash(t(':name graded :grade on this assignment.', ['name' => $p['full_name'], 'grade' => (string) $_POST['grade']]));
    redirect('/my-team');
}
// Walkthrough finding 28: My team counted who was not marked at roll call, but only the
// administrator's Deployment view (and the trusted phone) could mark it. The supervisor
// marks their own crew, on site, for today.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'clock') {
    require_once __DIR__ . '/../clock-in.php';
    if ($why = ci_decide((int) ($_POST['clock_in_id'] ?? 0), ($_POST['present'] ?? '1') === '1', uid())) {
        refuse(422, $why, '/my-team#clock-ins');
    }
    flash(($_POST['present'] ?? '1') === '1' ? t('Clock-in validated.') : t('Marked not on site. They are told to call you.'));
    redirect('/my-team#clock-ins');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'rollcall') {
    $p = row("SELECT p.id, c.full_name FROM assignment_details d JOIN placements p ON p.id = d.placement_id JOIN candidates c ON c.id = p.candidate_id
              WHERE d.supervisor_id = ? AND p.id = ? AND p.status = 'on_site'", [uid(), (int) ($_POST['placement_id'] ?? 0)]);
    if (! $p) {
        refuse(404, t('Mark only your own crew who are on site.'), '/my-team');
    }
    $present = ($_POST['present'] ?? '1') === '1' ? 1 : 0;
    q('INSERT INTO assignment_checkins (placement_id, work_date, present, marked_by) VALUES (?, CURDATE(), ?, ?)
       ON DUPLICATE KEY UPDATE present = VALUES(present), marked_by = VALUES(marked_by), marked_at = NOW()', [(int) $p['id'], $present, uid()]);
    log_activity($present ? 'marked present at roll call' : 'marked absent at roll call', 'placement', (int) $p['id'], $p['full_name']);
    flash($present ? t(':name marked present today.', ['name' => $p['full_name']]) : t(':name marked absent today.', ['name' => $p['full_name']]));
    redirect('/my-team#crew');
}
$team = rows("SELECT p.id,p.status,c.full_name,d.trade,d.shift_label,j.title,(SELECT k.present FROM assignment_checkins k WHERE k.placement_id=p.id AND k.work_date=CURDATE()) today_present FROM assignment_details d JOIN placements p ON p.id=d.placement_id JOIN candidates c ON c.id=p.candidate_id JOIN jobs j ON j.id=p.job_id WHERE d.supervisor_id=? AND p.status NOT IN ('completed','cancelled') ORDER BY j.title,c.full_name", [uid()]);
$toGrade = rows($finishedSql . ' AND NOT EXISTS (SELECT 1 FROM assignment_reviews r WHERE r.placement_id = p.id) ORDER BY c.full_name', [uid()]);
require_once __DIR__ . '/../home.php';
$today = home_supervisor(uid());
// P5-M32: clock-ins to validate by 30 minutes after the shift starts, and room numbers overdue.
require_once __DIR__ . '/../clock-in.php';
$clockDay = ci_crew_today(uid());
render('my-team', compact('team', 'toGrade', 'today', 'clockDay'));
