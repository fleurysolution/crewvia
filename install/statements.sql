-- P3-M05 (statements): budgets by account and month. The income
-- statement, balance sheet and cash flow are read from the ledger (P3-M03)
-- and need no table.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p3-m05-statements.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- The budget of one income or expense account for one month.
CREATE TABLE IF NOT EXISTS gl_budgets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_key VARCHAR(40) NOT NULL,
  period CHAR(7) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_gl_budget (account_key, period),
  FOREIGN KEY (account_key) REFERENCES accounting_accounts(account_key),
  FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Every change to a budget, kept: what it was, what it became, who, why.
CREATE TABLE IF NOT EXISTS gl_budget_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_key VARCHAR(40) NOT NULL,
  period CHAR(7) NOT NULL,
  old_amount DECIMAL(14,2) NULL,
  new_amount DECIMAL(14,2) NOT NULL,
  note VARCHAR(500) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_gl_budget_event (account_key, period, id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
