-- Rollback for P3-M05 statements (budgets; the statements themselves read
-- the ledger and leave nothing behind).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes the P3-M05 statements first.
--   * Lost by this rollback: every budget and the history of its changes.
--     The ledger and the statements it gives are untouched.
--
-- Run:  mysql <database> < install/rollback/p3-m05-statements.sql

DROP TABLE IF EXISTS gl_budget_events;
DROP TABLE IF EXISTS gl_budgets;
