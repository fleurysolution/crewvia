-- Rollback for P5-M65 (forms signed in person; copies of the I-9 documents; E-Verify).
--
-- READ BEFORE RUNNING
--   * Drafts (status 'draft') are not signed forms: delete them first, or the status change fails.
--   * The copies stay in worker_documents (encrypted, restricted); only their link to the I-9 goes.
--   * The drawn signatures are lost: the official PDFs no longer show them.

DELETE FROM worker_tax_form_access WHERE form_id IN (SELECT id FROM worker_tax_forms WHERE status = 'draft');
DELETE FROM worker_tax_forms WHERE status = 'draft';
ALTER TABLE worker_tax_forms MODIFY status ENUM('submitted','accepted','returned','replaced') NOT NULL DEFAULT 'submitted',
  DROP COLUMN witness_id, DROP COLUMN encrypted_signature, DROP COLUMN everify_case, DROP COLUMN everify_result, DROP COLUMN everify_by, DROP COLUMN everify_at;
DROP TABLE i9_document_copies;
DELETE FROM platform_settings WHERE setting_key = 'everify_enabled';
