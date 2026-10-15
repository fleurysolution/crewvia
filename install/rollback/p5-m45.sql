-- Rollback for P5-M45 (a candidate's availability; a portal offered right after applying).
--
-- READ BEFORE RUNNING
--   * What candidates said about their availability is dropped with the columns; each change
--     stays in their record's history (candidate_events, "availability").
--   * Portals already created stay; unused links offered after an application simply expire.

ALTER TABLE candidates DROP COLUMN availability, DROP COLUMN available_from, DROP COLUMN availability_at;
