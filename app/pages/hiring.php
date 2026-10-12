<?php
/**
 * Hiring (P5-M10): the recruiter's workspace. See app/hiring.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../hiring.php';

require_role('recruiter');

/** Back to where the recruiter was, only ever inside /hiring. */
function hw_back(): string
{
    $back = (string) ($_POST['back'] ?? '/hiring');

    return str_starts_with($back, '/hiring') && ! preg_match('/[\r\n]/', $back) ? $back : '/hiring';
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
$hwView = in_array($_GET['tab'] ?? '', ['candidates', 'matches', 'job'], true) ? (string) $_GET['tab'] : 'candidates';
$hwFilter = ['q' => trim((string) ($_GET['q'] ?? '')), 'interest' => (string) ($_GET['interest'] ?? ''), 'sort' => (string) ($_GET['sort'] ?? '')];
$hwCounts = hw_counts((int) $hwVacancy['id']);
$hwList = $hwView === 'candidates' ? hw_applicants($hwVacancy, $hwTab, $hwFilter) : [];
$hwMatches = $hwView === 'matches' ? hw_matches($hwVacancy, $hwFilter['q']) : [];
$hwSelf = '/hiring?' . http_build_query(array_filter(['v' => (int) $hwVacancy['id'], 'tab' => $hwView !== 'candidates' ? $hwView : null, 'stage' => $hwTab !== 'active' ? $hwTab : null,
                                                          'q' => $hwFilter['q'] ?: null, 'interest' => $hwFilter['interest'] ?: null, 'sort' => $hwFilter['sort'] ?: null]));

$hwApp = null;
$textPanel = null;
if (isset($_GET['app'])) {
    $hwApp = row('SELECT a.*, c.full_name, c.city, c.state, c.phone, c.email, c.degree, c.years_exp, c.discipline, c.last_contact_at
                  FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE a.id = ? AND a.vacancy_id = ?', [(int) $_GET['app'], (int) $hwVacancy['id']]);
    if (! $hwApp) {
        refuse(404, t('Application unavailable in this project.'));
    }
    $ids = array_map(static fn($a) => (int) $a['id'], $hwList);
    $at = array_search((int) $hwApp['id'], $ids, true);
    $hwApp['prev'] = $at !== false && $at > 0 ? $ids[$at - 1] : null;
    $hwApp['next'] = $at !== false && $at < count($ids) - 1 ? $ids[$at + 1] : null;
    $hwApp['position'] = $at !== false ? $at + 1 : null;
    $hwApp['questions'] = rows('SELECT q.id, q.question, q.required, s.answer FROM screening_questions q LEFT JOIN screening_answers s ON s.question_id = q.id AND s.application_id = ?
                                WHERE q.job_id = ? ORDER BY q.id', [(int) $hwApp['id'], (int) $hwVacancy['job_id']]);
    $hwApp['checks'] = rows('SELECT * FROM screening_checks WHERE application_id = ? ORDER BY id', [(int) $hwApp['id']]);
    $hwApp['resumes'] = rows('SELECT id, original_name, created_at FROM candidate_resumes WHERE candidate_id = ? ORDER BY id DESC', [(int) $hwApp['candidate_id']]);
    $hwApp['timeline'] = hw_timeline($hwApp);
    $hwApp['skills'] = rows('SELECT skill_slug, years, confirmed FROM candidate_skills WHERE candidate_id = ? ORDER BY confirmed DESC, years DESC', [(int) $hwApp['candidate_id']]);
    require_once __DIR__ . '/../messaging.php';
    $textPanel = msg_panel((int) $hwApp['candidate_id']);
}
$hwDetail = in_array($_GET['detail'] ?? '', ['application', 'activity'], true) ? (string) $_GET['detail'] : 'application';

render('hiring', compact('hwVacancy', 'hwTab', 'hwView', 'hwFilter', 'hwCounts', 'hwList', 'hwMatches', 'hwSelf', 'hwApp', 'hwDetail', 'textPanel'));
