-- P5-M103 rollback: requested requisitions (run only after taking a backup).
ALTER TABLE vacancies DROP COLUMN posting_status, DROP COLUMN requested_by, DROP COLUMN requested_at, DROP COLUMN published_by, DROP COLUMN published_at;
