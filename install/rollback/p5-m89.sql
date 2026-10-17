-- Rollback for P5-M89 (the onboarding congratulations).
ALTER TABLE placements DROP COLUMN welcome_sent_at;
