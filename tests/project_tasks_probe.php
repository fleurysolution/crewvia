<?php
/**
 * The P5-M01 test's eyes and hands, on the test database only.
 *   read [job]             tasks, milestones, events and notifications
 *   due <task> <date>      backdates a deadline, to make a task overdue
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

if (($argv[1] ?? '') === 'due') {
    q('UPDATE project_tasks SET due_on = ? WHERE id = ?', [(string) $argv[3], (int) $argv[2]]);
    exit(0);
}

$job = (int) ($argv[2] ?? 0);
echo json_encode([
    'tasks' => rows('SELECT id, job_id, milestone_id, title, assignee_id, status, priority, due_on, blocked_reason, completed_at, created_by FROM project_tasks' . ($job ? ' WHERE job_id = ' . $job : '') . ' ORDER BY id'),
    'milestones' => rows('SELECT id, job_id, title, due_on, cancelled_at FROM project_milestones ORDER BY id'),
    'events' => rows('SELECT task_id, user_id, kind, old_value, new_value, note FROM project_task_events ORDER BY id'),
    'notifications' => rows("SELECT user_id, message, target FROM notifications WHERE target LIKE '%task%' ORDER BY id"),
    'activity' => rows("SELECT user_id, action, entity_id, detail FROM activity WHERE entity IN ('project_task','project_milestone') ORDER BY id"),
]);
