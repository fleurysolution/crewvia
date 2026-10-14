-- Rollback for P5-M29 (campaigns by text message).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M29 first.
--   * Lost by this rollback: the text campaigns and their recipient lists.
--     The texts themselves stay in each person's messages (sms_messages),
--     and anyone who replied STOP stays opted out of texts.
--
-- Run:  mysql <database> < install/rollback/p5-m29.sql

DELETE r FROM email_campaign_recipients r JOIN email_campaigns m ON m.id = r.campaign_id WHERE m.channel = 'text';
DELETE FROM email_campaigns WHERE channel = 'text';
ALTER TABLE email_campaign_recipients DROP COLUMN phone, MODIFY email VARCHAR(190) NOT NULL;
ALTER TABLE email_campaigns DROP COLUMN channel;
