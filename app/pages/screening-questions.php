<?php
/**
 * The screening questionnaire: what every applicant is asked, and what the
 * answers are worth.
 *
 * Ported from BPMS247's screening questions screen. A question belongs to a
 * role or to the whole project, knows what kind of answer it wants, carries
 * a weight toward a score, and can name the answer that disqualifies.
 *
 * The statistics at the top are the point of the page as much as the builder
 * is: a question that screens nobody out is not doing anything, and one that
 * screens out nine applicants in ten is probably asking the wrong thing.
 */

require_role('recruiter');
require_once __DIR__ . '/../screening.php';

$job   = current_job();
$jobId = (int) ($job['id'] ?? 0);

$vacancyId = (int) ($_GET['vacancy'] ?? $_POST['vacancy_id'] ?? 0);

if ($vacancyId && ! val('SELECT id FROM vacancies WHERE id = ? AND job_id = ?', [$vacancyId, $jobId])) {
    refuse(404, t('That requisition is not on this project.'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');

    if (! $jobId) {
        flash(t('Choose a project first.'), 'err');
        redirect('/screening-questions');
    }

    if ($do === 'add') {
        $question = trim((string) ($_POST['question'] ?? ''));
        $type     = (string) ($_POST['answer_type'] ?? 'text');
        $weight   = (int) ($_POST['weight'] ?? 5);

        if ($question === '' || mb_strlen($question) > 500) {
            flash(t('Write the question as you would ask it out loud.'), 'err');
            redirect('/screening-questions' . ($vacancyId ? '?vacancy=' . $vacancyId : ''));
        }

        if (! array_key_exists($type, screening_answer_types())) {
            $type = 'text';
        }

        if ($weight < 0 || $weight > 100) {
            flash(t('A weight is between 0 and 100.'), 'err');
            redirect('/screening-questions' . ($vacancyId ? '?vacancy=' . $vacancyId : ''));
        }

        $choices  = trim((string) ($_POST['choices'] ?? ''));
        $knockout = trim((string) ($_POST['knockout_answer'] ?? ''));

        // A knockout on a yes/no question can only be one of the two answers;
        // anything else would never fire and would read as if it did.
        if ($type === 'yes_no' && $knockout !== ''
            && ! in_array(mb_strtolower($knockout), ['yes', 'no'], true)) {
            flash(t('On a Yes / No question the disqualifying answer is Yes or No.'), 'err');
            redirect('/screening-questions' . ($vacancyId ? '?vacancy=' . $vacancyId : ''));
        }

        $next = (int) val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM screening_questions
                           WHERE job_id = ?', [$jobId]);

        q('INSERT INTO screening_questions
             (job_id, vacancy_id, question, required, answer_type, choices,
              weight, knockout_answer, sort_order)
           VALUES (?,?,?,?,?,?,?,?,?)',
          [$jobId, $vacancyId ?: null, $question, isset($_POST['required']) ? 1 : 0,
           $type, $choices !== '' ? mb_substr($choices, 0, 500) : null,
           $weight, $knockout !== '' ? mb_substr($knockout, 0, 190) : null, $next]);

        log_activity('added a screening question', 'job', $jobId, $question);
        flash(t('Question added. Saving re-ranks the applications already on the board.'));

        screening_rescore_job($jobId);
        redirect('/screening-questions' . ($vacancyId ? '?vacancy=' . $vacancyId : ''));
    }

    if ($do === 'remove') {
        $question = row('SELECT * FROM screening_questions WHERE id = ? AND job_id = ?',
                        [(int) ($_POST['question_id'] ?? 0), $jobId]);

        if ($question) {
            q('DELETE FROM screening_answers WHERE question_id = ?', [$question['id']]);
            q('DELETE FROM screening_questions WHERE id = ?', [$question['id']]);
            log_activity('removed a screening question', 'job', $jobId, (string) $question['question']);
            flash(t('Question removed, and the scores recalculated without it.'));
            screening_rescore_job($jobId);
        }

        redirect('/screening-questions' . ($vacancyId ? '?vacancy=' . $vacancyId : ''));
    }

    if ($do === 'move') {
        $question = row('SELECT * FROM screening_questions WHERE id = ? AND job_id = ?',
                        [(int) ($_POST['question_id'] ?? 0), $jobId]);
        $by       = ($_POST['direction'] ?? '') === 'up' ? -1 : 1;

        if ($question) {
            // Swap with whichever question is adjacent in the running order,
            // so the list cannot develop gaps or ties.
            $neighbour = row('SELECT * FROM screening_questions
                              WHERE job_id = ? AND sort_order ' . ($by < 0 ? '<' : '>') . ' ?
                              ORDER BY sort_order ' . ($by < 0 ? 'DESC' : 'ASC') . ' LIMIT 1',
                             [$jobId, (int) $question['sort_order']]);

            if ($neighbour) {
                q('UPDATE screening_questions SET sort_order = ? WHERE id = ?',
                  [(int) $neighbour['sort_order'], (int) $question['id']]);
                q('UPDATE screening_questions SET sort_order = ? WHERE id = ?',
                  [(int) $question['sort_order'], (int) $neighbour['id']]);
            }
        }

        redirect('/screening-questions' . ($vacancyId ? '?vacancy=' . $vacancyId : ''));
    }

    refuse(422, t('Unknown screening action.'));
}

$requisitions = rows('SELECT id, title, discipline FROM vacancies
                      WHERE job_id = ? ORDER BY id DESC', [$jobId]);

$questions = $jobId
    ? rows('SELECT q.*, v.title AS role,
                   (SELECT COUNT(*) FROM screening_answers s WHERE s.question_id = q.id) answered
            FROM screening_questions q
            LEFT JOIN vacancies v ON v.id = q.vacancy_id
            WHERE q.job_id = ?' . ($vacancyId ? ' AND (q.vacancy_id IS NULL OR q.vacancy_id = ?)' : '')
           . ' ORDER BY q.sort_order, q.id',
           $vacancyId ? [$jobId, $vacancyId] : [$jobId])
    : [];

$stats = $jobId ? screening_stats($jobId, $vacancyId ?: null)
                : ['applications' => 0, 'screened_out' => 0, 'average' => null, 'unscored' => 0];

// Where the applicants came from, counted from the link each one followed.
$channels = $jobId ? rows("SELECT COALESCE(NULLIF(c.source, ''), ?) AS channel,
                                  COUNT(*) applications,
                                  SUM(a.screened_out = 1) screened_out,
                                  AVG(a.screening_score) average_score
                           FROM applications a
                           JOIN candidates c ON c.id = a.candidate_id
                           JOIN vacancies v ON v.id = a.vacancy_id
                           WHERE v.job_id = ?"
                          . ($vacancyId ? ' AND a.vacancy_id = ?' : '')
                          . ' GROUP BY channel ORDER BY applications DESC',
                          $vacancyId ? [t('Direct'), $jobId, $vacancyId] : [t('Direct'), $jobId])
                   : [];

$pageTitle = t('Screening questions') . ' · ' . $config['app_name'];

render('screening-questions', compact('job', 'jobId', 'vacancyId', 'requisitions',
                                      'questions', 'stats', 'channels'));
