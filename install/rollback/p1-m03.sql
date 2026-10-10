-- Rollback for P1-M03 (pay rules).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P1-M03 first: its pages read every
--     object dropped here.
--   * Lost by this rollback: every rule set, its holidays and shift
--     premiums, and which project used which. Weeks already approved keep
--     the figures frozen in pay_snapshots; nothing there is touched.
--
-- Run:  mysql <database> < install/rollback/p1-m03.sql
-- Re-applying afterwards is safe: php install/upgrade.php recreates it.

ALTER TABLE jobs DROP INDEX ix_job_pay_rule_set, DROP COLUMN pay_rule_set_id;

DROP TABLE IF EXISTS pay_rule_shift_premiums;
DROP TABLE IF EXISTS pay_rule_holidays;
DROP TABLE IF EXISTS pay_rule_sets;
