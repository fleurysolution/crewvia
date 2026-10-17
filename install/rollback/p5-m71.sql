-- Rollback for P5-M71 (client contacts, the site address). The main contact stays on each client (contact_name, contact_email, contact_phone).
DROP TABLE job_contacts;
DROP TABLE client_contacts;
ALTER TABLE jobs DROP COLUMN site_address;
