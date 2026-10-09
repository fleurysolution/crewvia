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
    if (trim($answer) === '') {
        return false;
    }

    return ! screening_is_knockout($question, $answer);
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

    foreach (rows('SELECT question_id, answer FROM screening_answers WHERE application_id = ?',
                  [$applicationId]) as $given) {
        $answers[(int) $given['question_id']] = (string) $given['answer'];
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

        if (screening_answer_scores($question, $answer)) {
            $earned += $weight;
        }
    }

    $score = $available > 0 ? round($earned / $available * 100, 2) : null;

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
