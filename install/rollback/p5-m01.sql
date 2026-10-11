-- Rollback for P5-M01 (project milestones, tasks and task history).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M01 first.
--   * Lost by this rollback: every milestone, every task and the whole
--     history of each task. Projects, crews and everything else stay.
--
-- Run:  mysql <database> < install/rollback/p5-m01.sql

-- P5-M02's dependencies point at tasks and go with them (roll back P5-M02
-- first to keep its sprints and capacities removed cleanly as well).
DROP TABLE IF EXISTS project_task_dependencies;
DROP TABLE IF EXISTS project_task_events;
-- If P5-M02 is still in place, the task's foreign keys to sprints go with the table.
DROP TABLE IF EXISTS project_tasks;
DROP TABLE IF EXISTS project_milestones;
