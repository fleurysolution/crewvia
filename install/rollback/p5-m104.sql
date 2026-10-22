-- P5-M104 rollback: the applicant emails (run only after taking a backup).
DROP TABLE IF EXISTS candidate_email_log;
DROP TABLE IF EXISTS candidate_email_templates;
DELETE FROM platform_settings WHERE setting_key = 'candidate_email_locale' OR setting_key LIKE 'candidate_email_off_%';
