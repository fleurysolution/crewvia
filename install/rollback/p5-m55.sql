-- Rollback for P5-M55 (an onboarding requirement asks the new hires; the crew on site only when ticked).
--
-- READ BEFORE RUNNING
--   * The earlier code gives every requirement to every assignment of the project, the crew on site
--     included: the onboarding page recreates their tasks on its next load ("Waiting for the worker").
--   * Which requirements were meant for the crew on site too is dropped with the column.
--   * Requirements removed with "Remove this requirement" are not restored.

ALTER TABLE onboarding_requirements DROP COLUMN for_crew;
