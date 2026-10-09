CREATE TABLE IF NOT EXISTS email_outbox (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 invitation_id INT UNSIGNED NOT NULL UNIQUE,
 recipient VARCHAR(190) NOT NULL,
 encrypted_message LONGTEXT NOT NULL,
 status ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 lease_until DATETIME NULL,
 sent_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(invitation_id) REFERENCES invitations(id), INDEX(status,lease_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS equipment_ownership (
 equipment_id INT UNSIGNED PRIMARY KEY,
 owner_type ENUM('agency','client') NOT NULL DEFAULT 'agency',
 client_id INT UNSIGNED NULL,
 serial_number VARCHAR(190) NULL,
 FOREIGN KEY(equipment_id) REFERENCES equipment(id), FOREIGN KEY(client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS project_departments (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 job_id INT UNSIGNED NOT NULL,
 name VARCHAR(190) NOT NULL,
 UNIQUE(job_id,name), FOREIGN KEY(job_id) REFERENCES jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS placement_departments (
 placement_id INT UNSIGNED PRIMARY KEY,
 department_id INT UNSIGNED NOT NULL,
 FOREIGN KEY(placement_id) REFERENCES placements(id), FOREIGN KEY(department_id) REFERENCES project_departments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS project_operating_settings (
 job_id INT UNSIGNED PRIMARY KEY,
 timezone VARCHAR(100) NOT NULL DEFAULT 'America/New_York',
 pay_cycle VARCHAR(30) NOT NULL DEFAULT 'weekly',
 pay_day TINYINT UNSIGNED NOT NULL DEFAULT 5,
 FOREIGN KEY(job_id) REFERENCES jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
