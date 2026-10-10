<?php
/**
 * Performance appraisals (P2-M02).
 *
 * A template picks criteria from the catalogue the end-of-assignment review
 * already uses, weights them, sets the scale and the grade thresholds. An
 * appraisal of one person on one assignment then moves:
 *
 *   self_review        the worker scores themself (if the template asks
 *                      and the worker has an account), or a recruiter
 *                      skips it with a reason
 *   supervisor_review  the reviewer scores every criterion; a lowest score
 *                      and "would not rehire" each need a reason
 *   awaiting_approval  somebody other than the reviewer approves, or
 *                      returns it with a note
 *   approved           frozen; an end-of-assignment appraisal also becomes
 *                      the roster's review of that assignment
 *
 * Only the supervisor's scores make the grade: score / scale, weighted, as a
 * percentage, against the template's thresholds. Every step is an event.
 */

declare(strict_types=1);

require_once __DIR__ . '/hr.php';

function appraisal_statuses(): array
{
    return ['self_review' => t('Self-review'), 'supervisor_review' => t('Supervisor review'), 'awaiting_approval' => t('Awaiting approval'),
            'approved' => t('Approved'), 'cancelled' => t('Cancelled')];
}

function appraisal_kinds(): array
{
    return ['end_of_assignment' => t('End of assignment'), 'periodic' => t('Periodic'), 'probation' => t('End of probation')];
}

function appraisal_event_labels(): array
{
    return ['opened' => t('Opened'), 'self_submitted' => t('Self-review submitted'), 'self_skipped' => t('Self-review skipped'),
            'scored' => t('Scored by the reviewer'), 'returned' => t('Returned to the reviewer'), 'approved' => t('Approved'),
            'cancelled' => t('Cancelled')];
}

function appraisal_event(int $id, string $event, ?string $detail = null): void
{
    q('INSERT INTO appraisal_events (appraisal_id, event, detail, user_id) VALUES (?,?,?,?)',
      [$id, $event, $detail !== null && $detail !== '' ? mb_substr($detail, 0, 1000) : null, uid() ?: null]);
}

function appraisal_notify(?int $userId, string $message, int $id): void
{
    if ($userId) {
        q('INSERT INTO notifications (user_id, message, target) VALUES (?,?,?)', [$userId, $message, '/appraisals?id=' . $id]);
    }
}

