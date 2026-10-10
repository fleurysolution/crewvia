-- P2-M07: HR requests from workers, and the employment letters issued on
-- them. The self-service home that gathers the rest (details, time off,
-- pay, benefits, reviews, goals, HR record) reads existing tables.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p2-m07.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- Something a worker asks, routed to the desk that answers it.
CREATE TABLE IF NOT EXISTS hr_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(30) NOT NULL,
  candidate_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  kind ENUM('employment_letter','pay_question','document_copy','schedule','other') NOT NULL,
  desk ENUM('payroll','recruiter') NOT NULL,
  subject VARCHAR(190) NOT NULL,
  detail TEXT NOT NULL,
  -- An employment letter states the pay rate only when the worker asks for it.
  include_pay TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('open','answered','closed','withdrawn') NOT NULL DEFAULT 'open',
  letter_text TEXT NULL,
  letter_sha256 CHAR(64) NULL,
  letter_issued_by INT UNSIGNED NULL,
  letter_issued_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  UNIQUE KEY uq_hr_request_reference (reference),
  KEY ix_hr_request_desk (desk, status),
  KEY ix_hr_request_person (candidate_id, status),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (letter_issued_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The conversation on a request, in order, from either side.
CREATE TABLE IF NOT EXISTS hr_request_replies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  by_worker TINYINT(1) NOT NULL,
  message TEXT NOT NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_hr_request_reply (request_id, id),
  FOREIGN KEY (request_id) REFERENCES hr_requests(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
