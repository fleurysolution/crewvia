-- Rollback for P5-M31 (the worker's registration with ADP).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M31 first.
--   * Lost by this rollback: when each worker said they registered with ADP,
--     and the registration code shown to workers. ADP employee IDs stay.
--
-- Run:  mysql <database> < install/rollback/p5-m31.sql

ALTER TABLE employee_profiles DROP COLUMN adp_registered_at;
DELETE FROM platform_settings WHERE setting_key IN ('adp_registration_code','adp_registration_url','adp_registration_note');
