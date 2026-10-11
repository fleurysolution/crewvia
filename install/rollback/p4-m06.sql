-- Rollback for P4-M06 (privacy notices, consent records, audit-log address
-- and append-only triggers).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P4-M06 first.
--   * Lost by this rollback: the published privacy notices and every
--     consent recorded against them, and the sender address of the audit
--     log entries. The audit log can then be changed again by anyone with
--     database access.
--
-- Run:  mysql <database> < install/rollback/p4-m06.sql

DROP TRIGGER IF EXISTS activity_no_update;
DROP TRIGGER IF EXISTS activity_no_delete;
DROP TABLE IF EXISTS consent_records;
DROP TABLE IF EXISTS privacy_notices;
ALTER TABLE activity DROP COLUMN ip;
