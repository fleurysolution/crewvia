-- Rollback for P5-M84 (HR coordinator, groups).
ALTER TABLE assignment_details DROP COLUMN crew_group, DROP COLUMN leads_group;
ALTER TABLE jobs DROP COLUMN coordinator_id;
UPDATE users SET role = 'recruiter' WHERE role = 'hr';
ALTER TABLE users MODIFY role ENUM('admin','recruiter','hotels','payroll','worker','supervisor','client') NOT NULL DEFAULT 'recruiter';
