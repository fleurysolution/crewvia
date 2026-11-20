-- Rollback for P5-M114 (renewal terms, consent record, invoices, cancel at period end).
-- WARNING: saas_renewal_consents holds consent records kept for 3 years; export it before dropping it.
DROP TABLE IF EXISTS saas_invoices;
DROP TABLE IF EXISTS saas_renewal_consents;
ALTER TABLE saas_subscription DROP COLUMN cancel_at_period_end, DROP COLUMN price_id, DROP COLUMN price_unit_amount, DROP COLUMN price_currency,
  DROP COLUMN price_interval, DROP COLUMN price_checked_at, DROP COLUMN reminder_sent_for;
