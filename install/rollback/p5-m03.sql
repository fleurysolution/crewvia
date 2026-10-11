-- Rollback for P5-M03 (asset inspections, maintenance plans, service
-- records, classification, lifecycle and valuation fields).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M03 first.
--   * Lost by this rollback: inspection records (the history keeps an
--     "Inspected" line for each), maintenance plans, service records,
--     category classes and valuation defaults, in-service, retirement and
--     disposal dates, disposal methods and proceeds, value overrides.
--   * A disposed item becomes retired again (the earlier status list has
--     no "disposed").
--
-- Run:  mysql <database> < install/rollback/p5-m03.sql

DROP TABLE IF EXISTS asset_service_records;
DROP TABLE IF EXISTS asset_maintenance_plans;
DROP TABLE IF EXISTS asset_inspections;
UPDATE equipment SET status = 'retired' WHERE status = 'disposed';
ALTER TABLE equipment MODIFY status ENUM('available','in_repair','lost','retired') NOT NULL DEFAULT 'available';
ALTER TABLE equipment DROP COLUMN in_service_on, DROP COLUMN useful_life_months, DROP COLUMN residual_value,
  DROP COLUMN retired_on, DROP COLUMN disposed_on, DROP COLUMN disposal_method, DROP COLUMN disposal_proceeds;
ALTER TABLE asset_categories DROP COLUMN asset_class, DROP COLUMN useful_life_months, DROP COLUMN residual_percent;
