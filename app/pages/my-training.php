<?php
/**
 * My training (P5-M04): what is assigned to me, what I have done and until
 * when it counts; a course to read and, when it has one, its assessment.
 * The answers are scored on the server; the answer key never reaches the
 * page.
 */

declare(strict_types=1);

require_login();
require_once __DIR__ . '/../training.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['assignment_id'] ?? 0);
    [$why, $score, $passed] = tr_submit($id, uid(), (array) ($_POST['answer'] ?? []), ! empty($_POST['confirmed']));
    if ($why !== null) { refuse(422, $why); }
    flash($passed ? ($score !== null ? t('Passed with :score%. Done.', ['score' => $score]) : t('Done.'))
                  : t('Not passed: :score%. Read the course again and try once more.', ['score' => $score]), $passed ? 'ok' : 'err');
    redirect($passed ? '/my-training' : '/my-training?take=' . $id);
}

$take = null;
$questions = [];
$attempts = [];
if (isset($_GET['take'])) {
    $take = tr_own((int) $_GET['take'], uid());
    if (! $take) { refuse(404, t('That training is not yours.')); }
    $questions = tr_questions_for_learner((int) $take['course_id']);
    $attempts = rows('SELECT score, passed, submitted_at FROM training_attempts WHERE assignment_id = ? ORDER BY id DESC', [(int) $take['id']]);
}
$mine = rows('SELECT a.*, c.title, c.description FROM training_assignments a JOIN training_courses c ON c.id = a.course_id WHERE a.user_id = ?
              ORDER BY a.completed_at IS NOT NULL, a.due_on, a.id DESC LIMIT 300', [uid()]);

render('my-training', compact('take', 'questions', 'attempts', 'mine'));
