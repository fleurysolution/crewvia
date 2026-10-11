<?php
/** The P5-M02 test's eyes on the test database: tasks, dependencies, sprints, events, capacities. */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

echo json_encode([
    'tasks' => rows('SELECT id, job_id, milestone_id, parent_id, sprint_id, title, assignee_id, status, estimate_hours, due_on FROM project_tasks ORDER BY id'),
    'dependencies' => rows('SELECT task_id, depends_on_id FROM project_task_dependencies ORDER BY task_id, depends_on_id'),
    'sprints' => rows('SELECT id, job_id, name, status FROM project_sprints ORDER BY id'),
    'events' => rows('SELECT task_id, user_id, kind, old_value, new_value, note FROM project_task_events ORDER BY id'),
    'capacity' => rows('SELECT user_id, hours_per_week FROM user_capacity ORDER BY user_id'),
]);
