-- P2-M06: recognition, documented disciplinary cases, separation records,
-- and controlled access to the sensitive ones.
--
-- Disciplinary cases and separation records are seen only by
-- administrators and by those an administrator grants HR access, and
-- every opening of a person's HR record is logged. A case's facts are
-- never edited: notes, the outcome, the person's own account and their
-- acknowledgement are added to it.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p2-m06.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

CREATE TABLE IF NOT EXISTS recognitions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NOT NULL,
  placement_id INT UNSIGNED NULL,
  kind ENUM('award','commendation','safety','years_of_service','other') NOT NULL,
  title VARCHAR(190) NOT NULL,
  description VARCHAR(1000) NULL,
  awarded_on DATE NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_recognition_person (candidate_id),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (placement_id) REFERENCES placements(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS disciplinary_cases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(30) NOT NULL,
  candidate_id INT UNSIGNED NOT NULL,
  placement_id INT UNSIGNED NULL,
  safety_incident_id INT UNSIGNED NULL,
  category ENUM('attendance','conduct','safety','performance','policy','other') NOT NULL,
  incident_on DATE NOT NULL,
  facts TEXT NOT NULL,
  status ENUM('open','decided','closed') NOT NULL DEFAULT 'open',
  outcome ENUM('no_action','verbal_warning','written_warning','final_warning','suspension','termination') NULL,
  outcome_note VARCHAR(1000) NULL,
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  response TEXT NULL,
  responded_at DATETIME NULL,
  acknowledged_at DATETIME NULL,
  opened_by INT UNSIGNED NULL,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_by INT UNSIGNED NULL,
  closed_at DATETIME NULL,
  UNIQUE KEY uq_case_reference (reference),
  KEY ix_case_person (candidate_id, status),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (placement_id) REFERENCES placements(id),
  FOREIGN KEY (safety_incident_id) REFERENCES safety_incidents(id),
  FOREIGN KEY (decided_by) REFERENCES users(id),
  FOREIGN KEY (opened_by) REFERENCES users(id),
  FOREIGN KEY (closed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- What was added to a case, in order. Never changed.
CREATE TABLE IF NOT EXISTS disciplinary_notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id INT UNSIGNED NOT NULL,
  kind VARCHAR(30) NOT NULL,
  note TEXT NOT NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_case_note (case_id, id),
  FOREIGN KEY (case_id) REFERENCES disciplinary_cases(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Why an assignment ended, once.
CREATE TABLE IF NOT EXISTS separations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  placement_id INT UNSIGNED NOT NULL,
  candidate_id INT UNSIGNED NOT NULL,
  separated_on DATE NOT NULL,
  reason ENUM('assignment_completed','resigned','terminated','no_show','laid_off','other') NOT NULL,
  voluntary TINYINT(1) NOT NULL,
  detail VARCHAR(1000) NULL,
  rehire ENUM('eligible','review','ineligible') NOT NULL,
  case_id INT UNSIGNED NULL,
  recorded_by INT UNSIGNED NULL,
  recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_separation_placement (placement_id),
  KEY ix_separation_person (candidate_id),
  FOREIGN KEY (placement_id) REFERENCES placements(id),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (case_id) REFERENCES disciplinary_cases(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Who besides administrators may see cases and separations.
CREATE TABLE IF NOT EXISTS hr_access_grants (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  reason VARCHAR(500) NOT NULL,
  granted_by INT UNSIGNED NULL,
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (granted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Every opening of a person's HR record, and every grant and revocation.
CREATE TABLE IF NOT EXISTS hr_access_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  candidate_id INT UNSIGNED NULL,
  subject_user_id INT UNSIGNED NULL,
  detail VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_hr_access_person (candidate_id, id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
