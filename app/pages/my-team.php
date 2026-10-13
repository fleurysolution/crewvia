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
$team = rows("SELECT p.id,p.status,c.full_name,d.trade,d.shift_label,j.title FROM assignment_details d JOIN placements p ON p.id=d.placement_id JOIN candidates c ON c.id=p.candidate_id JOIN jobs j ON j.id=p.job_id WHERE d.supervisor_id=? AND p.status NOT IN ('completed','cancelled') ORDER BY j.title,c.full_name", [uid()]);
$toGrade = rows($finishedSql . ' AND NOT EXISTS (SELECT 1 FROM assignment_reviews r WHERE r.placement_id = p.id) ORDER BY c.full_name', [uid()]);
render('my-team', compact('team', 'toGrade'));
