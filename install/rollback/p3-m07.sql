-- Rollback for P3-M07 (requests for quotation, order revisions and their
-- authorization).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P3-M07 first.
--   * Lost by this rollback: every request for quotation, the link from a
--     quotation to its request and its validity, every order's revision
--     history and number, and the approvals given to earlier revisions.
--     Orders keep their current quantity, price, total and status.
--
-- Run:  mysql <database> < install/rollback/p3-m07.sql

DROP TABLE IF EXISTS purchase_order_revisions;
DELETE a FROM purchase_order_approvals a JOIN purchase_orders o ON o.id = a.purchase_order_id WHERE a.revision <> o.revision;
ALTER TABLE purchase_order_approvals DROP INDEX uq_po_approval_revision, DROP COLUMN revision, ADD UNIQUE KEY uq_po_approval (purchase_order_id, approver);
ALTER TABLE purchase_orders DROP COLUMN revision, DROP COLUMN quotation_id;
ALTER TABLE purchase_quotations DROP COLUMN rfq_id, DROP COLUMN valid_until;
DROP TABLE IF EXISTS purchase_rfqs;
