<?php
/**
 * Scoring an application against the questions it was asked.
 *
 * Ported from BPMS247's screening questions, with its two rules kept exactly
 * as they are there, because they are the right ones:
 *
 *   - a knockout question disqualifies when it is answered wrongly. The
 *     applicant is flagged and sorted to the bottom with the reason shown.
 *     Nobody is rejected automatically; a person still presses the button.
 *
 *   - the score is a percentage of the weight that was available, not a sum.
 *     A role with four questions and a role with twelve then compare.
 *
 * An application that arrived before the questions existed is left alone:
 * scoring it against questions nobody asked it would invent a number.
 */

declare(strict_types=1);

/** The answer types a question can want. */
function screening_answer_types(): array
{
    return [
        'yes_no' => 'Yes / No',
        'choice' => 'One of a list',
        'number' => 'A number',
        'text'   => 'Free text',
    ];
}

/**
 * The questions that apply to a requisition: the ones written for that role,
 * plus the ones written for the whole project.
 */
function screening_questions(int $jobId, ?int $vacancyId): array
{
    return rows('SELECT * FROM screening_questions
                 WHERE job_id = ? AND (vacancy_id IS NULL OR vacancy_id = ?)
                 ORDER BY sort_order, id', [$jobId, $vacancyId ?? 0]);
}

/** The choices a question offers, one per line in the stored text. */
function screening_choices(array $question): array
{
    return array_values(array_filter(array_map(
        'trim', explode("\n", (string) ($question['choices'] ?? '')))));
}

/**
 * Is this answer the one that disqualifies?
 *
 * Compared case-insensitively and trimmed, because a person typing "Yes"
 * and a form posting "yes" mean the same thing and a knockout must not turn
 * on capitalisation.
 */
function screening_is_knockout(array $question, string $answer): bool
{
    $knockout = trim((string) ($question['knockout_answer'] ?? ''));

    if ($knockout === '') {
        return false;
    }

    // A number question can set a minimum: "<2" disqualifies anything under 2
    // (walkthrough finding: "2 years required" could not be screened, so one
    // year scored the same as six). Any other knockout is the exact answer.
    if (($question['answer_type'] ?? '') === 'number' && preg_match('/^<\s*(\d+(?:\.\d+)?)$/', $knockout, $min)) {
        $given = trim($answer);
        return is_numeric($given) && (float) $given < (float) $min[1];
    }

    return mb_strtolower(trim($answer)) === mb_strtolower($knockout);
}

/**
 * Did this answer earn its weight?
 *
 * A knockout answer never does. Otherwise a yes/no or choice question earns
 * its weight for any answer that is not the knockout, and a text or number
 * question earns it for being answered at all - the words themselves are for
 * a person to read, not for arithmetic to judge.
 */
function screening_answer_scores(array $question, string $answer): bool
{
    return screening_answer_share($question, $answer) > 0;
}

/**
 * P5-M43: how much of its weight an answer earns, from 0 to 1 - Crewvia's
 * own scoring, set per question by the office:
 *   - a number question with "full marks at" earns in proportion up to it:
 *     full marks at 5 years, 2 years earn 40%; under the minimum, nothing;
 *   - a choice question with points per answer earns that share: 100% travel
 *     earns 100, 50% earns 40, 25% earns 0; an answer it does not list, 0;
 *   - otherwise, as before, any answer that is not the knockout earns it all.
 */
function screening_answer_share(array $question, string $answer): float
{
    $answer = trim($answer);
    if ($answer === '' || screening_is_knockout($question, $answer)) {
        return 0.0;
    }
    $full = $question['full_marks_at'] ?? null;
    if (($question['answer_type'] ?? '') === 'number' && $full !== null && (float) $full > 0) {
        return is_numeric($answer) ? max(0.0, min(1.0, (float) $answer / (float) $full)) : 0.0;
    }
    $points = screening_choice_points($question);
    if ($points) {
        return ($points[mb_strtolower($answer)] ?? 0) / 100;
    }

    return 1.0;
}

/**
 * P5-M43: the points each answer of a choice question earns, as written on the
 * question ("100% = 100", one per line), keyed by the answer in lower case.
 *
 * @return array<string,int>
 */
function screening_choice_points(array $question): array
{
    $out = [];
    foreach (explode("\n", (string) ($question['choice_points'] ?? '')) as $line) {
        if (preg_match('/^(.+?)\s*=\s*(\d{1,3})\s*$/', trim($line), $m)) {
            $out[mb_strtolower(trim($m[1]))] = min(100, (int) $m[2]);
        }
    }

    return $out;
}

/**
 * P5-M43: the graded rules a question form sends, checked: "full marks at"
 * for a number question, points per answer for a choice question.
 *
 * @return array{0:?float,1:?string,2:?string} full marks at, points, an error
 */
function screening_rules_from_post(string $type, array $post): array
{
    $full = trim((string) ($post['full_marks_at'] ?? ''));
    $points = trim(str_replace("\r", '', (string) ($post['choice_points'] ?? '')));
    if ($type !== 'number') { $full = ''; }
    if ($type !== 'choice') { $points = ''; }
    if ($full !== '' && (! is_numeric($full) || (float) $full <= 0 || (float) $full > 999999)) {
        return [null, null, t('Full marks at is a number above 0, such as 5 for five years.')];
    }
    foreach (array_filter(array_map('trim', explode("\n", $points))) as $line) {
        if (! preg_match('/^.+=\s*\d{1,3}$/', $line) || (int) substr($line, (int) strrpos($line, '=') + 1) > 100) {
            return [null, null, t('Write the points one answer per line, from 0 to 100: 100% = 100')];
        }
    }

    return [$full !== '' ? (float) $full : null, $points !== '' ? mb_substr($points, 0, 500) : null, null];
}

/**
 * P5-M43: a job board's screening question and answer, recorded against the
 * requisition's own question so Crewvia's rules score it. The question is
 * found by the board's wording; the first time a wording is seen, a question
 * is created for the requisition with no weight, for the office to set its
 * rules (Screening questions). Returns the question id.
 */
function screening_record_board_answer(int $applicationId, int $jobId, int $vacancyId, string $wording, string $answer, ?int $by): int
{
    $wording = mb_substr(trim($wording), 0, 500);
    $answer = mb_substr(trim($answer), 0, 4000);
    $question = row('SELECT * FROM screening_questions WHERE job_id = ? AND (vacancy_id IS NULL OR vacancy_id = ?) AND (board_question = ? OR question = ?) ORDER BY vacancy_id IS NULL, id LIMIT 1',
                    [$jobId, $vacancyId, $wording, $wording]);
    if (! $question) {
        $type = match (true) {
            is_numeric($answer) => 'number',
            in_array(mb_strtolower($answer), ['yes', 'no'], true) => 'yes_no',
            (bool) preg_match('/^\d{1,3}\s*%$/', $answer) => 'choice',
            default => 'text',
        };
        $next = (int) val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM screening_questions WHERE job_id = ?', [$jobId]);
        q("INSERT INTO screening_questions (job_id, vacancy_id, question, required, answer_type, choices, weight, knockout_answer, sort_order, board_question, origin)
           VALUES (?,?,?,0,?,?,0,NULL,?,?,'board')", [$jobId, $vacancyId, $wording, $type, $type === 'choice' ? $answer : null, $next, $wording]);
        $question = row('SELECT * FROM screening_questions WHERE id = ?', [(int) db()->lastInsertId()]);
    } elseif ($question['answer_type'] === 'choice' && $answer !== ''
              && ! in_array(mb_strtolower($answer), array_map('mb_strtolower', screening_choices($question)), true)) {
        // A choice the board offered that the question did not list yet.
        $choices = trim((string) $question['choices'] . "\n" . $answer);
        if (mb_strlen($choices) <= 500) {
            q('UPDATE screening_questions SET choices = ? WHERE id = ?', [$choices, (int) $question['id']]);
        }
    }
    q('INSERT INTO screening_answers (application_id, question_id, answer, recorded_by, from_board) VALUES (?,?,?,?,1)
       ON DUPLICATE KEY UPDATE answer = VALUES(answer)', [$applicationId, (int) $question['id'], $answer, $by]);

    return (int) $question['id'];
}

/**
 * Score one application and record the result.
 *
 * @return array{score:?float,out:bool,why:?string,asked:int,answered:int}
 */
function screening_score_application(int $applicationId): array
{
    $application = row('SELECT a.*, v.job_id FROM applications a
                        JOIN vacancies v ON v.id = a.vacancy_id
                        WHERE a.id = ?', [$applicationId]);

    if (! $application) {
        return ['score' => null, 'out' => false, 'why' => null, 'asked' => 0, 'answered' => 0];
    }

    $questions = screening_questions((int) $application['job_id'], (int) $application['vacancy_id']);

    if (! $questions) {
        return ['score' => null, 'out' => false, 'why' => null, 'asked' => 0, 'answered' => 0];
    }

    $answers = [];
    $fromBoard = [];

    foreach (rows('SELECT question_id, answer, from_board FROM screening_answers WHERE application_id = ?',
                  [$applicationId]) as $given) {
        $answers[(int) $given['question_id']] = (string) $given['answer'];
        if ((int) $given['from_board']) { $fromBoard[(int) $given['question_id']] = true; }
    }

    // P5-M43: somebody who applied on a job board was asked the board's questions, not
    // Crewvia's; scoring them against questions nobody put to them would invent a number.
    if ($fromBoard) {
        $questions = array_values(array_filter($questions, static fn($q) => isset($fromBoard[(int) $q['id']])));
    }

    $available = 0;
    $earned    = 0;
    $answered  = 0;
    $why       = null;

    foreach ($questions as $question) {
        $weight = max(0, (int) $question['weight']);
        $answer = $answers[(int) $question['id']] ?? '';

        $available += $weight;

        if (trim($answer) !== '') {
            $answered++;
        }

        if (screening_is_knockout($question, $answer) && $why === null) {
            $why = mb_substr($question['question'] . ' — ' . trim($answer), 0, 255);
        }

        $earned += $weight * screening_answer_share($question, $answer);
    }

    $score = $available > 0 ? round($earned / $available * 100, 2) : null;

    // Until the office gives the board's questions a weight, the board's own verdict (P5-M42) stands.
    if ($fromBoard && $score === null && $why === null) {
        return ['score' => null, 'out' => false, 'why' => null, 'asked' => count($questions), 'answered' => $answered];
    }

    q('UPDATE applications SET screening_score = ?, screened_out = ?, screened_out_why = ?
       WHERE id = ?',
      [$score, $why !== null ? 1 : 0, $why, $applicationId]);

    return ['score' => $score, 'out' => $why !== null, 'why' => $why,
            'asked' => count($questions), 'answered' => $answered];
}

/**
 * Store the answers an applicant gave, then score what they said.
 *
 * @param array<int,string> $given question id => answer
 */
function screening_record_answers(int $applicationId, array $given, ?int $recordedBy = null): void
{
    foreach ($given as $questionId => $answer) {
        $questionId = (int) $questionId;
        $answer     = trim((string) $answer);

        if ($questionId <= 0) {
            continue;
        }

        q('INSERT INTO screening_answers (application_id, question_id, answer, recorded_by)
           VALUES (?,?,?,?)
           ON DUPLICATE KEY UPDATE answer = VALUES(answer), recorded_by = VALUES(recorded_by)',
          [$applicationId, $questionId, mb_substr($answer, 0, 4000), $recordedBy]);
    }

    screening_score_application($applicationId);
}

/**
 * Re-rank everything already on the board.
 *
 * Changing the questions changes what the answers were worth, so the
 * applications already received are scored again rather than left showing a
 * number that was calculated against a different set.
 */
function screening_rescore_job(int $jobId): int
{
    $done = 0;

    foreach (rows('SELECT a.id FROM applications a
                   JOIN vacancies v ON v.id = a.vacancy_id
                   WHERE v.job_id = ? LIMIT 2000', [$jobId]) as $application) {
        screening_score_application((int) $application['id']);
        $done++;
    }

    return $done;
}

/**
 * How the questions on a role are performing, which is the only way to know
 * whether they are the right questions.
 */
function screening_stats(int $jobId, ?int $vacancyId = null): array
{
    $where = 'v.job_id = ?' . ($vacancyId ? ' AND a.vacancy_id = ?' : '');
    $args  = $vacancyId ? [$jobId, $vacancyId] : [$jobId];

    $row = row("SELECT COUNT(*) applications,
                       SUM(a.screened_out = 1) screened_out,
                       AVG(a.screening_score) average_score,
                       SUM(a.screening_score IS NULL) unscored
                FROM applications a
                JOIN vacancies v ON v.id = a.vacancy_id
                WHERE " . $where, $args) ?: [];

    return [
        'applications' => (int) ($row['applications'] ?? 0),
        'screened_out' => (int) ($row['screened_out'] ?? 0),
        'average'      => $row['average_score'] !== null ? round((float) $row['average_score']) : null,
        'unscored'     => (int) ($row['unscored'] ?? 0),
    ];
}
