-- Rollback for P5-M100 (verifiable billing). Drops the ledger and its closes: keep a copy first.
DROP TRIGGER IF EXISTS billing_events_no_update;
DROP TRIGGER IF EXISTS billing_events_no_delete;
DROP TRIGGER IF EXISTS billing_closings_no_update;
DROP TRIGGER IF EXISTS billing_closings_no_delete;
DROP TABLE billing_closings;
DROP TABLE billing_events;
