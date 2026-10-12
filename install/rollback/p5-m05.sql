-- Rollback for P5-M05 (peer thanks, award programmes and nominations,
-- points and rewards, feedback, engagement surveys).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M05 first.
--   * Lost by this rollback: every thank-you, programme, nomination, point,
--     reward and redemption, every piece of feedback and request, and every
--     survey with its answers.
--   * Kept: awards already written into recognitions (HR records), and
--     cash rewards already paid. Those stay in payroll_adjustments as a
--     "correction" whose reason starts with "Reward:", because the kind
--     "reward" goes away with this module.
--   * Time-off rewards stop adding days to leave allowances.
--
-- Run:  mysql <database> < install/rollback/p5-m05.sql

UPDATE payroll_adjustments SET kind = 'correction', reason = LEFT(CONCAT('Reward: ', reason), 500) WHERE kind = 'reward';
ALTER TABLE payroll_adjustments MODIFY kind ENUM('correction','back_pay','reimbursement','recovery') NOT NULL;

DROP TABLE IF EXISTS survey_answers;
DROP TABLE IF EXISTS survey_responses;
DROP TABLE IF EXISTS survey_invitations;
DROP TABLE IF EXISTS survey_questions;
DROP TABLE IF EXISTS surveys;
DROP TABLE IF EXISTS feedback_entries;
DROP TABLE IF EXISTS feedback_request_people;
DROP TABLE IF EXISTS feedback_requests;
DROP TABLE IF EXISTS reward_redemptions;
DROP TABLE IF EXISTS reward_points;
DROP TABLE IF EXISTS award_nominations;
DROP TABLE IF EXISTS award_programs;
DROP TABLE IF EXISTS reward_catalog;
DROP TABLE IF EXISTS kudos;
