-- Rollback for P5-M76 (the hotels of each project). Bookings keep their hotel and their rate.
UPDATE job_billing_terms SET lodging_mode = 'cost' WHERE lodging_mode = 'hotel_rate';
ALTER TABLE job_billing_terms MODIFY lodging_mode ENUM('none','cost','cost_plus','flat_night') NOT NULL DEFAULT 'none';
DROP TABLE job_hotels;
