-- Rollback for P3-M03 (Crewvia's own general ledger).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P3-M03 first: before it, the QuickBooks
--     export builds its entries from the records directly.
--   * Lost by this rollback: every ledger journal, including the manual
--     journals typed for what Crewvia never sees, and the accounts an
--     administrator added. Exports already made stay as they were, and
--     what was not yet exported is rebuilt from the records, except the
--     manual journals. The budgets (P3-M05) go too: they are set on these accounts.
--
-- Run:  mysql <database> < install/rollback/p3-m03.sql

-- Budgets (P3-M05) are set on these accounts: they go first.
DROP TABLE IF EXISTS gl_budget_events;
DROP TABLE IF EXISTS gl_budgets;
DROP TABLE IF EXISTS gl_lines;
DROP TABLE IF EXISTS gl_journals;
DELETE FROM accounting_accounts WHERE account_key NOT IN ('accounts_receivable','bank','accounts_payable','net_pay_payable','deductions_payable','employer_payable','revenue','wages_expense','employer_expense','per_diem_expense','hotels_expense','transportation_expense','equipment_expense','other_expense');
ALTER TABLE accounting_accounts MODIFY side ENUM('asset','liability','income','expense') NOT NULL;
ALTER TABLE accounting_accounts DROP COLUMN number, DROP COLUMN is_system, DROP COLUMN is_active;
