-- Rollback for P2-M07 (HR requests and employment letters; the
-- self-service home reads existing tables and needs no rollback).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P2-M07 first.
--   * Lost by this rollback: every HR request, its conversation, and the
--     employment letters issued on them. Letters already printed or sent
--     stay valid on paper; they can no longer be checked against their
--     fingerprint here.
--
-- Run:  mysql <database> < install/rollback/p2-m07.sql

DROP TABLE IF EXISTS hr_request_replies;
DROP TABLE IF EXISTS hr_requests;
