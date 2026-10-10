-- P2-M05: loans and wage advances - installment schedules, approval
-- controls, outstanding balances, pauses and write-offs - on top of the
-- advances payroll already repays week by week (P1-M05).
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p2-m05.sql.
-- The columns added to wage_advances are added by upgrade.php.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- Weeks repayment is paused: payroll takes nothing for this advance in a
-- week ending inside the window, and the schedule does not expect it.
CREATE TABLE IF NOT EXISTS advance_pauses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  advance_id INT UNSIGNED NOT NULL,
  from_week DATE NOT NULL,
  until_week DATE NOT NULL,
  reason VARCHAR(500) NOT NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_advance_pause (advance_id, from_week),
  FOREIGN KEY (advance_id) REFERENCES wage_advances(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Every step of an advance or loan, with who and why.
CREATE TABLE IF NOT EXISTS advance_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  advance_id INT UNSIGNED NOT NULL,
  event VARCHAR(40) NOT NULL,
  detail VARCHAR(500) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_advance_event (advance_id, id),
  FOREIGN KEY (advance_id) REFERENCES wage_advances(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
