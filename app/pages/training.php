<?php
/**
 * Training (P5-M04), for recruiters (the HR desk) and administrators:
 * courses and their assessments, plans, requirements by desk role, trade or
 * project, and who is where on what is required. See app/training.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../training.php';

require_role('recruiter');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $course = (int) ($_POST['course_id'] ?? 0);
    $plan = (int) ($_POST['plan_id'] ?? 0);
    $back = '/training';
    $why = null;
    switch ($do) {
        case 'course_save':
            [$why, $course] = tr_course_save($_POST, uid(), $course);
            $back = '/training?course=' . $course;
            break;
        case 'question_add':
            $why = tr_question_add($course, $_POST);
            $back = '/training?course=' . $course;
            break;
        case 'question_remove':
            $why = tr_question_remove((int) ($_POST['question_id'] ?? 0));
            $back = '/training?course=' . $course;
            break;
        case 'assign':
            [$why, $n] = tr_assign_manual($course, (array) ($_POST['user_ids'] ?? []), (string) ($_POST['due_on'] ?? ''), uid());
            $back = '/training?course=' . $course;
            if ($why === null) { flash(t(':n assignment(s) made; anyone who has it open or still current was left as they are.', ['n' => $n])); }
            break;
        case 'plan_save':
            [$why, $plan] = tr_plan_save($_POST, uid(), $plan);
            $back = '/training?plan=' . $plan;
            break;
        case 'plan_course_add':
            $why = tr_plan_course_add($plan, $course, trim((string) ($_POST['due_days'] ?? '')));
            $back = '/training?plan=' . $plan;
            break;
        case 'plan_course_remove':
            $why = tr_plan_course_remove($plan, $course);
            $back = '/training?plan=' . $plan;
            break;
        case 'plan_assign':
            [$why, $n] = tr_plan_assign($plan, (array) ($_POST['user_ids'] ?? []), uid());
            $back = '/training?plan=' . $plan;
            if ($why === null) { flash(t(':n assignment(s) made; anyone who has it open or still current was left as they are.', ['n' => $n])); }
            break;
        case 'requirement_save':
            $why = tr_requirement_save($_POST, uid());
            $back = '/training#requirements';
            if ($why === null) { flash(t('Required. :n assignment(s) made.', ['n' => tr_sync()])); }
            break;
        case 'requirement_stop':
            $why = tr_requirement_stop((int) ($_POST['requirement_id'] ?? 0));
            $back = '/training#requirements';
            break;
        case 'sync':
            flash(t(':n assignment(s) made from the requirements.', ['n' => tr_sync()]));
            redirect('/training#compliance');
        default:
            refuse(400, t('Unknown action.'));
    }
    if ($why !== null) { refuse(422, $why); }
    if (! isset($_SESSION['flash'])) { flash(t('Saved.')); }
    redirect($back);
}

$types = rows('SELECT id, name FROM qualification_types ORDER BY name');
$people = rows("SELECT u.id, u.name, u.role FROM users u WHERE u.is_active = 1 AND u.role <> 'client' ORDER BY u.name");

if (isset($_GET['course'])) {
    $course = row('SELECT * FROM training_courses WHERE id = ?', [(int) $_GET['course']]);
    if (! $course) { refuse(404, t('That course does not exist.')); }
    $questions = rows('SELECT * FROM training_questions WHERE course_id = ? ORDER BY sort_order, id', [(int) $course['id']]);
    $taken = rows('SELECT a.*, u.name FROM training_assignments a JOIN users u ON u.id = a.user_id WHERE a.course_id = ? ORDER BY a.id DESC LIMIT 200', [(int) $course['id']]);
    render('training', compact('course', 'questions', 'taken', 'types', 'people'));
    return;
}
if (isset($_GET['plan'])) {
    $plan = row('SELECT * FROM training_plans WHERE id = ?', [(int) $_GET['plan']]);
    if (! $plan) { refuse(404, t('That plan does not exist.')); }
    $planCourses = rows('SELECT pc.*, c.title FROM training_plan_courses pc JOIN training_courses c ON c.id = pc.course_id WHERE pc.plan_id = ? ORDER BY pc.sort_order', [(int) $plan['id']]);
    $courses = rows('SELECT id, title FROM training_courses WHERE is_active = 1 ORDER BY title');
    render('training', compact('plan', 'planCourses', 'courses', 'people'));
    return;
}

$filter = ['scope' => isset(tr_scopes()[$_GET['scope'] ?? '']) ? (string) $_GET['scope'] : '', 'status' => isset(tr_statuses()[$_GET['status'] ?? '']) ? (string) $_GET['status'] : ''];
$courses = rows('SELECT c.*, (SELECT COUNT(*) FROM training_questions q WHERE q.course_id = c.id) AS questions, t.name AS awards FROM training_courses c LEFT JOIN qualification_types t ON t.id = c.awards_type_id ORDER BY c.is_active DESC, c.title');
$plans = rows('SELECT p.*, (SELECT COUNT(*) FROM training_plan_courses pc WHERE pc.plan_id = p.id) AS courses FROM training_plans p ORDER BY p.is_active DESC, p.name');
$requirements = rows('SELECT r.*, c.title, j.title AS job_title FROM training_requirements r JOIN training_courses c ON c.id = r.course_id LEFT JOIN jobs j ON j.id = r.job_id WHERE r.is_active = 1 ORDER BY c.title, r.id');
$compliance = tr_compliance($filter);
$counts = array_count_values(array_column(tr_compliance(), 'status'));
$jobs = rows("SELECT id, title FROM jobs WHERE status <> 'closed' ORDER BY title");

render('training', compact('courses', 'plans', 'requirements', 'compliance', 'counts', 'filter', 'types', 'jobs'));
