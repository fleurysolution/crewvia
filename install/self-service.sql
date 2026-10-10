-- HR self-service (R22-R24, BACKLOG T12-T14): a worker proposes, somebody
-- here validates, and only then does anything change.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/self-service.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- Bank details a worker entered themselves. Kept apart from the record in
-- use, so a proposal never replaces verified details before payroll has
-- checked it. Encrypted as one blob, exactly as worker_bank_details is.
CREATE TABLE IF NOT EXISTS worker_bank_change_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NOT NULL,
  encrypted_details TEXT NOT NULL,
  last_four CHAR(4) NOT NULL,
  bank_label VARCHAR(90) NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  submitted_by INT UNSIGNED NOT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  review_note VARCHAR(500) NULL,
  KEY ix_bank_change (candidate_id, status),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (submitted_by) REFERENCES users(id),
  FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A change to a personal detail, asked for by the worker and applied only
-- when somebody here agrees (R24).
CREATE TABLE IF NOT EXISTS profile_change_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NOT NULL,
  field ENUM('full_name','email','phone','city','state') NOT NULL,
  old_value VARCHAR(190) NULL,
  new_value VARCHAR(190) NOT NULL,
  reason VARCHAR(500) NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  submitted_by INT UNSIGNED NOT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  review_note VARCHAR(500) NULL,
  KEY ix_profile_change (candidate_id, status),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (submitted_by) REFERENCES users(id),
  FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- "Nothing has changed": what a returning worker says instead of filling
-- everything in again (R23), and when.
CREATE TABLE IF NOT EXISTS details_confirmations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NOT NULL,
  placement_id INT UNSIGNED NULL,
  confirmed_by INT UNSIGNED NOT NULL,
  confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_confirmation (candidate_id, id),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (placement_id) REFERENCES placements(id),
  FOREIGN KEY (confirmed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
