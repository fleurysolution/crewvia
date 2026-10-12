-- Rollback for P5-M08 (Twilio: credentials, texts and calls through Crewvia).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M08 first.
--   * Lost by this rollback: the Twilio credentials entered in Crewvia, and
--     the record of each text and call made through Twilio (their statuses,
--     replies received). Every text and reply is also in the contact
--     register (candidate_calls), which is kept.
--   * Kept on purpose: Twilio's lines in the integration audit trail, and
--     "twilio" among its providers, so no audit line is blanked.
--
-- Run:  mysql <database> < install/rollback/p5-m08.sql

DELETE FROM connector_modes WHERE connector = 'twilio';
ALTER TABLE connector_modes MODIFY kind ENUM('payroll','ats','screening') NOT NULL;
DROP TABLE IF EXISTS voice_calls;
DROP TABLE IF EXISTS sms_messages;
DROP TABLE IF EXISTS provider_credentials;
