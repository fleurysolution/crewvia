-- Rollback for P2-M01 (grades, pay bands, effective-dated pay changes).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P2-M01 first.
--   * Lost by this rollback: the grades and their bands, each person's
--     grade, and the dated pay changes - including any not yet in effect.
--     Changes already in effect stay where they were applied: the
--     salary on the profile and the rate on the assignment. Weeks already
--     approved keep their frozen figures.
--
-- Run:  mysql <database> < install/rollback/p2-m01.sql

ALTER TABLE employee_profiles DROP COLUMN grade_id;

DROP TABLE IF EXISTS compensation_changes;
DROP TABLE IF EXISTS pay_grades;
