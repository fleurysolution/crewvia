-- Rollback for P5-M18 (T7: campaign email).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M18 first, and remove the
--     deliver-campaigns.php cron line.
--   * Lost by this rollback: every campaign, who it went to, and every
--     "do not email" a person asked for by its unsubscribe link. Keep a copy
--     of the opt-outs if they must be honoured again later:
--       SELECT candidate_id, no_email_since FROM contact_preferences WHERE no_email_since IS NOT NULL;
--
-- Run:  mysql <database> < install/rollback/p5-m18.sql

ALTER TABLE contact_preferences DROP COLUMN no_email_since;
DELETE FROM platform_settings WHERE setting_key = 'campaign_postal_address';
DROP TABLE IF EXISTS email_campaign_recipients;
DROP TABLE IF EXISTS email_campaigns;
