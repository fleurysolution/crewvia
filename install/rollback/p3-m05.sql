-- Rollback for P3-M05 (financial periods and the trial balance of what was
-- exported).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P3-M05 first.
--   * Lost by this rollback: which months are closed, and the history of
--     every close and reopen. Every month is open again. No invoice,
--     payment, claim or export is touched.
--
-- Run:  mysql <database> < install/rollback/p3-m05.sql

DROP TABLE IF EXISTS financial_period_events;
DROP TABLE IF EXISTS financial_periods;
