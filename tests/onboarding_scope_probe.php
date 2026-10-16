<?php
/**
 * The P5-M55 test's eyes, on the test database only.
 *   tasks <title>   for the requirement with this title: the requirement id, and each task with its placement's status
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
[$cmd, $arg] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($cmd === 'tasks') {
    $r = row('SELECT id, for_crew FROM onboarding_requirements WHERE title = ? ORDER BY id DESC LIMIT 1', [$arg]);
    echo json_encode(['requirement' => $r ? (int) $r['id'] : null, 'for_crew' => $r ? (int) $r['for_crew'] : null,
                      'count' => (int) val('SELECT COUNT(*) FROM onboarding_requirements WHERE title = ?', [$arg]),
                      'tasks' => $r ? rows('SELECT t.id, t.status, t.response, p.status AS placement, c.email FROM onboarding_tasks t JOIN placements p ON p.id = t.placement_id JOIN candidates c ON c.id = p.candidate_id WHERE t.requirement_id = ? ORDER BY t.id', [(int) $r['id']]) : []]);
}
