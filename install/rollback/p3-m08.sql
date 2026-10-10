-- Rollback for P3-M08 (receiving with rejections, three-way matching,
-- purchasing exceptions, payment readiness).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P3-M08 first.
--   * Lost by this rollback: what was rejected on receipt and why, the
--     quantity billed on each bill, and every cleared exception. Bills are
--     then paid on approval alone, as before.
--
-- Run:  mysql <database> < install/rollback/p3-m08.sql

DROP TABLE IF EXISTS bill_match_clearances;
ALTER TABLE purchase_receipts DROP COLUMN rejected_quantity, DROP COLUMN rejection_reason;
ALTER TABLE vendor_invoices DROP COLUMN quantity;
DELETE FROM platform_settings WHERE setting_key IN ('match_tolerance_percent', 'match_tolerance_amount');
