<?php
/**
 * Hiring (P5-M10): the recruiter's workspace. See app/hiring.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../hiring.php';
require_once __DIR__ . '/../geo.php';
require_once __DIR__ . '/../reviews.php';
require_once __DIR__ . '/../availability.php';

require_role('recruiter');

/** Back to where the recruiter was, only ever inside /hiring. */
function hw_back(): string
{
    $back = (string) ($_POST['back'] ?? '/hiring');

    return str_starts_with($back, '/hiring') && ! preg_match('/[\r\n]/', $back) ? $back : '/hiring';
}

// P5-M42: somebody who applied by email, phone or in person, added with what they sent.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'add_applicant') {
    $vacancyId = (int) ($_POST['vacancy_id'] ?? 0);
    [$why, $appId] = hw_add_applicant($vacancyId, $_POST, $_FILES['documents'] ?? [], uid());
    if ($why !== null) {
        $_SESSION['hw_add_form'] = array_intersect_key($_POST, array_flip(['first_name', 'last_name', 'email', 'phone', 'city', 'state', 'zip', 'channel', 'received_on', 'degree', 'years_exp', 'message']));
        refuse(422, $why, '/hiring?v=' . $vacancyId . '&tab=add');
    }
    flash(t('Applicant added, with what they sent. Review them here.'));
    redirect('/hiring?v=' . $vacancyId . '&app=' . $appId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['application_id'] ?? 0);
    $why = match ($do) {
        'interest' => hw_interest($id, (string) ($_POST['interest'] ?? ''), uid()),
        'move'     => hw_move($id, (string) ($_POST['stage'] ?? ''), (string) ($_POST['note'] ?? ''), uid()),
        'reject'   => hw_move($id, 'rejected', (string) ($_POST['note'] ?? ''), uid()),
        'note'     => hw_note($id, (string) ($_POST['note'] ?? ''), uid()),
        'add'      => hw_add((int) ($_POST['vacancy_id'] ?? 0), (int) ($_POST['candidate_id'] ?? 0), uid())[0],
        // P5-M44: to the manager, and interviews.
        'review_request'     => review_request($id, (int) ($_POST['reviewer_id'] ?? 0), (int) ($_POST['rating'] ?? 0), (string) ($_POST['recommendation'] ?? ''), uid()),
        'review_withdraw'    => review_withdraw((int) ($_POST['review_id'] ?? 0), uid()),
        'interview_schedule' => interview_schedule($id, (int) ($_POST['interviewer_id'] ?? 0), (string) ($_POST['interview_at'] ?? ''), (int) ($_POST['duration'] ?? 30),
                                                   (string) ($_POST['mode'] ?? 'video'), (string) ($_POST['place'] ?? ''), uid()),
        default    => t('Unknown action.'),
    };
    if ($why !== null) {
        refuse(422, $why, hw_back());
    }
    if (! isset($_SESSION['flash'])) {
        flash(match ($do) {
            'move', 'reject' => t('Moved.'),
            'note'           => t('Note recorded against the application.'),
            'add'            => t('Added to this requisition as a new applicant.'),
            'review_request'     => t('Passed to the manager. You will be told when they decide.'),
            'review_withdraw'    => t('Taken back from the manager.'),
            'interview_schedule' => t('Interview scheduled. The interviewer is told.'),
            default          => t('Saved.'),
        });
    }
    redirect(hw_back());
}

$hwStatus = in_array($_GET['status'] ?? 'open', ['open', 'closed', 'all'], true) ? (string) ($_GET['status'] ?? 'open') : 'open';

if (! isset($_GET['v'])) {
    $hwJobs = hw_jobs($hwStatus, (int) ($_GET['project'] ?? 0));
    $hwProjects = rows("SELECT id, title FROM jobs WHERE status <> 'closed' ORDER BY title");
    render('hiring', compact('hwJobs', 'hwStatus', 'hwProjects'));
    return;
}

$hwVacancy = hw_vacancy((int) $_GET['v']);
if (! $hwVacancy) {
    refuse(404, t('That requisition does not exist.'));
}
$hwTab = isset(hw_tabs()[$_GET['stage'] ?? '']) ? (string) $_GET['stage'] : 'active';
$hwView = in_array($_GET['tab'] ?? '', ['candidates', 'matches', 'job', 'add'], true) ? (string) $_GET['tab'] : 'candidates';
$hwFilter = ['q' => trim((string) ($_GET['q'] ?? '')), 'interest' => (string) ($_GET['interest'] ?? ''), 'sort' => (string) ($_GET['sort'] ?? ''),
             'miles' => in_array((int) ($_GET['miles'] ?? 0), geo_radii(), true) ? (int) $_GET['miles'] : 0];
geo_refresh_candidates();
$hwSite = geo_site($hwVacancy);
$hwCounts = hw_counts((int) $hwVacancy['id']);
$hwList = $hwView === 'candidates' ? hw_applicants($hwVacancy, $hwTab, $hwFilter, $hwSite) : [];
$hwMatches = $hwView === 'matches' ? hw_matches($hwVacancy, $hwFilter['q'], $hwSite, $hwFilter['miles']) : [];
$hwSelf = '/hiring?' . http_build_query(array_filter(['v' => (int) $hwVacancy['id'], 'tab' => $hwView !== 'candidates' ? $hwView : null, 'stage' => $hwTab !== 'active' ? $hwTab : null,
                                                          'q' => $hwFilter['q'] ?: null, 'interest' => $hwFilter['interest'] ?: null, 'sort' => $hwFilter['sort'] ?: null,
                                                          'miles' => $hwFilter['miles'] ?: null]));

