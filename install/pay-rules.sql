-- P1-M03: pay rules as data - overtime, double time, holidays, shift premiums.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p1-m03.sql.
-- jobs.pay_rule_set_id is added by upgrade.php behind an information_schema
-- guard.
--
-- No rule here is the law. A rule set is what somebody configured for a
-- jurisdiction and a person confirmed before it could be used: it starts
-- as a draft, and becomes active only with the name of who confirmed it.

CREATE TABLE IF NOT EXISTS pay_rule_sets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  jurisdiction VARCHAR(80) NOT NULL,
  weekly_overtime_after DECIMAL(5,2) NULL,
  weekly_overtime_multiplier DECIMAL(4,2) NOT NULL DEFAULT 1.50,
  daily_overtime_after DECIMAL(5,2) NULL,
  daily_overtime_multiplier DECIMAL(4,2) NOT NULL DEFAULT 1.50,
  daily_double_after DECIMAL(5,2) NULL,
  daily_double_multiplier DECIMAL(4,2) NOT NULL DEFAULT 2.00,
  holiday_multiplier DECIMAL(4,2) NULL,
  notes VARCHAR(1000) NULL,
  status ENUM('draft','active','retired') NOT NULL DEFAULT 'draft',
  confirmed_by VARCHAR(190) NULL,
  confirmed_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pay_rule_set_name (name),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Days paid at the holiday rate under one rule set.
CREATE TABLE IF NOT EXISTS pay_rule_holidays (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_set_id INT UNSIGNED NOT NULL,
  holiday_date DATE NOT NULL,
  name VARCHAR(120) NOT NULL,
  UNIQUE KEY uq_pay_rule_holiday (rule_set_id, holiday_date),
  FOREIGN KEY (rule_set_id) REFERENCES pay_rule_sets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- An amount per hour added for a shift, matched on the assignment's shift.
CREATE TABLE IF NOT EXISTS pay_rule_shift_premiums (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_set_id INT UNSIGNED NOT NULL,
  shift_label VARCHAR(190) NOT NULL,
  amount_per_hour DECIMAL(8,2) NOT NULL,
  UNIQUE KEY uq_pay_rule_shift (rule_set_id, shift_label),
  FOREIGN KEY (rule_set_id) REFERENCES pay_rule_sets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
