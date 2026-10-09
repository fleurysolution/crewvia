CREATE TABLE IF NOT EXISTS account_security (
 user_id INT UNSIGNED PRIMARY KEY,
 encrypted_totp TEXT NULL,
 last_totp_step BIGINT NOT NULL DEFAULT -1,
 session_version INT UNSIGNED NOT NULL DEFAULT 0,
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS auth_rate_limits (
 bucket CHAR(64) PRIMARY KEY,
 window_start BIGINT NOT NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 INDEX(window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS account_recovery_codes (
 user_id INT UNSIGNED NOT NULL,
 code_hash CHAR(64) NOT NULL,
 PRIMARY KEY(user_id,code_hash), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS password_resets (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 FOREIGN KEY(user_id) REFERENCES users(id), INDEX(user_id,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS account_mail_outbox (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 reset_id INT UNSIGNED NOT NULL UNIQUE,
 recipient VARCHAR(190) NOT NULL,
 encrypted_message LONGTEXT NOT NULL,
 status ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 lease_until DATETIME NULL,
 sent_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(reset_id) REFERENCES password_resets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS qualification_types (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(190) NOT NULL UNIQUE,
 expires_required TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS candidate_qualifications (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 candidate_id INT UNSIGNED NOT NULL,
 type_id INT UNSIGNED NOT NULL,
 detail VARCHAR(190) NOT NULL DEFAULT '',
 issued_on DATE NULL,
 expires_on DATE NULL,
 evidence_reference VARCHAR(190) NOT NULL,
 status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
 reviewed_by INT UNSIGNED NULL,
 reviewed_at DATETIME NULL,
 UNIQUE(candidate_id,type_id),
 FOREIGN KEY(candidate_id) REFERENCES candidates(id),
 FOREIGN KEY(type_id) REFERENCES qualification_types(id),
 FOREIGN KEY(reviewed_by) REFERENCES users(id), INDEX(status,expires_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS project_qualifications (
 job_id INT UNSIGNED NOT NULL,
 type_id INT UNSIGNED NOT NULL,
 required_detail VARCHAR(190) NOT NULL DEFAULT '',
 PRIMARY KEY(job_id,type_id), FOREIGN KEY(job_id) REFERENCES jobs(id),
 FOREIGN KEY(type_id) REFERENCES qualification_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS qualification_events (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 qualification_id INT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 action VARCHAR(40) NOT NULL,
 note TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(qualification_id) REFERENCES candidate_qualifications(id), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS screening_questions (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 job_id INT UNSIGNED NOT NULL,
 question VARCHAR(500) NOT NULL,
 required TINYINT NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(job_id) REFERENCES jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS screening_answers (
 application_id INT UNSIGNED NOT NULL,
 question_id INT UNSIGNED NOT NULL,
 answer TEXT NOT NULL,
 recorded_by INT UNSIGNED NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(application_id,question_id), FOREIGN KEY(application_id) REFERENCES applications(id),
 FOREIGN KEY(question_id) REFERENCES screening_questions(id), FOREIGN KEY(recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS recruiting_contacts (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 application_id INT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 kind ENUM('call','email','interview','note') NOT NULL,
 outcome VARCHAR(190) NOT NULL,
 note TEXT NOT NULL,
 follow_up_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(application_id) REFERENCES applications(id), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS project_pay_policies (
 job_id INT UNSIGNED PRIMARY KEY,
 weekly_overtime_after DECIMAL(6,2) NULL,
 overtime_multiplier DECIMAL(5,2) NOT NULL DEFAULT 1.50,
 reviewed_by INT UNSIGNED NOT NULL,
 reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(job_id) REFERENCES jobs(id), FOREIGN KEY(reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS project_qualification_scope (
 job_id INT UNSIGNED NOT NULL,
 type_id INT UNSIGNED NOT NULL,
 trade VARCHAR(190) NOT NULL,
 PRIMARY KEY(job_id,type_id), FOREIGN KEY(job_id,type_id) REFERENCES project_qualifications(job_id,type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS attendance_payroll_sources (
 timesheet_id INT UNSIGNED PRIMARY KEY,
 attendance_json LONGTEXT NOT NULL,
 imported_by INT UNSIGNED NOT NULL,
 imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(timesheet_id) REFERENCES timesheets(id), FOREIGN KEY(imported_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS recruiting_inbound_events (
 event_key CHAR(64) PRIMARY KEY,
 channel VARCHAR(60) NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 application_id INT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(application_id) REFERENCES applications(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS candidate_resumes (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 candidate_id INT UNSIGNED NOT NULL,
 storage_name CHAR(64) NOT NULL UNIQUE,
 file_hash CHAR(64) NOT NULL,
 extension VARCHAR(8) NOT NULL,
 original_name VARCHAR(190) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(candidate_id) REFERENCES candidates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS offboarding_requirements (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 job_id INT UNSIGNED NOT NULL,
 title VARCHAR(190) NOT NULL,
 instructions TEXT NOT NULL,
 UNIQUE(job_id,title), FOREIGN KEY(job_id) REFERENCES jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS offboarding_tasks (
 placement_id INT UNSIGNED NOT NULL,
 requirement_id INT UNSIGNED NOT NULL,
 status ENUM('pending','submitted','approved','rejected') NOT NULL DEFAULT 'pending',
 response TEXT NULL,
 submitted_at DATETIME NULL,
 reviewed_by INT UNSIGNED NULL,
 reviewed_at DATETIME NULL,
 PRIMARY KEY(placement_id,requirement_id), FOREIGN KEY(placement_id) REFERENCES placements(id),
 FOREIGN KEY(requirement_id) REFERENCES offboarding_requirements(id), FOREIGN KEY(reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS project_safety_plans (
 job_id INT UNSIGNED PRIMARY KEY,
 access_instructions TEXT NOT NULL,
 emergency_contacts TEXT NOT NULL,
 escalation_procedure TEXT NOT NULL,
 site_posts TEXT NOT NULL,
 reviewed_by INT UNSIGNED NOT NULL,
 reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(job_id) REFERENCES jobs(id), FOREIGN KEY(reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS saas_checkout_attempts (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 request_key CHAR(32) NOT NULL UNIQUE,
 quantity INT UNSIGNED NOT NULL,
 requested_email VARCHAR(190) NOT NULL,
 completed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX(completed_at,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS workflow_alerts (
 user_id INT UNSIGNED NOT NULL,
 alert_key CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id,alert_key), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS onboarding_requirement_events (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 requirement_id INT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 previous_json LONGTEXT NOT NULL,
 current_json LONGTEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(requirement_id) REFERENCES onboarding_requirements(id), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
