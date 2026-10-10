-- P1-M06: pay periods, adjustments, and the payroll audit trail.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p1-m06.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- One pay period per week ending, across every project. While it is open,
-- weeks are approved project by project as before. Submitted, approved
-- and locked each freeze the week further, and nothing in a frozen week
-- changes - a later difference is an adjustment in a later period.
CREATE TABLE IF NOT EXISTS payroll_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  week_ending DATE NOT NULL,
  status ENUM('open','submitted','approved','locked') NOT NULL DEFAULT 'open',
  opened_by INT UNSIGNED NOT NULL,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_by INT UNSIGNED NULL,
  submitted_at DATETIME NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  locked_by INT UNSIGNED NULL,
  locked_at DATETIME NULL,
  UNIQUE KEY uq_payroll_run_week (week_ending),
  FOREIGN KEY (opened_by) REFERENCES users(id),
  FOREIGN KEY (submitted_by) REFERENCES users(id),
  FOREIGN KEY (approved_by) REFERENCES users(id),
  FOREIGN KEY (locked_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Money owed for, or taken back from, a week already frozen - paid in an
-- open period. The week it corrects is named, and so is the reason.
CREATE TABLE IF NOT EXISTS payroll_adjustments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id INT UNSIGNED NOT NULL,
  candidate_id INT UNSIGNED NOT NULL,
  timesheet_id INT UNSIGNED NULL,
  kind ENUM('correction','back_pay','reimbursement','recovery') NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  hours DECIMAL(6,2) NULL,
  reason VARCHAR(500) NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_adjustment_run (run_id, candidate_id),
  FOREIGN KEY (run_id) REFERENCES payroll_runs(id),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (timesheet_id) REFERENCES timesheets(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Everything that happens to a pay period, in order, and who did it.
CREATE TABLE IF NOT EXISTS payroll_run_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id INT UNSIGNED NOT NULL,
  event VARCHAR(60) NOT NULL,
  detail VARCHAR(1000) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_run_event (run_id, id),
  FOREIGN KEY (run_id) REFERENCES payroll_runs(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