function appraisal_templates(bool $activeOnly = true): array
{
    return rows('SELECT t.*, (SELECT COUNT(*) FROM appraisals a WHERE a.template_id = t.id) AS used,
                        (SELECT COUNT(*) FROM appraisal_template_criteria c WHERE c.template_id = t.id) AS criteria
                 FROM appraisal_templates t' . ($activeOnly ? ' WHERE t.is_active = 1' : '') . ' ORDER BY t.is_active DESC, t.label');
}

/** The criteria of a template, in order, with their labels and weights. */
function appraisal_template_criteria(int $templateId): array
{
    return rows('SELECT c.criterion_slug AS slug, c.weight, k.label FROM appraisal_template_criteria c
                 JOIN assignment_review_criteria k ON k.slug = c.criterion_slug
                 WHERE c.template_id = ? ORDER BY c.sort_order, k.sort_order', [$templateId]);
}

/** The grade a percentage earns on a template. */
function appraisal_grade(float $percent, array $template): string
{
    foreach (['A' => 'grade_a', 'B' => 'grade_b', 'C' => 'grade_c', 'D' => 'grade_d'] as $grade => $col) {
        if ($percent >= (float) $template[$col]) {
            return $grade;
        }
    }

    return 'F';
}

/** Weighted score as a percentage: each score over the scale, times its weight. */
function appraisal_percent(array $criteria, array $scores, int $scaleMax): float
{
    $sum = 0.0;
    $weights = 0;
    foreach ($criteria as $c) {
        $sum += (int) $c['weight'] * ((int) $scores[$c['slug']] / $scaleMax);
        $weights += (int) $c['weight'];
    }

    return $weights ? round($sum / $weights * 100, 2) : 0.0;
}

/** The appraisal with what the screens need around it. */
function appraisal(int $id): ?array
{
    $a = row('SELECT a.*, t.label AS template, t.kind, t.scale_max, t.self_review AS template_self, t.grade_a, t.grade_b, t.grade_c, t.grade_d,
                     c.full_name, j.title AS project, p.job_id, d.supervisor_id AS site_supervisor,
                     r.name AS reviewer, s.name AS scored_by, x.name AS decided_by_name,
                     (SELECT w.user_id FROM worker_accounts w WHERE w.candidate_id = a.candidate_id LIMIT 1) AS worker_user
              FROM appraisals a JOIN appraisal_templates t ON t.id = a.template_id
              JOIN candidates c ON c.id = a.candidate_id JOIN placements p ON p.id = a.placement_id JOIN jobs j ON j.id = p.job_id
              LEFT JOIN assignment_details d ON d.placement_id = p.id
              LEFT JOIN users r ON r.id = a.reviewer_id LEFT JOIN users s ON s.id = a.supervisor_by LEFT JOIN users x ON x.id = a.decided_by
              WHERE a.id = ?', [$id]);

    return $a ?: null;
}

/** Is the signed-in user the worker being appraised? */
function appraisal_is_subject(array $a): bool
{
    return $a['worker_user'] !== null && (int) $a['worker_user'] === uid();
}

/** Is the signed-in user the one who scores it? */
function appraisal_is_reviewer(array $a): bool
{
    return uid() && ((int) $a['reviewer_id'] === uid() || (! $a['reviewer_id'] && (int) $a['site_supervisor'] === uid()));
}

/**
 * Who may open the appraisal. A worker sees their own while it asks for
 * their view and once it is approved, never the supervisor's draft.
 */
function appraisal_can_view(array $a): bool
{
    $role = (string) (user()['role'] ?? '');

    if ($role === 'worker') {
        return appraisal_is_subject($a) && in_array($a['status'], ['self_review', 'approved'], true);
    }
    if ($role === 'supervisor') {
        return appraisal_is_reviewer($a) || (int) $a['site_supervisor'] === uid();
    }

    return can('recruiter');
}

/** Scores from a form, checked against the template. Returns [scores, comments, refusal]. */
function appraisal_read_scores(array $criteria, int $scaleMax, array $in, array $comments, bool $lowNeedsReason): array
{
    $scores = [];
    $notes = [];
    foreach ($criteria as $c) {
        $raw = $in[$c['slug']] ?? '';
        if (! is_numeric($raw) || (int) $raw != $raw || (int) $raw < 1 || (int) $raw > $scaleMax) {
            return [[], [], t('Score every criterion from 1 to :max.', ['max' => $scaleMax])];
        }
        $scores[$c['slug']] = (int) $raw;
        $note = mb_substr(trim((string) ($comments[$c['slug']] ?? '')), 0, 500);
        if ($lowNeedsReason && (int) $raw === 1 && mb_strlen($note) < 3) {
            return [[], [], t('A score of 1 on :criterion needs a comment.', ['criterion' => t($c['label'])])];
        }
        $notes[$c['slug']] = $note !== '' ? $note : null;
    }

    return [$scores, $notes, null];
}

function appraisal_store_scores(int $id, string $rater, array $scores, array $notes): void
{
    q('DELETE FROM appraisal_scores WHERE appraisal_id = ? AND rater = ?', [$id, $rater]);
    foreach ($scores as $slug => $score) {
        q('INSERT INTO appraisal_scores (appraisal_id, criterion_slug, rater, score, comment) VALUES (?,?,?,?,?)', [$id, $slug, $rater, $score, $notes[$slug] ?? null]);
    }
}

/** Open an appraisal. Returns [id, refusal]. */
function appraisal_open(int $placementId, int $templateId, int $reviewerId, string $from, string $to): array
{
    $p = row("SELECT p.*, d.supervisor_id FROM placements p LEFT JOIN assignment_details d ON d.placement_id = p.id
              WHERE p.id = ? AND p.status <> 'cancelled'", [$placementId]);
    $t = row('SELECT * FROM appraisal_templates WHERE id = ? AND is_active = 1', [$templateId]);

    if (! $p) {
        return [0, t('Choose somebody on this project.')];
    }
    if (! $t || ! val('SELECT COUNT(*) FROM appraisal_template_criteria WHERE template_id = ?', [$templateId])) {
        return [0, t('Choose a template in use, with at least one criterion.')];
    }
    if (($from !== '' && ! valid_date($from)) || ($to !== '' && ! valid_date($to)) || ($from !== '' && $to !== '' && $from > $to)) {
        return [0, t('Check the period: a start, an end, the start first.')];
    }
    if (val("SELECT COUNT(*) FROM appraisals WHERE placement_id = ? AND template_id = ? AND status NOT IN ('approved','cancelled')", [$placementId, $templateId])) {
        return [0, t('This person already has that appraisal open on this assignment.')];
    }

    $reviewer = $reviewerId ?: (int) ($p['supervisor_id'] ?? 0);
    if (! $reviewer || ! val("SELECT COUNT(*) FROM users WHERE id = ? AND is_active = 1 AND role IN ('supervisor','recruiter','admin')", [$reviewer])) {
        return [0, t('Choose who reviews: the assignment has no supervisor.')];
    }
    if ((int) val('SELECT COUNT(*) FROM worker_accounts WHERE candidate_id = ? AND user_id = ?', [(int) $p['candidate_id'], $reviewer])) {
        return [0, t('Nobody reviews themself.')];
    }

    $worker = val('SELECT user_id FROM worker_accounts WHERE candidate_id = ? LIMIT 1', [(int) $p['candidate_id']]);
    $status = (int) $t['self_review'] === 1 && $worker ? 'self_review' : 'supervisor_review';

    q('INSERT INTO appraisals (template_id, placement_id, candidate_id, period_from, period_to, status, reviewer_id, created_by) VALUES (?,?,?,?,?,?,?,?)',
      [$templateId, $placementId, (int) $p['candidate_id'], $from ?: null, $to ?: null, $status, $reviewer, uid() ?: null]);
    $id = (int) db()->lastInsertId();
    appraisal_event($id, 'opened', $t['label']);

    if ($status === 'self_review') {
        appraisal_notify((int) $worker, 'Your performance review is ready for your own view', $id);
    } else {
        appraisal_notify($reviewer, 'A performance review is waiting for your scores', $id);
    }

    return [$id, null];
}

/** The worker's own view. Returns the refusal, or null. */
function appraisal_self_submit(int $id, array $scoresIn, array $commentsIn, string $comment): ?string
{
    $a = appraisal($id);
    if (! $a || ! appraisal_is_subject($a)) {
        return t('That review is not yours.');
    }
    if ($a['status'] !== 'self_review') {
        return t('Your part of this review is closed.');
    }

    [$scores, $notes, $why] = appraisal_read_scores(appraisal_template_criteria((int) $a['template_id']), (int) $a['scale_max'], $scoresIn, $commentsIn, false);
    if ($why !== null) {
        return $why;
    }

    appraisal_store_scores($id, 'self', $scores, $notes);
    q("UPDATE appraisals SET status = 'supervisor_review', self_comment = ?, self_submitted_at = NOW() WHERE id = ?", [mb_substr(trim($comment), 0, 2000) ?: null, $id]);
    appraisal_event($id, 'self_submitted');
    appraisal_notify((int) $a['reviewer_id'], 'A performance review is waiting for your scores', $id);

    return null;
}

/** A recruiter moves on without the worker's view. */
function appraisal_skip_self(int $id, string $reason): ?string
{
    $a = appraisal($id);
    if (! $a || $a['status'] !== 'self_review') {
        return t('That review is not waiting for the worker.');
    }
    if (mb_strlen(trim($reason)) < 3) {
        return t('Say why the worker\'s view is skipped.');
    }

    q("UPDATE appraisals SET status = 'supervisor_review' WHERE id = ?", [$id]);
    appraisal_event($id, 'self_skipped', trim($reason));
    appraisal_notify((int) $a['reviewer_id'], 'A performance review is waiting for your scores', $id);

    return null;
}

/** The reviewer's scores. Returns the refusal, or null. */
function appraisal_supervisor_submit(int $id, array $scoresIn, array $commentsIn, string $comment, string $rehire): ?string
{
    $a = appraisal($id);
    if (! $a) {
        return t('That review does not exist.');
    }
    if (! appraisal_is_reviewer($a) && ! can('recruiter')) {
        return t('Only the reviewer, or a recruiter, scores this review.');
    }
    if (appraisal_is_subject($a)) {
        return t('Nobody reviews themself.');
    }
    if ($a['status'] !== 'supervisor_review') {
        return t('This review is not waiting for scores.');
    }

    $criteria = appraisal_template_criteria((int) $a['template_id']);
    [$scores, $notes, $why] = appraisal_read_scores($criteria, (int) $a['scale_max'], $scoresIn, $commentsIn, true);
    if ($why !== null) {
        return $why;
    }
    if (! in_array($rehire, ['0', '1'], true)) {
        return t('Say whether you would have them back.');
    }
    if ($rehire === '0' && mb_strlen(trim($comment)) < 3) {
        return t('Say why you would not have them back. It is the note the next recruiter reads.');
    }

    $percent = appraisal_percent($criteria, $scores, (int) $a['scale_max']);
    $grade = appraisal_grade($percent, $a);

    appraisal_store_scores($id, 'supervisor', $scores, $notes);
    q("UPDATE appraisals SET status = 'awaiting_approval', supervisor_comment = ?, would_rehire = ?, supervisor_by = ?, supervisor_submitted_at = NOW(),
                             score_percent = ?, grade = ? WHERE id = ?",
      [mb_substr(trim($comment), 0, 2000) ?: null, (int) $rehire, uid(), $percent, $grade, $id]);
    appraisal_event($id, 'scored', number_format($percent, 1) . '% · ' . $grade . ($rehire === '0' ? ' · would not rehire' : ''));

    return null;
}

/** Approve, or return to the reviewer. Somebody other than the reviewer decides. */
function appraisal_decide(int $id, string $decision, string $note): ?string
{
    $a = appraisal($id);
    if (! $a || $a['status'] !== 'awaiting_approval') {
        return t('This review is not awaiting approval.');
    }
    if ((int) $a['supervisor_by'] === uid() || (int) $a['reviewer_id'] === uid()) {
        return t('Somebody other than the reviewer approves a review.');
    }
    if (! in_array($decision, ['approve', 'return'], true)) {
        return t('Unknown action.');
    }

    if ($decision === 'return') {
        if (mb_strlen(trim($note)) < 3) {
            return t('Say what the reviewer should look at again.');
        }
        q("UPDATE appraisals SET status = 'supervisor_review', decision_note = ? WHERE id = ?", [trim($note), $id]);
        appraisal_event($id, 'returned', trim($note));
        appraisal_notify((int) ($a['supervisor_by'] ?: $a['reviewer_id']), 'A performance review was returned to you', $id);

        return null;
    }

    q("UPDATE appraisals SET status = 'approved', decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?", [uid(), trim($note) ?: null, $id]);
    appraisal_event($id, 'approved', trim($note) ?: null);

    // The end-of-assignment appraisal is the review of that assignment, so
    // the roster and the person's record read the same grade.
    if ($a['kind'] === 'end_of_assignment') {
        q('INSERT INTO assignment_reviews (placement_id, grade, would_rehire, note, reviewed_by) VALUES (?,?,?,?,?)
           ON DUPLICATE KEY UPDATE grade = VALUES(grade), would_rehire = VALUES(would_rehire), note = VALUES(note),
                                   reviewed_by = VALUES(reviewed_by), reviewed_at = NOW()',
          [(int) $a['placement_id'], $a['grade'], (int) $a['would_rehire'], $a['supervisor_comment'], $a['supervisor_by']]);
        $review = (int) val('SELECT id FROM assignment_reviews WHERE placement_id = ?', [(int) $a['placement_id']]);
        q('DELETE FROM assignment_review_scores WHERE review_id = ?', [$review]);
        foreach (rows("SELECT criterion_slug, score FROM appraisal_scores WHERE appraisal_id = ? AND rater = 'supervisor'", [$id]) as $s) {
            q('INSERT INTO assignment_review_scores (review_id, criterion_slug, score) VALUES (?,?,?)',
              [$review, $s['criterion_slug'], max(1, min(5, (int) round((int) $s['score'] / (int) $a['scale_max'] * 5)))]);
        }
    }

    q('INSERT INTO candidate_events (candidate_id, user_id, event_type, detail) VALUES (?,?,?,?)',
      [(int) $a['candidate_id'], uid(), 'performance review', $a['template'] . ' · ' . number_format((float) $a['score_percent'], 1) . '% · ' . $a['grade']]);
    appraisal_notify($a['worker_user'] !== null ? (int) $a['worker_user'] : null, 'Your performance review was approved', $id);

    return null;
}

function appraisal_cancel(int $id, string $reason): ?string
{
    $a = appraisal($id);
    if (! $a || in_array($a['status'], ['approved', 'cancelled'], true)) {
        return t('Only an open review can be cancelled.');
    }
    if (mb_strlen(trim($reason)) < 3) {
        return t('Say why.');
    }

    q("UPDATE appraisals SET status = 'cancelled', decision_note = ? WHERE id = ?", [trim($reason), $id]);
    appraisal_event($id, 'cancelled', trim($reason));

    return null;
}
