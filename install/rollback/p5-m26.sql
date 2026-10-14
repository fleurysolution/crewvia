-- Rollback for P5-M26 (walkthrough fixes: the candidate stage sweep).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M26 first.
--   * Puts back every candidate stage the one-time sweep moved forward, as it
--     was before the sweep. Stages changed by people since are kept only where
--     the sweep did not touch that candidate.
--
-- Run:  mysql <database> < install/rollback/p5-m26.sql

UPDATE candidates c JOIN candidate_stage_sweep s ON s.candidate_id = c.id
   SET c.stage = s.old_stage
 WHERE c.stage = s.new_stage;
DELETE FROM platform_settings WHERE setting_key = 'candidate_stage_swept';
DROP TABLE IF EXISTS candidate_stage_sweep;
