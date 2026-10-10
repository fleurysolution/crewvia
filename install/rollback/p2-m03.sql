-- Rollback for P2-M03 (evaluation cycles, goals and their progress,
-- development plans and follow-up actions).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P2-M03 first.
--   * Lost by this rollback: every cycle, goal, progress update and
--     development action. The reviews a cycle opened stay, without their
--     cycle.
--
-- Run:  mysql <database> < install/rollback/p2-m03.sql

DROP TABLE IF EXISTS goal_updates;
DROP TABLE IF EXISTS performance_goals;
DROP TABLE IF EXISTS development_actions;
ALTER TABLE appraisals DROP FOREIGN KEY fk_appraisal_cycle, DROP COLUMN cycle_id;
DROP TABLE IF EXISTS appraisal_cycles;
