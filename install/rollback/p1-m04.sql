-- Rollback for P1-M04 (leave accrual, carryover, eligibility, paid leave).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P1-M04 first: its pages read every
--     column dropped here.
--   * Lost by this rollback: each leave type's accrual, carryover and
--     eligibility settings, and the paid leave hours on weekly sheets not
--     yet approved. Weeks already approved keep their figures in
--     pay_snapshots. Leave types and requests themselves are untouched.
--
-- Run:  mysql <database> < install/rollback/p1-m04.sql
-- Re-applying afterwards is safe: php install/upgrade.php adds them again.

ALTER TABLE timesheets DROP COLUMN paid_leave_hours;

ALTER TABLE leave_types
  DROP COLUMN hours_per_day,
  DROP COLUMN eligible_employment_types,
  DROP COLUMN eligible_after_days,
  DROP COLUMN carryover_max_days,
  DROP COLUMN accrual_cap_days,
  DROP COLUMN accrual_hours_per_day,
  DROP COLUMN accrual_method;
