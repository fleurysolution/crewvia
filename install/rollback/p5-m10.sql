-- Rollback for P5-M10 (the Hiring workspace).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M10 first.
--   * Lost by this rollback: the interest shown on each application (yes,
--     maybe, no). Applications, their stages, notes and checks are kept.
--
-- Run:  mysql <database> < install/rollback/p5-m10.sql

ALTER TABLE applications DROP COLUMN interest, DROP COLUMN interest_by, DROP COLUMN interest_at;
