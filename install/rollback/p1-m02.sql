-- Rollback for P1-M02 (attendance corrections, staff-entered days).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the archive that precedes P1-M02 first: its code reads every
--     object dropped here.
--   * Lost by this rollback: the correction history, and which days were
--     entered by staff and the note they gave. The days themselves stay,
--     with their current (corrected) hours.
--
-- Run:  mysql <database> < install/rollback/p1-m02.sql
-- Re-applying afterwards is safe: php install/upgrade.php recreates it.

DROP TABLE IF EXISTS attendance_corrections;

ALTER TABLE attendance_records DROP COLUMN note, DROP COLUMN source;
