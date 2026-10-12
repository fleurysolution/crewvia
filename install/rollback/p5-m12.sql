-- Rollback for P5-M12 (T8: state, city and distance filters).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M12 first.
--   * Lost by this rollback: the ZIP codes typed on candidates and project
--     sites, and the coordinates worked out from them. The gazetteer
--     tables are reloaded from install/gazetteer by the next upgrade.
--
-- Run:  mysql <database> < install/rollback/p5-m12.sql

ALTER TABLE candidates DROP INDEX idx_candidates_geo, DROP COLUMN zip, DROP COLUMN geo_lat, DROP COLUMN geo_lng, DROP COLUMN geo_from, DROP COLUMN geo_state, DROP COLUMN geo_basis;
ALTER TABLE jobs DROP COLUMN site_zip;
DROP TABLE IF EXISTS geo_places;
DROP TABLE IF EXISTS geo_zips;
