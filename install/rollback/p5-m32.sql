-- Rollback for P5-M32 (the room number, the clock-in, the supervisor's validation).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M32 first.
--   * Lost by this rollback: every clock-in and its validation, and when each
--     worker was told their hotel. Room numbers and roll call marks stay.
--
-- Run:  mysql <database> < install/rollback/p5-m32.sql

DROP TABLE IF EXISTS clock_ins;
ALTER TABLE lodging DROP COLUMN room_told_at;
