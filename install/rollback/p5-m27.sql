-- Rollback for P5-M27 (a plant's shifts, and the markup that prices a line).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M27 first.
--   * Lost by this rollback: each plant's list of shifts and the link from an
--     assignment to its shift. Each assignment keeps its shift label and start
--     time, which were written beside the link.
--
-- Run:  mysql <database> < install/rollback/p5-m27.sql

ALTER TABLE assignment_details DROP COLUMN shift_id;
DROP TABLE IF EXISTS project_shifts;
