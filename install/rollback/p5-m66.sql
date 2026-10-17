-- Rollback for P5-M66 (the office is told when a person is cleared for deployment).
ALTER TABLE placements DROP COLUMN ready_notified_at;
