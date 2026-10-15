-- Rollback for P5-M43 (Crewvia's own scoring: graded answers, a job board's answers scored by our rules).
--
-- READ BEFORE RUNNING
--   * The answers recorded from a job board are removed; the board's answers stay in the
--     application's note ("From the application"), as before P5-M43.
--   * The questions created from a job board's wording are removed with them. Questions the
--     office wrote stay, with their weight and knockout; their graded rules go.
--   * Scores are not recalculated here: run install/upgrade.php after the rollback, or change a
--     question on Screening questions, to re-rank.

DELETE FROM screening_answers WHERE from_board = 1;
DELETE FROM screening_questions WHERE origin = 'board' AND NOT EXISTS (SELECT 1 FROM screening_answers s WHERE s.question_id = screening_questions.id);
ALTER TABLE screening_answers DROP COLUMN from_board;
ALTER TABLE screening_questions DROP COLUMN full_marks_at, DROP COLUMN choice_points, DROP COLUMN board_question, DROP COLUMN origin;
DELETE FROM platform_settings WHERE setting_key = 'board_answers_recorded';
