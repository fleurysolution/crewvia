CREATE TABLE IF NOT EXISTS worker_accounts (
 user_id INT UNSIGNED PRIMARY KEY, candidate_id INT UNSIGNED NOT NULL UNIQUE,
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(candidate_id) REFERENCES candidates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS invitations (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, candidate_id INT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, used_at DATETIME NULL,
 FOREIGN KEY(candidate_id) REFERENCES candidates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS onboarding_requirements (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_id INT UNSIGNED NOT NULL,
 title VARCHAR(190) NOT NULL, instructions TEXT NULL, required TINYINT NOT NULL DEFAULT 1,
 FOREIGN KEY(job_id) REFERENCES jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS onboarding_tasks (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, placement_id INT UNSIGNED NOT NULL,
 requirement_id INT UNSIGNED NOT NULL, status ENUM('pending','submitted','approved','rejected') NOT NULL DEFAULT 'pending',
 response TEXT NULL, reviewed_by INT UNSIGNED NULL, reviewed_at DATETIME NULL,
 UNIQUE KEY(placement_id,requirement_id), FOREIGN KEY(placement_id) REFERENCES placements(id),
 FOREIGN KEY(requirement_id) REFERENCES onboarding_requirements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS vacancies (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_id INT UNSIGNED NOT NULL,
 title VARCHAR(190) NOT NULL, description TEXT NOT NULL, is_open TINYINT NOT NULL DEFAULT 1,
 FOREIGN KEY(job_id) REFERENCES jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS applications (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, candidate_id INT UNSIGNED NOT NULL, vacancy_id INT UNSIGNED NOT NULL,
 stage ENUM('new','screening','interview','offered','accepted','rejected','withdrawn') NOT NULL DEFAULT 'new',
 source VARCHAR(120) NOT NULL DEFAULT 'RSS', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(candidate_id) REFERENCES candidates(id), FOREIGN KEY(vacancy_id) REFERENCES vacancies(id), INDEX(vacancy_id,stage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS equipment (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL,
 asset_tag VARCHAR(120) NOT NULL UNIQUE, condition_note VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS equipment_issues (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, equipment_id INT UNSIGNED NOT NULL,
 placement_id INT UNSIGNED NOT NULL, issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 returned_at DATETIME NULL, return_note VARCHAR(255) NULL,
 FOREIGN KEY(equipment_id) REFERENCES equipment(id), FOREIGN KEY(placement_id) REFERENCES placements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS route_passengers (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, run_id INT UNSIGNED NOT NULL,
 placement_id INT UNSIGNED NOT NULL, pickup_stop VARCHAR(190) NOT NULL,
 attendance ENUM('scheduled','boarded','absent') NOT NULL DEFAULT 'scheduled',
 UNIQUE KEY(run_id,placement_id), FOREIGN KEY(run_id) REFERENCES shuttle_runs(id),
 FOREIGN KEY(placement_id) REFERENCES placements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS assignment_details (
 placement_id INT UNSIGNED PRIMARY KEY, trade VARCHAR(190) NOT NULL,
 supervisor_id INT UNSIGNED NULL, shift_label VARCHAR(190) NULL,
 FOREIGN KEY(placement_id) REFERENCES placements(id), FOREIGN KEY(supervisor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS pay_snapshots (
 timesheet_id INT UNSIGNED PRIMARY KEY, result_json TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(timesheet_id) REFERENCES timesheets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS platform_settings (
 setting_key VARCHAR(120) PRIMARY KEY, setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS channels (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_id INT UNSIGNED NOT NULL, name VARCHAR(190) NOT NULL,
 created_by INT UNSIGNED NOT NULL, FOREIGN KEY(job_id) REFERENCES jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS channel_members (
 channel_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
 PRIMARY KEY(channel_id,user_id), FOREIGN KEY(channel_id) REFERENCES channels(id), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS channel_messages (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, channel_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
 message TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(channel_id) REFERENCES channels(id), FOREIGN KEY(user_id) REFERENCES users(id), INDEX(channel_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS notifications (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, message VARCHAR(255) NOT NULL,
 target VARCHAR(255) NOT NULL, read_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id), INDEX(user_id,read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS learning_content (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_id INT UNSIGNED NOT NULL,
 kind ENUM('training','safety','legal') NOT NULL, title VARCHAR(190) NOT NULL,
 content TEXT NOT NULL, resource_url VARCHAR(500) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(job_id) REFERENCES jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS learning_assignments (
 content_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, completed_at DATETIME NULL,
 PRIMARY KEY(content_id,user_id), FOREIGN KEY(content_id) REFERENCES learning_content(id), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS safety_incidents (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_id INT UNSIGNED NOT NULL, reported_by INT UNSIGNED NOT NULL,
 description TEXT NOT NULL, severity ENUM('low','medium','high') NOT NULL,
 status ENUM('open','investigating','resolved') NOT NULL DEFAULT 'open', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(job_id) REFERENCES jobs(id), FOREIGN KEY(reported_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS personnel_changes (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, placement_id INT UNSIGNED NOT NULL,
 kind ENUM('promotion','transfer') NOT NULL, previous_trade VARCHAR(190) NULL, new_trade VARCHAR(190) NULL,
 target_job_id INT UNSIGNED NULL, effective_on DATE NOT NULL, reason TEXT NOT NULL,
 approved_by INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(placement_id) REFERENCES placements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS vehicles (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, plate VARCHAR(60) NOT NULL UNIQUE,
 seats SMALLINT UNSIGNED NOT NULL, status ENUM('available','maintenance','retired') NOT NULL DEFAULT 'available'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS vehicle_assignments (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, vehicle_id INT UNSIGNED NOT NULL, placement_id INT UNSIGNED NOT NULL,
 driver_name VARCHAR(190) NOT NULL, checked_out_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, checked_in_at DATETIME NULL,
 mvr_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending', agreement_signed_at DATETIME NULL,
 FOREIGN KEY(vehicle_id) REFERENCES vehicles(id), FOREIGN KEY(placement_id) REFERENCES placements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS time_off_requests (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, placement_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
 starts_on DATE NOT NULL, ends_on DATE NOT NULL, request_type VARCHAR(120) NOT NULL, reason TEXT NULL,
 status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
 reviewed_by INT UNSIGNED NULL, reviewed_at DATETIME NULL, review_note TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(placement_id) REFERENCES placements(id),
 FOREIGN KEY(user_id) REFERENCES users(id), INDEX(placement_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS signed_acknowledgements (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, placement_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
 title VARCHAR(190) NOT NULL, document_text MEDIUMTEXT NOT NULL, document_hash CHAR(64) NOT NULL,
 signer_name VARCHAR(190) NULL, signed_at DATETIME NULL, status ENUM('pending','signed','declined') NOT NULL DEFAULT 'pending',
 FOREIGN KEY(placement_id) REFERENCES placements(id), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS gmail_connections (
 user_id INT UNSIGNED PRIMARY KEY, email VARCHAR(190) NOT NULL, encrypted_tokens MEDIUMTEXT NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS deployment_waves (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_id INT UNSIGNED NOT NULL, name VARCHAR(190) NOT NULL,
 arrival_date DATE NOT NULL, supervisor_id INT UNSIGNED NULL, headcount_target SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 FOREIGN KEY(job_id) REFERENCES jobs(id), FOREIGN KEY(supervisor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS wave_members (
 wave_id INT UNSIGNED NOT NULL, placement_id INT UNSIGNED NOT NULL,
 PRIMARY KEY(wave_id,placement_id), FOREIGN KEY(wave_id) REFERENCES deployment_waves(id),
 FOREIGN KEY(placement_id) REFERENCES placements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS worker_documents (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, candidate_id INT UNSIGNED NOT NULL,
 uploaded_by INT UNSIGNED NOT NULL, document_type VARCHAR(120) NOT NULL, original_name VARCHAR(190) NOT NULL,
 storage_name CHAR(64) NOT NULL UNIQUE, mime_type VARCHAR(120) NOT NULL, file_hash CHAR(64) NOT NULL,
 status ENUM('submitted','approved','rejected') NOT NULL DEFAULT 'submitted', review_note TEXT NULL,
 reviewed_by INT UNSIGNED NULL, reviewed_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(candidate_id) REFERENCES candidates(id), FOREIGN KEY(uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS screening_checks (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, application_id INT UNSIGNED NOT NULL,
 title VARCHAR(190) NOT NULL, status ENUM('pending','passed','failed') NOT NULL DEFAULT 'pending',
 reviewed_by INT UNSIGNED NULL, reviewed_at DATETIME NULL, note TEXT NULL,
 FOREIGN KEY(application_id) REFERENCES applications(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS application_events (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, application_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
 stage VARCHAR(60) NOT NULL, note TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(application_id) REFERENCES applications(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS employment_clearance (
 candidate_id INT UNSIGNED PRIMARY KEY, first_day DATE NULL, review_due_on DATE NULL,
 i9_status ENUM('pending','employee_submitted','employer_completed') NOT NULL DEFAULT 'pending',
 section1_document_id INT UNSIGNED NULL, section2_document_id INT UNSIGNED NULL,
 verifier_name VARCHAR(190) NULL, verification_method ENUM('in_person','authorized_representative') NULL,
 verified_by INT UNSIGNED NULL, verified_at DATETIME NULL,
 w4_status ENUM('pending','submitted','reviewed') NOT NULL DEFAULT 'pending', w4_document_id INT UNSIGNED NULL,
 FOREIGN KEY(candidate_id) REFERENCES candidates(id),
 FOREIGN KEY(section1_document_id) REFERENCES worker_documents(id),
 FOREIGN KEY(section2_document_id) REFERENCES worker_documents(id), FOREIGN KEY(w4_document_id) REFERENCES worker_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS employee_profiles (
 candidate_id INT UNSIGNED PRIMARY KEY, employee_number VARCHAR(120) NULL UNIQUE,
 employment_type ENUM('hourly','salaried','contractor','external') NOT NULL DEFAULT 'hourly',
 availability ENUM('available','unavailable','on_assignment') NOT NULL DEFAULT 'available',
 rehire_status ENUM('review','eligible','ineligible') NOT NULL DEFAULT 'review',
 payment_method ENUM('direct_deposit','check','cash') NOT NULL DEFAULT 'check',
 adp_employee_id VARCHAR(120) NULL, salary_per_period DECIMAL(12,2) NULL,
 FOREIGN KEY(candidate_id) REFERENCES candidates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS candidate_events (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, candidate_id INT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NULL, event_type VARCHAR(120) NOT NULL, detail TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(candidate_id) REFERENCES candidates(id), INDEX(candidate_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS worker_credentials (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, candidate_id INT UNSIGNED NOT NULL,
 credential_type VARCHAR(120) NOT NULL, description VARCHAR(255) NULL, expires_on DATE NOT NULL,
 document_id INT UNSIGNED NULL, status ENUM('submitted','verified','rejected') NOT NULL DEFAULT 'submitted',
 reviewed_by INT UNSIGNED NULL, reviewed_at DATETIME NULL,
 FOREIGN KEY(candidate_id) REFERENCES candidates(id), FOREIGN KEY(document_id) REFERENCES worker_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS screening_cases (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, placement_id INT UNSIGNED NOT NULL,
 kind ENUM('background','drug') NOT NULL, provider_name VARCHAR(190) NULL,
 delivery_mode ENUM('agency','mobile_bus','onsite') NOT NULL DEFAULT 'agency',
 location VARCHAR(255) NULL, scheduled_at DATETIME NULL, instructions TEXT NULL,
 authorization_id INT UNSIGNED NULL,
 status ENUM('pending','scheduled','completed','cleared','not_cleared','cancelled') NOT NULL DEFAULT 'pending',
 payer ENUM('agency','client','worker_reimbursement') NOT NULL DEFAULT 'agency',
 reviewed_by INT UNSIGNED NULL, reviewed_at DATETIME NULL,
 FOREIGN KEY(placement_id) REFERENCES placements(id), FOREIGN KEY(authorization_id) REFERENCES signed_acknowledgements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS expense_claims (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, placement_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
 category ENUM('flight','hotel','screening','transport','other') NOT NULL, amount DECIMAL(12,2) NOT NULL,
 receipt_document_id INT UNSIGNED NOT NULL, note TEXT NULL,
 status ENUM('submitted','approved','rejected','paid') NOT NULL DEFAULT 'submitted',
 payer ENUM('agency','client') NOT NULL DEFAULT 'agency', reviewed_by INT UNSIGNED NULL, reviewed_at DATETIME NULL,
 payment_method ENUM('direct_deposit','check','cash') NULL, payment_reference VARCHAR(190) NULL,
 paid_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(placement_id) REFERENCES placements(id), FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(receipt_document_id) REFERENCES worker_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS vendor_invoices (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_id INT UNSIGNED NOT NULL, hotel_id INT UNSIGNED NULL,
 vendor_name VARCHAR(190) NOT NULL, reference VARCHAR(190) NOT NULL, amount DECIMAL(12,2) NOT NULL,
 due_on DATE NOT NULL, status ENUM('received','approved','paid') NOT NULL DEFAULT 'received',
 payment_method ENUM('transfer','check','cash','card') NULL, payment_reference VARCHAR(190) NULL, paid_at DATETIME NULL,
 UNIQUE KEY(job_id,vendor_name,reference), FOREIGN KEY(job_id) REFERENCES jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS payroll_payments (
 timesheet_id INT UNSIGNED PRIMARY KEY, method ENUM('direct_deposit','check','cash') NOT NULL,
 reference VARCHAR(190) NOT NULL, paid_by INT UNSIGNED NOT NULL, paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(timesheet_id) REFERENCES timesheets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS client_invoices (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_id INT UNSIGNED NOT NULL, reference VARCHAR(190) NOT NULL UNIQUE,
 starts_on DATE NOT NULL, ends_on DATE NOT NULL, total DECIMAL(14,2) NOT NULL, details_json MEDIUMTEXT NOT NULL,
 status ENUM('draft','issued','paid','void') NOT NULL DEFAULT 'draft', created_by INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(job_id) REFERENCES jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS attendance_records (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, placement_id INT UNSIGNED NOT NULL,
 work_date DATE NOT NULL, hours DECIMAL(5,2) NOT NULL, status ENUM('submitted','approved','rejected') NOT NULL DEFAULT 'submitted',
 submitted_by INT UNSIGNED NOT NULL, reviewed_by INT UNSIGNED NULL, reviewed_at DATETIME NULL,
 UNIQUE KEY(placement_id,work_date), FOREIGN KEY(placement_id) REFERENCES placements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS invoice_timesheets (
 invoice_id INT UNSIGNED NOT NULL, timesheet_id INT UNSIGNED NOT NULL UNIQUE,
 PRIMARY KEY(invoice_id,timesheet_id), FOREIGN KEY(invoice_id) REFERENCES client_invoices(id), FOREIGN KEY(timesheet_id) REFERENCES timesheets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS import_batches (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, filename VARCHAR(190) NOT NULL, file_hash CHAR(64) NOT NULL UNIQUE,
 job_id INT UNSIGNED NULL, imported_by INT UNSIGNED NOT NULL, row_count INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS import_records (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, batch_id INT UNSIGNED NOT NULL, row_number INT UNSIGNED NOT NULL,
 candidate_id INT UNSIGNED NULL, encrypted_source MEDIUMTEXT NOT NULL,
 status ENUM('imported','duplicate_review','invalid') NOT NULL, note VARCHAR(255) NULL,
 FOREIGN KEY(batch_id) REFERENCES import_batches(id), FOREIGN KEY(candidate_id) REFERENCES candidates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS offer_acceptances (
 application_id INT UNSIGNED PRIMARY KEY, user_id INT UNSIGNED NOT NULL, offer_text TEXT NOT NULL,
 offer_hash CHAR(64) NOT NULL, signer_name VARCHAR(190) NOT NULL, signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(application_id) REFERENCES applications(id), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS vehicle_agreements (
 vehicle_assignment_id INT UNSIGNED PRIMARY KEY, acknowledgement_id INT UNSIGNED NOT NULL,
 FOREIGN KEY(vehicle_assignment_id) REFERENCES vehicle_assignments(id), FOREIGN KEY(acknowledgement_id) REFERENCES signed_acknowledgements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
 role_name VARCHAR(60) NOT NULL, desk VARCHAR(60) NOT NULL,
 PRIMARY KEY(role_name,desk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
