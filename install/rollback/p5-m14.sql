-- Rollback for P5-M14 (T19: lateness).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M14 first.
--   * Lost by this rollback: every recorded late arrival, its dispute and
--     decision, and each project's shift start, grace and rounding. Weekly
--     sheets keep their hours; a sheet imported after a lateness was docked
--     keeps the reduced hours. Re-import an open week to restore them.
--
-- Run:  mysql <database> < install/rollback/p5-m14.sql

ALTER TABLE timesheets DROP COLUMN late_docked_hours, DROP COLUMN late_billed_hours;
ALTER TABLE assignment_details DROP COLUMN shift_start;
DROP TABLE IF EXISTS attendance_lateness;
DROP TABLE IF EXISTS lateness_policies;
