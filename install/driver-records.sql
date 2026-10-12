-- P5-M09: driving-record checks run in the providers' own portals and
-- recorded here: the motor vehicle record (MVRNOW, which also returns
-- the commercial licence), the FMCSA Drug and Alcohol Clearinghouse, and
-- the FMCSA Pre-Employment Screening Program (PSP).
--
-- No provider is connected. A recruiter records the person's consent,
-- opens the provider's site, runs the check there, then records the
-- result here. The driver's licence number is kept sealed with the
-- encryption key (encrypted_license_number, so the key rotation tool
-- re-seals it), and only its last four are shown.
--
-- A record showing a valid commercial licence, or a medical certificate,
-- writes it into the person's qualifications, verified, with its expiry,
-- never shortening one already verified for longer.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p5-m09.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

CREATE TABLE IF NOT EXISTS driver_checks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NOT NULL,
  kind ENUM('mvr','clearinghouse','psp') NOT NULL,
  state CHAR(2) NOT NULL,
  encrypted_license_number TEXT NOT NULL,
  license_last4 VARCHAR(4) NOT NULL,
  consent_method ENUM('signed_form','clearinghouse_portal','psp_form') NOT NULL,
  consent_on DATE NOT NULL,
  consent_reference VARCHAR(190) NULL,
  query_type ENUM('full','limited') NULL,
  status ENUM('requested','completed','cancelled') NOT NULL DEFAULT 'requested',
  requested_by INT UNSIGNED NOT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  follows_check_id INT UNSIGNED NULL,
  result_on DATE NULL,
  provider_reference VARCHAR(80) NULL,
  license_status ENUM('valid','suspended','revoked','expired','cancelled','not_found') NULL,
  cdl TINYINT(1) NULL,
  cdl_class ENUM('A','B','C') NULL,
  endorsements VARCHAR(20) NULL,
  restrictions VARCHAR(190) NULL,
  license_expires_on DATE NULL,
  medical_expires_on DATE NULL,
  violations_3y TINYINT UNSIGNED NULL,
  accidents_3y TINYINT UNSIGNED NULL,
  major_violation TINYINT(1) NULL,
  clearinghouse_result ENUM('no_information','information_found','prohibited') NULL,
  crashes_5y TINYINT UNSIGNED NULL,
  inspections_3y SMALLINT UNSIGNED NULL,
  oos_3y SMALLINT UNSIGNED NULL,
  decision ENUM('acceptable','review','not_acceptable') NULL,
  adverse_notice_on DATE NULL,
  notes VARCHAR(1000) NULL,
  recorded_by INT UNSIGNED NULL,
  recorded_at DATETIME NULL,
  next_due_on DATE NULL,
  KEY ix_driver_check_person (candidate_id, kind, id),
  KEY ix_driver_check_due (status, next_due_on),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (requested_by) REFERENCES users(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO qualification_types (name, expires_required) VALUES
  ('Commercial driver''s licence (CDL)', 1),
  ('DOT medical certificate', 1);
