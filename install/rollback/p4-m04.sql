-- Rollback for P4-M04 (integration audit, provider tokens, one-time keys,
-- Gmail connection state, DocuSign Connect retries).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P4-M04 first.
--   * Lost by this rollback: the integration audit trail, any cached
--     DocuSign token (a new one is requested), the one-time form keys, the
--     record of which Gmail connections need reconnecting, and the retry
--     state of DocuSign Connect events. Gmail connections and Connect
--     events themselves are kept.
--
-- Run:  mysql <database> < install/rollback/p4-m04.sql

DROP TABLE IF EXISTS integration_nonces;
DROP TABLE IF EXISTS integration_tokens;
DROP TABLE IF EXISTS integration_events;
ALTER TABLE gmail_connections DROP COLUMN status, DROP COLUMN scopes, DROP COLUMN last_refreshed_at, DROP COLUMN last_error;
UPDATE docusign_connect_events SET status = 'pending' WHERE status = 'failed';
ALTER TABLE docusign_connect_events DROP COLUMN next_attempt_at, DROP COLUMN last_error, DROP COLUMN processed_at;
