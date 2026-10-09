CREATE TABLE IF NOT EXISTS requisition_publication (
 vacancy_id INT UNSIGNED PRIMARY KEY,
 published_on DATE NOT NULL,
 expires_on DATE NOT NULL,
 employment_type VARCHAR(40) NOT NULL DEFAULT 'TEMPORARY',
 FOREIGN KEY(vacancy_id) REFERENCES vacancies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS recruitment_channel_requests (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 vacancy_id INT UNSIGNED NOT NULL,
 channel VARCHAR(60) NOT NULL,
 status VARCHAR(40) NOT NULL DEFAULT 'awaiting_integration',
 requested_by INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(vacancy_id,channel), FOREIGN KEY(vacancy_id) REFERENCES vacancies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS ai_recruitment_reviews (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 application_id INT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 provider VARCHAR(40) NOT NULL,
 model VARCHAR(120) NOT NULL,
 input_hash CHAR(64) NOT NULL,
 review_json LONGTEXT NOT NULL,
 input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
 output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(application_id) REFERENCES applications(id), INDEX(user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
