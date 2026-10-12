-- Rollback for P5-M06b (trusted phones and the offline attendance log).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M06b first.
--   * Lost by this rollback: every trusted phone (they stop syncing at
--     once) and the log of what arrived from them and when it was typed.
--   * Kept: the hours and gate marks themselves, in attendance_records and
--     assignment_checkins, as if they had been entered on the screens.
--
-- Run:  mysql <database> < install/rollback/p5-m06b.sql

DROP TABLE IF EXISTS attendance_sync_log;
DROP TABLE IF EXISTS device_grants;
