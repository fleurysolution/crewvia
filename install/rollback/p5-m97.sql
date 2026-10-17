-- Rollback for P5-M97/M98 (send home; the airport given on applying). Separations, tasks and travel legs stay.
ALTER TABLE candidates DROP COLUMN home_airport;
