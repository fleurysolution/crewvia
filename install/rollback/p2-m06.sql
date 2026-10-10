-- Rollback for P2-M06 (recognition, disciplinary cases, separations,
-- controlled HR access).
--
-- READ BEFORE RUNNING
--   * Take a database backup first. These are personnel records: an
--     employer may be required to keep them. Export them before rolling
--     back, and keep the export where personnel files are kept.
--   * Deploy the code that precedes P2-M06 first.
--   * Lost by this rollback: every recognition, disciplinary case with its
--     notes and the person's account, every separation record, the HR
--     access grants and the log of who opened which record. Rehire
--     decisions a separation put on the register stay on the register.
--
-- Run:  mysql <database> < install/rollback/p2-m06.sql

DROP TABLE IF EXISTS separations;
DROP TABLE IF EXISTS disciplinary_notes;
DROP TABLE IF EXISTS disciplinary_cases;
DROP TABLE IF EXISTS recognitions;
DROP TABLE IF EXISTS hr_access_grants;
DROP TABLE IF EXISTS hr_access_log;
