-- Rollback for P1-M01 (classification history, assignment provenance,
-- rate-change history).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the archive that precedes P1-M01 first: the P1-M01 code reads
--     every object dropped here and will fail without them.
--   * Lost by this rollback: the classification history, the overtime
--     status, the rate-change history and the requisition/line link on
--     placements. Nothing that existed before P1-M01 is touched:
--     employee_profiles.employment_type and every placement's rates keep
--     their current values.
--
-- Run:  mysql <database> < install/rollback/p1-m01.sql
-- Re-applying afterwards is safe: php install/upgrade.php recreates
-- everything and backfills again.

DROP TABLE IF EXISTS placement_rate_changes;
DROP TABLE IF EXISTS employee_classifications;

ALTER TABLE placements DROP INDEX ix_placement_order_line, DROP COLUMN order_line_id;
ALTER TABLE placements DROP INDEX ix_placement_vacancy, DROP COLUMN vacancy_id;
ALTER TABLE employee_profiles DROP COLUMN flsa_status;
