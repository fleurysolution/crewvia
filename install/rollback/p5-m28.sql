-- Rollback for P5-M28 (the notices along the flow, offer validation, a plant's project manager).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M28 first.
--   * Lost by this rollback: which person manages each project. Notifications
--     already sent stay; offers waiting for validation stay in Approvals.
--
-- Run:  mysql <database> < install/rollback/p5-m28.sql

ALTER TABLE jobs DROP COLUMN project_manager_id;
