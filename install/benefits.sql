-- P2-M04: benefit plans, eligibility, enrollment with effective dates,
-- employer contributions and employee deductions.
--
-- A plan owns two pay items (P1-M05): what the person pays, and what the
-- agency pays on top. Enrolling someone puts both on the person, from the
-- coverage date to its end, so payroll takes them through gross to net
-- with nothing else to set. An enrollment is never edited: a change of
-- coverage ends one and starts the next, and every step is kept.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p2-m04.sql.
-- employee_pay_items.benefit_enrollment_id is added by upgrade.php.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

CREATE TABLE IF NOT EXISTS benefit_plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(120) NOT NULL,
  kind ENUM('medical','dental','vision','life','disability','retirement','other') NOT NULL,
  provider VARCHAR(190) NULL,
  -- fixed: a weekly cost per coverage level. percent: the person elects a
  -- share of gross wages, the agency adds its own share.
  method ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
  employer_percent DECIMAL(5,2) NULL,
  max_employee_percent DECIMAL(5,2) NULL,
  pre_tax TINYINT(1) NOT NULL DEFAULT 1,
  eligible_types VARCHAR(120) NOT NULL,
  waiting_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  deduction_item_id INT UNSIGNED NOT NULL,
  employer_item_id INT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_benefit_plan (code),
  FOREIGN KEY (deduction_item_id) REFERENCES pay_items(id),
  FOREIGN KEY (employer_item_id) REFERENCES pay_items(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- What each coverage level costs a week, on a fixed plan.
CREATE TABLE IF NOT EXISTS benefit_plan_tiers (
  plan_id INT UNSIGNED NOT NULL,
  tier ENUM('employee','employee_spouse','employee_children','family') NOT NULL,
  employee_amount DECIMAL(10,2) NOT NULL,
  employer_amount DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (plan_id, tier),
  FOREIGN KEY (plan_id) REFERENCES benefit_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One person in one plan, from a date: enrolled, or waived (declined, with
-- the reason, which is also a record to keep).
CREATE TABLE IF NOT EXISTS benefit_enrollments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  status ENUM('enrolled','waived','ended') NOT NULL,
  tier ENUM('employee','employee_spouse','employee_children','family') NULL,
  employee_percent DECIMAL(5,2) NULL,
  employee_amount DECIMAL(10,2) NULL,
  employer_amount DECIMAL(10,2) NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NULL,
  reason VARCHAR(500) NULL,
  end_reason VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_by INT UNSIGNED NULL,
  ended_at DATETIME NULL,
  KEY ix_enrollment_person (candidate_id, plan_id, status),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (plan_id) REFERENCES benefit_plans(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (ended_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
