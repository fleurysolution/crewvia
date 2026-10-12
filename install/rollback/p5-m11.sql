-- Rollback for P5-M11 (sign-in lock, client portal switch, frozen week).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M11 first: without the switch, client
--     accounts sign in again whenever they exist.
--   * Nothing else is stored: the sign-in counts stay in auth_rate_limits.
--
-- Run:  mysql <database> < install/rollback/p5-m11.sql

DELETE FROM platform_settings WHERE setting_key = 'client_portal_enabled';
