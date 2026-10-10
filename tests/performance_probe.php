<?php
/**
 * The P2-M03 test's eyes, on the test database only: the project's cycles,
 * reviews, goals, updates, actions and notifications. "overdue <action id>"
 * dates an action yesterday, as time passing would.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$job = (int) ($argv[1] ?? 0);
if (($argv[2] ?? '') === 'overdue') {
    q('UPDATE development_actions SET due_on = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = ?', [(int) ($argv[3] ?? 0)]);
    exit(0);
}
$num = static fn(array $rows, array $keys): array => array_map(static function (array $r) use ($keys): array {
    foreach ($keys as $k) {
        if (isset($r[$k])) { $r[$k] = $r[$k] + 0; }
    }
    return $r;
}, $rows);
$people = 'SELECT candidate_id FROM placements WHERE job_id = ' . $job;

echo json_encode([
    'cycles'   => $num(rows('SELECT id, name, status FROM appraisal_cycles ORDER BY id'), ['id']),
    'reviews'  => $num(rows('SELECT a.id, a.candidate_id, a.cycle_id, a.status, a.reviewer_id FROM appraisals a JOIN placements p ON p.id = a.placement_id WHERE p.job_id = ? ORDER BY a.id', [$job]), ['id', 'candidate_id', 'cycle_id', 'reviewer_id']),
    'goals'    => $num(rows("SELECT id, candidate_id, title, progress, status, appraisal_id FROM performance_goals WHERE candidate_id IN ($people) ORDER BY id"), ['id', 'candidate_id', 'progress', 'appraisal_id']),
    'updates'  => $num(rows("SELECT x.goal_id, x.progress, x.note, x.by_self FROM goal_updates x JOIN performance_goals g ON g.id = x.goal_id WHERE g.candidate_id IN ($people) ORDER BY x.id"), ['goal_id', 'progress', 'by_self']),
    'actions'  => $num(rows("SELECT id, candidate_id, kind, owner_id, status, outcome FROM development_actions WHERE candidate_id IN ($people) ORDER BY id"), ['id', 'candidate_id', 'owner_id']),
    'notified' => $num(rows("SELECT user_id, message FROM notifications WHERE target = '/performance' ORDER BY id"), ['user_id']),
]);
