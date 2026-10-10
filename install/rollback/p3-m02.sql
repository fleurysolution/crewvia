-- Rollback for P3-M02 (chart-of-accounts mapping, QuickBooks export).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P3-M02 first.
--   * Lost by this rollback: the account mapping, every export batch and
--     its lines, and the record of which invoices, claims and payroll
--     periods were exported. Anything already imported into QuickBooks
--     stays there: re-exporting after a rollback would send it again.
--   * Also lost: the issue and payment dates on client invoices.
--
-- Run:  mysql <database> < install/rollback/p3-m02.sql

DROP TABLE IF EXISTS accounting_sources;
DROP TABLE IF EXISTS accounting_lines;
DROP TABLE IF EXISTS accounting_batches;
DROP TABLE IF EXISTS accounting_accounts;
ALTER TABLE client_invoices DROP COLUMN issued_at, DROP COLUMN paid_at;
