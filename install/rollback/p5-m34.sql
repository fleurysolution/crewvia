-- Rollback for P5-M34 (absences and the guarantee, guaranteed overtime, taxable per diem).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M34 first.
--   * Lost by this rollback: each project's absence rule and guaranteed
--     overtime choice, and which workers' per diem is taxable. Weeks already
--     approved keep the amounts frozen in their pay snapshots.
--
-- Run:  mysql <database> < install/rollback/p5-m34.sql

ALTER TABLE project_pay_policies DROP COLUMN guarantee_absence_rule, DROP COLUMN guarantee_day_hours, DROP COLUMN guarantee_overtime;
ALTER TABLE placements DROP COLUMN per_diem_taxable;
DELETE FROM platform_settings WHERE setting_key = 'adp_perdiem_taxable_code';
