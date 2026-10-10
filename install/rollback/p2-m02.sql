-- Rollback for P2-M02 (performance appraisals).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P2-M02 first.
--   * Lost by this rollback: appraisal templates, every appraisal, its
--     scores and its history. The end-of-assignment reviews on the roster
--     stay, including those an approved appraisal wrote.
--
-- Run:  mysql <database> < install/rollback/p2-m02.sql

DROP TABLE IF EXISTS appraisal_events;
DROP TABLE IF EXISTS appraisal_scores;
DROP TABLE IF EXISTS appraisals;
DROP TABLE IF EXISTS appraisal_template_criteria;
DROP TABLE IF EXISTS appraisal_templates;
