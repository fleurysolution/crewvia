-- Rollback for P5-M01 (project milestones, tasks and task history).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M01 first.
--   * Lost by this rollback: every milestone, every task and the whole
--     history of each task. Projects, crews and everything else stay.
--
-- Run:  mysql <database> < install/rollback/p5-m01.sql

DROP TABLE IF EXISTS project_task_events;
DROP TABLE IF EXISTS project_tasks;
DROP TABLE IF EXISTS project_milestones;
