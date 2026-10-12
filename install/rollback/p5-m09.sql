-- Rollback for P5-M09 (driving-record checks: MVR, Clearinghouse, PSP).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M09 first.
--   * Lost by this rollback: every driving-record check recorded, with the
--     consent, the results and when each is due again. FMCSA requires an
--     employer to keep driver qualification records: export them first.
--   * Kept: the CDL and medical certificate qualifications they wrote, and
--     those two qualification types.
--
-- Run:  mysql <database> < install/rollback/p5-m09.sql

DELETE FROM platform_settings WHERE setting_key IN ('driver_link_mvr', 'driver_link_clearinghouse', 'driver_link_psp');
DROP TABLE IF EXISTS driver_checks;
