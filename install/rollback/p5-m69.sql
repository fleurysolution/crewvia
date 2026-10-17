-- Rollback for P5-M69 (the Clients page). The clients and their names stay.
ALTER TABLE clients DROP COLUMN address, DROP COLUMN city, DROP COLUMN state, DROP COLUMN zip, DROP COLUMN billing_email, DROP COLUMN notes, DROP COLUMN created_by;
