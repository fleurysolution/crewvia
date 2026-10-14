-- Rollback for P5-M33 (restricted identity documents, the W-4 signed in person).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M33 first: that code lets recruiting
--     open every document again.
--   * Lost by this rollback: which documents are restricted, who was
--     authorized to read them, the signing codes, and who witnessed each
--     W-4 signed in person. The documents and the W-4 statuses stay.
--
-- Run:  mysql <database> < install/rollback/p5-m33.sql

DROP TABLE IF EXISTS signing_codes;
DROP TABLE IF EXISTS identity_readers;
ALTER TABLE employment_clearance DROP COLUMN w4_witness_id, DROP COLUMN w4_in_person_at;
ALTER TABLE worker_documents DROP COLUMN restricted;
