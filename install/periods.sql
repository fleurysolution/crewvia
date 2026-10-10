-- P3-M05: financial periods. A month is open until an administrator closes
-- it. Once closed, nothing new is recorded or exported with a date in it:
-- no payment, credit note, issued invoice, approved bill, paid claim, and
-- no QuickBooks entry. A correction is made in an open month. Reopening is
-- possible, with a reason, and every close and reopen is kept.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p3-m05.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

CREATE TABLE IF NOT EXISTS financial_periods (
  period CHAR(7) NOT NULL PRIMARY KEY,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  changed_by INT UNSIGNED NULL,
  changed_at DATETIME NULL,
  FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS financial_period_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period CHAR(7) NOT NULL,
  action ENUM('close','reopen') NOT NULL,
  reason VARCHAR(500) NOT NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_period_event (period, id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
