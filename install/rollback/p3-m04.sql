-- Rollback for P3-M04 (receivables and payables: payments, applications,
-- credit notes, aging).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P3-M04 first.
--   * Lost by this rollback: every recorded payment, its applications and
--     every credit note, client payment terms and client invoice due dates.
--     Invoice statuses (issued, paid) stay as they are. Entries already
--     exported to QuickBooks stay there.
--
-- Run:  mysql <database> < install/rollback/p3-m04.sql

DROP TABLE IF EXISTS ar_allocations;
DROP TABLE IF EXISTS ar_credits;
DROP TABLE IF EXISTS ar_payments;
DROP TABLE IF EXISTS ap_allocations;
DROP TABLE IF EXISTS ap_credits;
DROP TABLE IF EXISTS ap_payments;
ALTER TABLE client_invoices DROP COLUMN due_on;
ALTER TABLE clients DROP COLUMN payment_terms_days;
