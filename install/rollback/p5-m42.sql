-- Rollback for P5-M42 (applicants added by hand with their documents; job board screening scores).
--
-- READ BEFORE RUNNING
--   * A diploma or certificate is filed as "other" again; the file itself is kept.
--   * Applicants added by hand stay: they are real applications.
--   * The screening scores filled from a job board's answers are cleared, only on
--     applications from Indeed with no answers to Crewvia's own screening questions,
--     so a score Crewvia worked out itself is kept.

UPDATE candidate_resumes SET kind = 'other' WHERE kind = 'certificate';
ALTER TABLE candidate_resumes MODIFY kind ENUM('cv','cover_letter','other') NOT NULL DEFAULT 'cv';
UPDATE applications a SET a.screening_score = NULL
 WHERE a.source = 'Indeed'
   AND NOT EXISTS (SELECT 1 FROM screening_answers s WHERE s.application_id = a.id);
DELETE FROM platform_settings WHERE setting_key = 'board_scores_filled';
