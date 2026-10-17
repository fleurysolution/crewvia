-- Rollback for P5-M70 (billing terms). Invoices already made keep their lines; new ones bill hours at the bill rate.
ALTER TABLE project_shifts DROP COLUMN bill_premium;
DROP TABLE job_billing_terms;
