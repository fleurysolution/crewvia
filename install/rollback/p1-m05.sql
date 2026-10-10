-- Rollback for P1-M05 (deductions, employer contributions, gross to net).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P1-M05 first.
--   * Lost by this rollback: the pay items, each person's deductions and
--     contributions, and which advance repayments were taken from which
--     weekly sheet. The repayments themselves stay in
--     wage_advance_payments, so balances owed do not change. Weeks
--     already approved keep their frozen figures in pay_snapshots.
--
-- Run:  mysql <database> < install/rollback/p1-m05.sql

ALTER TABLE wage_advance_payments DROP INDEX uq_advance_sheet, DROP COLUMN timesheet_id;

DROP TABLE IF EXISTS employee_pay_items;
-- P2-M04's benefit plans own pay items: they go before the pay items.
DROP TABLE IF EXISTS benefit_enrollments;
DROP TABLE IF EXISTS benefit_plan_tiers;
DROP TABLE IF EXISTS benefit_plans;
DROP TABLE IF EXISTS pay_items;
