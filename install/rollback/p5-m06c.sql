-- Rollback for P5-M06c (push notifications).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M06c first.
--   * Lost by this rollback: the agency's push key pair, every phone's
--     subscription and the log of what was sent. Phones stop receiving
--     pushes. Crewvia's own notifications are untouched.
--
-- Run:  mysql <database> < install/rollback/p5-m06c.sql

DELETE FROM platform_settings WHERE setting_key IN ('push_enabled', 'push_cursor');
DROP TABLE IF EXISTS push_deliveries;
DROP TABLE IF EXISTS push_subscriptions;
DROP TABLE IF EXISTS push_keys;
