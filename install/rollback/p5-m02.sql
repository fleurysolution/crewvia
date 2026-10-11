-- Rollback for P5-M02 (subtasks, dependencies, estimates, sprints, capacity).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M02 first.
--   * Lost by this rollback: which task is a subtask of which, every
--     dependency, every estimate, every sprint and every weekly capacity.
--     The tasks themselves, their statuses and history stay (P5-M01).
--
-- Run:  mysql <database> < install/rollback/p5-m02.sql

ALTER TABLE project_tasks DROP FOREIGN KEY fk_task_parent;
ALTER TABLE project_tasks DROP FOREIGN KEY fk_task_sprint;
ALTER TABLE project_tasks DROP COLUMN parent_id, DROP COLUMN estimate_hours, DROP COLUMN sprint_id;
DROP TABLE IF EXISTS project_task_dependencies;
DROP TABLE IF EXISTS project_sprints;
DROP TABLE IF EXISTS user_capacity;
