-- Rollback for HR self-service.
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes self-service first.
--   * Lost by this rollback: bank details and detail changes workers
--     proposed and that are still waiting or were decided, and the
--     "nothing has changed" confirmations. Bank details already approved
--     are in worker_bank_details and stay; detail changes already
--     approved are already on the person's record and stay.
--
-- Run:  mysql <database> < install/rollback/self-service.sql

DROP TABLE IF EXISTS details_confirmations;
DROP TABLE IF EXISTS profile_change_requests;
DROP TABLE IF EXISTS worker_bank_change_requests;
