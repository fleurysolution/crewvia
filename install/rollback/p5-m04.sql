-- Rollback for P5-M04 (training courses, assessments, plans, requirements,
-- assignments and attempts).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M04 first.
--   * Lost by this rollback: every course, question, plan, requirement,
--     assignment and attempt. Qualifications a passed course awarded stay
--     in candidate_qualifications, with their evidence reference naming
--     the course.
--
-- Run:  mysql <database> < install/rollback/p5-m04.sql

DROP TABLE IF EXISTS training_attempts;
DROP TABLE IF EXISTS training_assignments;
DROP TABLE IF EXISTS training_requirements;
DROP TABLE IF EXISTS training_plan_courses;
DROP TABLE IF EXISTS training_plans;
DROP TABLE IF EXISTS training_questions;
DROP TABLE IF EXISTS training_courses;
