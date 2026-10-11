-- Rollback for P4-M05 (connector modes, payroll export record, ADP
-- reconciliation).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P4-M05 first: before it, the ADP file
--     and the partner intake have no sandbox, and every configured partner
--     channel is live.
--   * Lost by this rollback: the connector modes and who authorized them,
--     the record of every payroll file produced (with the files), ADP's
--     import confirmations, the reconciliations, and the ADP and applicant
--     tracking entries of the integration audit trail.
--
-- Run:  mysql <database> < install/rollback/p4-m05.sql

DROP TABLE IF EXISTS adp_reconciliations;
DROP TABLE IF EXISTS payroll_exports;
DROP TABLE IF EXISTS connector_modes;
DELETE FROM integration_events WHERE provider IN ('adp','ats');
ALTER TABLE integration_events MODIFY provider ENUM('gmail','docusign','stripe') NOT NULL;
