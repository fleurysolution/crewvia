-- Rollback for P5-M37 (the office attaches a candidate's CV, cover letter or other document).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M37 first.
--   * Lost by this rollback: which attachment is a CV, a cover letter or
--     something else, and who attached it. The files themselves stay.
--
-- Run:  mysql <database> < install/rollback/p5-m37.sql

ALTER TABLE candidate_resumes DROP COLUMN kind, DROP COLUMN attached_by;
