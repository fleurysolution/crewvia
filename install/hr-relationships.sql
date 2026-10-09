-- P1-M01: who somebody is employed as, and where an assignment's terms
-- came from.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p1-m01.sql.
-- New tables only; the columns this module adds to existing tables are
-- added by upgrade.php behind information_schema guards.

-- Classification over time. employee_profiles.employment_type stays the
-- value every screen reads; this is the record of how it got there.
--
-- effective_from is NULL only on the initial row written for a profile
-- that existed before this history was kept: the value was held, nobody
-- knows since when, and inventing a date would be a lie in the record.
CREATE TABLE IF NOT EXISTS employee_classifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NOT NULL,
  employment_type ENUM('hourly','salaried','contractor','external') NOT NULL,
  -- Whether the overtime law applies. Decided by duties and salary, which
  -- the database does not hold, so 'not_determined' is an honest value.
  flsa_status ENUM('non_exempt','exempt','not_applicable','not_determined')
    NOT NULL DEFAULT 'not_determined',
  effective_from DATE NULL,
  reason VARCHAR(500) NOT NULL,
  recorded_by INT UNSIGNED NULL,
  recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_classification_person (candidate_id, effective_from),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Every change to what one person on one assignment is paid or billed.
-- Approved weeks are frozen in pay_snapshots and do not move; this says
-- why the weeks after them differ.
CREATE TABLE IF NOT EXISTS placement_rate_changes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  placement_id INT UNSIGNED NOT NULL,
  old_pay_rate DECIMAL(10,2) NULL,
  new_pay_rate DECIMAL(10,2) NULL,
  old_bill_rate DECIMAL(10,2) NULL,
  new_bill_rate DECIMAL(10,2) NULL,
  old_per_diem_rate DECIMAL(8,2) NULL,
  new_per_diem_rate DECIMAL(8,2) NULL,
  old_guarantee_hours SMALLINT UNSIGNED NULL,
  new_guarantee_hours SMALLINT UNSIGNED NULL,
  changed_by INT UNSIGNED NOT NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_rate_change_placement (placement_id, id),
  FOREIGN KEY (placement_id) REFERENCES placements(id),
  FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
