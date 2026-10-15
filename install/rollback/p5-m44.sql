-- Rollback for P5-M44 (the recruiter passes an applicant to a manager; interviews with a scorecard).
--
-- READ BEFORE RUNNING
--   * Every review and every interview recorded since P5-M44 is deleted with its table.
--     What happened stays on each application's history (application_events), written in words
--     at the time: "Passed to ... for review", "Validated by ...", "Interview done: 4/5 ...".
--   * Applications moved to Interview by a scheduled interview stay at Interview.

DROP TABLE IF EXISTS interviews;
DROP TABLE IF EXISTS application_reviews;
