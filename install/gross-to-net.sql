-- P1-M05: deductions and employer contributions, per person, effective-dated.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p1-m05.sql.
-- wage_advance_payments.timesheet_id is added by upgrade.php behind an
-- information_schema guard.
--
-- No tax is computed from these tables. They prepare gross to net before
-- taxes; withholding is the payroll provider's (constraint C9).

-- What can be taken from pay, or paid on top of it by the employer.
CREATE TABLE IF NOT EXISTS pay_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  label VARCHAR(120) NOT NULL,
  side ENUM('deduction','employer_contribution') NOT NULL,
  -- fixed is an amount a week, percent_of_gross a share of the week's
  -- gross wages, advance_repayment the person's open wage advances at
  -- their agreed weekly repayment (one system item, never set per person).
  -- No comment line in this file may end in a semicolon: upgrade.php
  -- splits statements there.
  method ENUM('fixed','percent_of_gross','advance_repayment') NOT NULL,
  pre_tax TINYINT(1) NOT NULL DEFAULT 0,
  provider_code VARCHAR(40) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pay_item_code (code),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A pay item for one person, from a date, until a date or open.
CREATE TABLE IF NOT EXISTS employee_pay_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NOT NULL,
  pay_item_id INT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NULL,
  note VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_employee_pay_item (candidate_id, starts_on),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (pay_item_id) REFERENCES pay_items(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
