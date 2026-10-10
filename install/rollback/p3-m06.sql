-- Rollback for P3-M06 (approved vendors, approval thresholds, project
-- purchasing allocations).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P3-M06 first.
--   * Lost by this rollback: the vendor list and its history, the
--     thresholds, each order's recorded approvals and its split across
--     projects. Orders keep their status, vendor name and total, and each
--     counts again wholly on its own project.
--
-- Run:  mysql <database> < install/rollback/p3-m06.sql

DROP TABLE IF EXISTS purchase_order_allocations;
DROP TABLE IF EXISTS purchase_order_approvals;
DROP TABLE IF EXISTS procurement_thresholds;
DROP TABLE IF EXISTS vendor_events;
DROP TABLE IF EXISTS vendors;
ALTER TABLE purchase_orders DROP COLUMN approvers, DROP COLUMN over_budget;
