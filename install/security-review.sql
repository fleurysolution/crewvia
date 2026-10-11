-- P4-M06: privacy notices and the consent given to them. The audit log
-- gains the sender's address and becomes append-only (both added by
-- upgrade.php: a column, and triggers that cannot sit in this file).
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p4-m06.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- Each version of the agency's privacy notice, as published. The text is
-- the agency's own: Crewvia shows it and records who agreed to which.
CREATE TABLE IF NOT EXISTS privacy_notices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  body MEDIUMTEXT NOT NULL,
  sha256 CHAR(64) NOT NULL,
  published_by INT UNSIGNED NULL,
  published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (published_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A person's consent: to what, through which form, against which notice,
-- when and from where.
CREATE TABLE IF NOT EXISTS consent_records (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NOT NULL,
  purpose VARCHAR(60) NOT NULL,
  source VARCHAR(60) NOT NULL,
  notice_id INT UNSIGNED NULL,
  ip VARCHAR(45) NULL,
  given_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_consent_candidate (candidate_id, id),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (notice_id) REFERENCES privacy_notices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
