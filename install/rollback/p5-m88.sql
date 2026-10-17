-- Rollback for P5-M88 (hiring managers marked on Team).
ALTER TABLE users DROP COLUMN hiring_manager;
