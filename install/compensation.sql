-- P2-M01: grades with pay bands, and pay changes that take effect on a date.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p2-m01.sql.
-- employee_profiles.grade_id is added by upgrade.php.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- A grade, and the band its pay is expected to sit in. A blank end of a
-- band is open.
CREATE TABLE IF NOT EXISTS pay_grades (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  label VARCHAR(120) NOT NULL,
  rate_min DECIMAL(10,2) NULL,
  rate_max DECIMAL(10,2) NULL,
  salary_min DECIMAL(12,2) NULL,
  salary_max DECIMAL(12,2) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pay_grade_code (code),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A change of salary, of an assignment's hourly rate, or of grade, from a
-- date. It applies to every week ending on or after that date. Until the
-- date comes it can be cancelled. applied_at records when the current
-- values on the profile or placement were brought up to it.
CREATE TABLE IF NOT EXISTS compensation_changes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NOT NULL,
  placement_id INT UNSIGNED NULL,
  kind ENUM('salary','hourly_rate','grade') NOT NULL,
  old_amount DECIMAL(12,2) NULL,
  new_amount DECIMAL(12,2) NULL,
  grade_id INT UNSIGNED NULL,
  effective_from DATE NOT NULL,
  reason VARCHAR(500) NOT NULL,
  outside_band TINYINT(1) NOT NULL DEFAULT 0,
  recorded_by INT UNSIGNED NOT NULL,
  recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied_at DATETIME NULL,
  cancelled_by INT UNSIGNED NULL,
  cancelled_at DATETIME NULL,
  KEY ix_comp_person (candidate_id, kind, effective_from),
  KEY ix_comp_placement (placement_id, kind, effective_from),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (placement_id) REFERENCES placements(id),
  FOREIGN KEY (grade_id) REFERENCES pay_grades(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id),
  FOREIGN KEY (cancelled_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
