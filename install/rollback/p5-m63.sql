-- Rollback for P5-M63 (bank details come with a proof that payroll compares before accepting them).
--
-- READ BEFORE RUNNING
--   * The proofs stay on disk, encrypted, in storage/bank-proofs; without these columns nothing points to
--     them any more. Delete that folder by hand once nothing needs them.
--   * The earlier code accepts bank details without a proof and without the "compared" box.

ALTER TABLE worker_bank_change_requests DROP COLUMN proof_storage_name, DROP COLUMN proof_mime, DROP COLUMN proof_hash;
