-- Rollback for P5-M64 (the W-4 and Section 1 of the I-9 filled in the portal).
--
-- READ BEFORE RUNNING
--   * worker_tax_forms holds signed W-4s and I-9s, encrypted. Dropping it destroys them: an I-9 must be
--     kept three years after hire or one year after employment ends, whichever is later. Export or print
--     every form first (Change requests, or /tax-forms?id=N), and keep them with the employment records.
--   * The earlier code does not know the forms: the onboarding tasks go back to a completion note.

ALTER TABLE employment_clearance DROP COLUMN w4_form_id, DROP COLUMN i9_form_id;
ALTER TABLE onboarding_requirements DROP COLUMN form_kind;
DROP TABLE worker_tax_form_access;
DROP TABLE worker_tax_forms;
-- P5-M64b: the consignes it rewrote stay (the earlier ones are in onboarding_requirement_events); its marker goes.
DELETE FROM platform_settings WHERE setting_key = 'p5_m64b_form_consignes';
