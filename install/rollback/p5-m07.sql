-- Rollback for P5-M07 (texting templates, do-not-text and do-not-call).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M07 first.
--   * Lost by this rollback: every text template, and every "do not text"
--     and "do not call" recorded. Export them first if anyone relies on
--     them: the people who asked to stop must still not be contacted.
--   * Kept: every contact in the register. Texts logged stay as contacts
--     whose note starts "Text:", because the outcomes "texted" and
--     "replied by text" go away with this module.
--
-- Run:  mysql <database> < install/rollback/p5-m07.sql

UPDATE candidate_calls SET note = LEFT(CONCAT('Text: ', COALESCE(note, '')), 65535), outcome = 'reached' WHERE outcome IN ('texted', 'replied_text');
ALTER TABLE candidate_calls MODIFY outcome ENUM('reached','voicemail','no_answer','callback','emailed','replied_email','not_interested','wrong_number') NOT NULL DEFAULT 'reached';
ALTER TABLE candidate_calls DROP COLUMN template_id;
DROP TABLE IF EXISTS contact_preferences;
DROP TABLE IF EXISTS message_templates;
