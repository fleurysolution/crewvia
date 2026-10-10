-- Rollback for assets.
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes assets first.
--   * Lost by this rollback: categories, each item's status, purchase
--     details, inspection date and notes, the condition recorded at issue
--     and return, and every item's history. The items themselves, and who
--     holds them, stay in equipment and equipment_issues.
--
-- Run:  mysql <database> < install/rollback/assets.sql

ALTER TABLE equipment_issues DROP COLUMN issue_condition, DROP COLUMN return_condition, DROP COLUMN issued_by, DROP COLUMN returned_by;
ALTER TABLE equipment DROP COLUMN category_id, DROP COLUMN status, DROP COLUMN purchase_date, DROP COLUMN purchase_cost,
  DROP COLUMN purchase_order_id, DROP COLUMN inspection_due, DROP COLUMN notes;

DROP TABLE IF EXISTS asset_events;
DROP TABLE IF EXISTS asset_categories;
