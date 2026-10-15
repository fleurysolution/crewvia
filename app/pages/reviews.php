<?php
/**
 * Candidate reviews (P5-M44): what the recruiting desk has passed to a
 * manager, and the interviews given to somebody. See app/reviews.php.
 *
 *   /reviews                 waiting for my decision, my interviews, decided lately
 *   /reviews?id=ID           one review: the applicant's dossier and the decision
 *   /reviews?interview=ID    one interview: the dossier and the scorecard
 *
 * Open to the recruiting desk and to supervisors (a manager need not be on
 * the recruiting desk); each sees only what was given to them, an
 * administrator sees everything.
 */

declare(strict_types=1);

require_role('recruiter', 'supervisor');
require_once __DIR__ . '/../reviews.php';
require_once __DIR__ . '/../screening.php';
require_once __DIR__ . '/../intake.php';

$me = uid();
$admin = can('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'decide') {
        $id = (int) ($_POST['review_id'] ?? 0);
        $why = review_decide($id, (string) ($_POST['decision'] ?? ''), (string) ($_POST['note'] ?? ''), $me, [
            'when' => (string) ($_POST['interview_at'] ?? ''), 'duration' => (int) ($_POST['duration'] ?? 30), 'mode' => (string) ($_POST['mode'] ?? 'video'),
            'place' => (string) ($_POST['place'] ?? ''), 'interviewer' => (int) ($_POST['interviewer_id'] ?? $me)]);
        if ($why !== null) { refuse(422, $why, '/reviews?id=' . $id); }
        flash(t('Decision recorded. The recruiter is told.'));
        redirect('/reviews');
    }
    if ($do === 'interview_record') {
        $id = (int) ($_POST['interview_id'] ?? 0);
        $why = interview_record($id, (string) ($_POST['status'] ?? ''), (int) ($_POST['rating'] ?? 0), (string) ($_POST['verdict'] ?? ''), (string) ($_POST['feedback'] ?? ''), $me);
        if ($why !== null) { refuse(422, $why, '/reviews?interview=' . $id); }
        flash(t('Interview recorded. The recruiter is told.'));
        redirect('/reviews');
    }
    refuse(422, t('Unknown action.'), '/reviews');
}

$mine = $admin ? '1' : 'r.reviewer_id = ' . $me;
$waiting = rows("SELECT r.*, c.full_name, v.title, a.screening_score, u.name AS requester, m.name AS reviewer FROM application_reviews r
                 JOIN applications a ON a.id = r.application_id JOIN candidates c ON c.id = a.candidate_id JOIN vacancies v ON v.id = a.vacancy_id
                 JOIN users u ON u.id = r.requested_by JOIN users m ON m.id = r.reviewer_id
                 WHERE r.status = 'pending' AND $mine ORDER BY r.id");
$interviews = rows("SELECT i.*, c.full_name, v.title, u.name AS interviewer FROM interviews i
                    JOIN applications a ON a.id = i.application_id JOIN candidates c ON c.id = a.candidate_id JOIN vacancies v ON v.id = a.vacancy_id
                    JOIN users u ON u.id = i.interviewer_id
                    WHERE i.status = 'scheduled' AND " . ($admin ? '1' : 'i.interviewer_id = ' . $me) . ' ORDER BY i.scheduled_at');
$decided = rows("SELECT r.*, c.full_name, v.title FROM application_reviews r JOIN applications a ON a.id = r.application_id
                 JOIN candidates c ON c.id = a.candidate_id JOIN vacancies v ON v.id = a.vacancy_id
                 WHERE r.status IN ('validated','interview','declined') AND $mine ORDER BY r.decided_at DESC LIMIT 20");

$review = null;
$interview = null;
$dossier = null;
if (isset($_GET['id'])) {
    $review = row('SELECT r.*, u.name AS requester FROM application_reviews r JOIN users u ON u.id = r.requested_by WHERE r.id = ?', [(int) $_GET['id']]);
    if (! $review || (! $admin && (int) $review['reviewer_id'] !== $me)) { refuse(404, t('That review is not yours to see.'), '/reviews'); }
    $dossier = review_dossier((int) $review['application_id']);
} elseif (isset($_GET['interview'])) {
    $interview = row('SELECT i.*, u.name AS interviewer FROM interviews i JOIN users u ON u.id = i.interviewer_id WHERE i.id = ?', [(int) $_GET['interview']]);
    if (! $interview || (! $admin && ! can('recruiter') && (int) $interview['interviewer_id'] !== $me)) { refuse(404, t('That interview is not yours to see.'), '/reviews'); }
    $dossier = review_dossier((int) $interview['application_id']);
}
$people = review_people();

$pageTitle = t('Candidate reviews') . ' · ' . $config['app_name'];
render('reviews', compact('waiting', 'interviews', 'decided', 'review', 'interview', 'dossier', 'people', 'me'));
