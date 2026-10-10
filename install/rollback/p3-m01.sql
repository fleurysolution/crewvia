-- Rollback for P3-M01 (project costing).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P3-M01 first.
--   * Lost by this rollback: every project budget and its history, and each
--     project's burden and overhead rates. No cost, invoice, sheet or claim
--     is touched: the report only reads them.
--
-- Run:  mysql <database> < install/rollback/p3-m01.sql

DROP TABLE IF EXISTS project_budget_changes;
ALTER TABLE jobs DROP COLUMN burden_percent, DROP COLUMN overhead_percent;
