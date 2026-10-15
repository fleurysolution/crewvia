-- Rollback for P5-M47 (candidates' emails in test mode; the reply address).
--
-- READ BEFORE RUNNING
--   * Rolling back removes test mode: the code before P5-M47 sends every queued email to the
--     candidates' real addresses. Empty or hold the queues first (Email delivery), or do not
--     run the delivery scripts, unless the real candidates are meant to receive them.

DELETE FROM platform_settings WHERE setting_key IN ('candidate_mail_test', 'candidate_mail_test_to', 'candidate_mail_reply_to');
