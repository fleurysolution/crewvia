-- Rollback for P1-M06 (pay periods, adjustments, payroll audit).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P1-M06 first.
--   * Lost by this rollback: every pay period, its approvals and locks,
--     every adjustment, and the payroll audit trail. Weekly sheets and
--     their frozen figures in pay_snapshots are not touched.
--
-- Run:  mysql <database> < install/rollback/p1-m06.sql

DROP TABLE IF EXISTS payroll_run_events;
DROP TABLE IF EXISTS payroll_adjustments;
DROP TABLE IF EXISTS payroll_runs;
