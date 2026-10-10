-- P3-M01: project costing. The report reads records that already exist and
-- writes no financial transaction. What it adds is the budget, kept as a
-- list of changes: a budget line is never overwritten, its current figure
-- is its latest change, and every change says who, when and why.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p3-m01.sql.
-- The burden and overhead rates on jobs are added by upgrade.php.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

CREATE TABLE IF NOT EXISTS project_budget_changes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  category ENUM('revenue','labour','burden','per_diem','hotels','transportation','equipment','other','overhead') NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  previous_amount DECIMAL(14,2) NULL,
  reason VARCHAR(500) NOT NULL,
  changed_by INT UNSIGNED NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_budget_line (job_id, category, id),
  FOREIGN KEY (job_id) REFERENCES jobs(id),
  FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