$hwApp = null;
$textPanel = null;
if (isset($_GET['app'])) {
    $hwApp = row('SELECT a.*, c.full_name, c.city, c.state, c.phone, c.email, c.degree, c.years_exp, c.discipline, c.last_contact_at, c.availability, c.available_from, c.availability_at
                  FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE a.id = ? AND a.vacancy_id = ?', [(int) $_GET['app'], (int) $hwVacancy['id']]);
    if (! $hwApp) {
        refuse(404, t('Application unavailable in this project.'));
    }
    $ids = array_map(static fn($a) => (int) $a['id'], $hwList);
    $at = array_search((int) $hwApp['id'], $ids, true);
    $hwApp['prev'] = $at !== false && $at > 0 ? $ids[$at - 1] : null;
    $hwApp['next'] = $at !== false && $at < count($ids) - 1 ? $ids[$at + 1] : null;
    $hwApp['position'] = $at !== false ? $at + 1 : null;
    // Walkthrough finding 21: somebody applying again arrives as a new record (identity is a person's call,
    // never the email's). Application screening said so; Hiring, where the recruiter works now, did not.
    $hwApp['matches'] = rows("SELECT old.id, old.full_name, old.stage FROM candidates nc JOIN candidates old ON old.id <> nc.id
                                AND ((nc.normalized_email IS NOT NULL AND nc.normalized_email <> '' AND old.normalized_email = nc.normalized_email)
                                  OR (nc.normalized_phone IS NOT NULL AND nc.normalized_phone <> '' AND old.normalized_phone = nc.normalized_phone))
                              WHERE nc.id = ? ORDER BY old.id LIMIT 5", [(int) $hwApp['candidate_id']]);
    require_once __DIR__ . '/../approvals.php';
    $hwApp['validation'] = approvals_available() ? approval_state('offer', (int) $hwApp['id']) : 'none';
    require_once __DIR__ . '/../screening.php';
    $hwApp['questions'] = rows('SELECT q.*, s.answer FROM screening_questions q LEFT JOIN screening_answers s ON s.question_id = q.id AND s.application_id = ?
                                WHERE q.job_id = ? AND (q.vacancy_id IS NULL OR q.vacancy_id = (SELECT vacancy_id FROM applications WHERE id = ?)) ORDER BY q.sort_order, q.id', [(int) $hwApp['id'], (int) $hwVacancy['job_id'], (int) $hwApp['id']]);
    $hwApp['checks'] = rows('SELECT * FROM screening_checks WHERE application_id = ? ORDER BY id', [(int) $hwApp['id']]);
    $hwApp['resumes'] = rows('SELECT id, kind, original_name, created_at FROM candidate_resumes WHERE candidate_id = ? ORDER BY id DESC', [(int) $hwApp['candidate_id']]);
    // P5-M40: what came with the application (a job board's status and answers, a cover letter) and every note written on it.
    $hwApp['notes'] = rows("SELECT e.stage, e.note, e.created_at, u.name FROM application_events e LEFT JOIN users u ON u.id = e.user_id
                            WHERE e.application_id = ? AND e.note IS NOT NULL AND e.note <> '' ORDER BY e.id DESC LIMIT 30", [(int) $hwApp['id']]);
    $hwApp['timeline'] = hw_timeline($hwApp);
    // P5-M44: the manager's review and the interviews.
    $hwApp['reviews'] = rows('SELECT r.*, m.name AS reviewer FROM application_reviews r JOIN users m ON m.id = r.reviewer_id WHERE r.application_id = ? ORDER BY r.id DESC', [(int) $hwApp['id']]);
    $hwApp['interviews'] = rows('SELECT i.*, u.name AS interviewer FROM interviews i JOIN users u ON u.id = i.interviewer_id WHERE i.application_id = ? ORDER BY i.scheduled_at DESC', [(int) $hwApp['id']]);
    $hwApp['skills'] = rows('SELECT skill_slug, years, confirmed FROM candidate_skills WHERE candidate_id = ? ORDER BY confirmed DESC, years DESC', [(int) $hwApp['candidate_id']]);
    require_once __DIR__ . '/../messaging.php';
    $textPanel = msg_panel((int) $hwApp['candidate_id']);
}
$hwPeople = isset($_GET['app']) ? review_people() : [];
$hwAddForm = $hwView === 'add' ? (array) ($_SESSION['hw_add_form'] ?? []) : [];
unset($_SESSION['hw_add_form']);
$hwDetail = in_array($_GET['detail'] ?? '', ['application', 'activity'], true) ? (string) $_GET['detail'] : 'application';

render('hiring', compact('hwVacancy', 'hwTab', 'hwView', 'hwFilter', 'hwCounts', 'hwList', 'hwMatches', 'hwSelf', 'hwApp', 'hwDetail', 'textPanel', 'hwSite', 'hwAddForm', 'hwPeople'));
