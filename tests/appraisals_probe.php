<?php
/** Read-only: the P2-M02 project's appraisals, scores, events and roster reviews, as JSON. */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$job = (int) ($argv[1] ?? 0);
$worker = (int) ($argv[2] ?? 0);

echo json_encode([
    'templates'  => rows('SELECT t.id, t.code, t.kind, t.scale_max, t.self_review, t.is_active, (SELECT COUNT(*) FROM appraisal_template_criteria c WHERE c.template_id = t.id) AS criteria FROM appraisal_templates t ORDER BY t.id'),
    'weights'    => rows('SELECT template_id, criterion_slug, weight FROM appraisal_template_criteria ORDER BY template_id, sort_order'),
    'criteria'   => rows('SELECT slug FROM assignment_review_criteria ORDER BY sort_order'),
    'appraisals' => rows('SELECT a.id, a.template_id, a.placement_id, a.status, a.reviewer_id, a.supervisor_by, a.decided_by, a.score_percent, a.grade, a.would_rehire
                          FROM appraisals a JOIN placements p ON p.id = a.placement_id WHERE p.job_id = ? ORDER BY a.id', [$job]),
    'scores'     => rows('SELECT s.* FROM appraisal_scores s JOIN appraisals a ON a.id = s.appraisal_id JOIN placements p ON p.id = a.placement_id WHERE p.job_id = ?', [$job]),
    'events'     => rows('SELECT e.appraisal_id, e.event, e.user_id FROM appraisal_events e JOIN appraisals a ON a.id = e.appraisal_id JOIN placements p ON p.id = a.placement_id WHERE p.job_id = ? ORDER BY e.id', [$job]),
    'reviews'    => rows('SELECT r.placement_id, r.grade, r.would_rehire, r.reviewed_by, (SELECT COUNT(*) FROM assignment_review_scores s WHERE s.review_id = r.id) AS scores
                          FROM assignment_reviews r JOIN placements p ON p.id = r.placement_id WHERE p.job_id = ?', [$job]),
    'notified'   => rows("SELECT message FROM notifications WHERE user_id = ? AND target LIKE '/appraisals%' ORDER BY id", [$worker]),
], JSON_NUMERIC_CHECK);
