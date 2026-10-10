-- Rollback for P2-M05 (loans, schedules, approval controls, pauses,
-- write-offs).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P2-M05 first.
--   * Lost by this rollback: whether each was a loan or an advance, the
--     week repayment starts, every pause and its reason, the write-offs and
--     their reasons, the approval limit, and the history of each advance.
--     A written-off advance becomes cleared, so payroll does not start
--     taking it again. Repayments already taken stay.
--
-- Run:  mysql <database> < install/rollback/p2-m05.sql

UPDATE wage_advances SET status = 'cleared' WHERE status = 'written_off';
ALTER TABLE wage_advances MODIFY status ENUM('requested','approved','paid_out','cleared','cancelled') NOT NULL DEFAULT 'requested';
ALTER TABLE wage_advances DROP COLUMN kind, DROP COLUMN first_week, DROP COLUMN written_off_amount, DROP COLUMN written_off_reason,
  DROP COLUMN written_off_by, DROP COLUMN written_off_at;
DROP TABLE IF EXISTS advance_pauses;
DROP TABLE IF EXISTS advance_events;
DELETE FROM platform_settings WHERE setting_key = 'advance_admin_above';
