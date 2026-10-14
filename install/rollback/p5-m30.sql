-- Rollback for P5-M30 (the worker's travel details, the itinerary, advice for travellers, email to workers).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M30 first.
--   * Lost by this rollback: the travel details workers entered, the advice
--     for travellers, the tickets pasted on bookings, and any email to
--     workers not yet sent. Bookings themselves (travel) stay.
--
-- Run:  mysql <database> < install/rollback/p5-m30.sql

DROP TABLE IF EXISTS mail_outbox;
DROP TABLE IF EXISTS travel_advisories;
DROP TABLE IF EXISTS travel_profiles;
ALTER TABLE travel DROP COLUMN ticket_details;
