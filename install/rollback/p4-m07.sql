-- Rollback for P4-M07 (operations: the record of backups, restore drills
-- and scheduled job runs).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P4-M07 first.
--   * Lost by this rollback: the history of backups, drills and job runs.
--     The backups themselves are files and are not touched. The error logs
--     in storage/logs are files and are not touched.
--
-- Run:  mysql <database> < install/rollback/p4-m07.sql

DROP TABLE IF EXISTS ops_runs;
