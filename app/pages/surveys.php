<?php
/**
 * Engagement surveys (P5-M05). Everyone signed in except clients answers
 * the surveys sent to them; recruiters and administrators write, open,
 * close them and read the results. See app/surveys.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../surveys.php';

if (! eng_person(uid())) {
    refuse(403, t('Not your desk'));
}
$me = uid();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $sid = (int) ($_POST['survey_id'] ?? 0);
    if ($do === 'respond') {
        $why = sv_respond($sid, $me, (array) ($_POST['answer'] ?? []));
        if ($why !== null) { refuse(422, $why); }
        flash(t('Thank you. Your answers are in.'));
        redirect('/surveys?take=' . $sid);
    }
    if (! eng_manager()) {
        refuse(403, t('Recruiters and administrators run surveys.'));
    }
    $back = '/surveys?survey=' . $sid;
    switch ($do) {
        case 'save':
            [$why, $sid] = sv_save($_POST, $me, $sid);
            $back = '/surveys?survey=' . $sid;
            break;
        case 'question_add':
            $why = sv_question_add($sid, $_POST);
            break;
        case 'question_remove':
            $why = sv_question_remove($sid, (int) ($_POST['question_id'] ?? 0));
            break;
        case 'open':
            [$why, $n] = sv_open($sid, $me);
            if ($why === null) { flash(t('Open. :n people asked.', ['n' => $n])); }
            break;
        case 'close':
            $why = sv_close($sid);
            break;
        case 'remind':
            [$why, $n] = sv_remind($sid);
            if ($why === null) { flash(t(':n reminder(s) sent.', ['n' => $n])); }
            break;
        default:
            refuse(400, t('Unknown action.'));
    }
    if ($why !== null) { refuse(422, $why); }
    if (! isset($_SESSION['flash'])) { flash(t('Saved.')); }
    redirect($back);
}

$take = $survey = null;
$questions = $results = [];
if (isset($_GET['take'])) {
    $take = row('SELECT s.*, i.responded_on FROM surveys s JOIN survey_invitations i ON i.survey_id = s.id AND i.user_id = ? WHERE s.id = ?', [$me, (int) $_GET['take']]);
    if (! $take) { refuse(404, t('That survey was not sent to you.')); }
    $questions = sv_questions((int) $take['id']);
    render('surveys', compact('take', 'questions'));
    return;
}
if (isset($_GET['survey'])) {
    if (! eng_manager()) { refuse(403, t('Recruiters and administrators run surveys.')); }
    $survey = row('SELECT s.*, j.title AS job_title FROM surveys s LEFT JOIN jobs j ON j.id = s.job_id WHERE s.id = ?', [(int) $_GET['survey']]);
    if (! $survey) { refuse(404, t('That survey does not exist.')); }
    $questions = sv_questions((int) $survey['id']);
    $results = $survey['status'] === 'draft' ? [] : sv_results($survey);
}
$mine = sv_mine($me);
$all = eng_manager() ? rows('SELECT s.*, (SELECT COUNT(*) FROM survey_invitations i WHERE i.survey_id = s.id) AS invited,
                                    (SELECT COUNT(*) FROM survey_responses r WHERE r.survey_id = s.id) AS responded
                             FROM surveys s ORDER BY s.status = \'open\' DESC, s.id DESC LIMIT 100') : [];
$jobs = eng_manager() ? rows("SELECT id, title FROM jobs WHERE status <> 'closed' ORDER BY title") : [];

render('surveys', compact('take', 'survey', 'questions', 'results', 'mine', 'all', 'jobs'));
