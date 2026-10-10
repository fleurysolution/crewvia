-- Rollback for procurement (R41-R47).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes procurement first.
--   * Lost by this rollback: every request, quotation, purchase order and
--     receipt, the units of measure, each project's budget owner and
--     automatic-lodging setting, and which vendor invoice bills which
--     order. Rooms already added to a hotel's block stay in the block.
--     Vendor invoices themselves are kept.
--
-- Run:  mysql <database> < install/rollback/procurement.sql

ALTER TABLE vendor_invoices DROP FOREIGN KEY fk_invoice_po, DROP COLUMN purchase_order_id;
ALTER TABLE jobs DROP COLUMN budget_owner_id, DROP COLUMN auto_lodging;

-- P3-M06 and P3-M07 build on the requests and orders: their tables go with them.
DROP TABLE IF EXISTS purchase_order_allocations;
DROP TABLE IF EXISTS purchase_order_revisions;
DROP TABLE IF EXISTS purchase_rfqs;
DROP TABLE IF EXISTS purchase_order_approvals;
DROP TABLE IF EXISTS purchase_receipts;
DROP TABLE IF EXISTS purchase_orders;
DROP TABLE IF EXISTS purchase_quotations;
DROP TABLE IF EXISTS purchase_requests;
DROP TABLE IF EXISTS procurement_units;
