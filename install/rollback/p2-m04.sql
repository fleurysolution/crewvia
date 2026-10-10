-- Rollback for P2-M04 (benefit plans, eligibility, enrollment).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P2-M04 first.
--   * Lost by this rollback: every plan, coverage level and enrollment or
--     waiver. The deductions and employer contributions enrollments put on
--     people stay, as ordinary pay items, and keep being taken until they
--     are ended on the employee folder. Weeks already paid are untouched.
--
-- Run:  mysql <database> < install/rollback/p2-m04.sql

ALTER TABLE employee_pay_items DROP FOREIGN KEY fk_pay_item_enrollment, DROP COLUMN benefit_enrollment_id;
DROP TABLE IF EXISTS benefit_enrollments;
DROP TABLE IF EXISTS benefit_plan_tiers;
DROP TABLE IF EXISTS benefit_plans;
