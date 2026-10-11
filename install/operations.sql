-- P4-M07: when backups, restore drills and the scheduled jobs ran, and how
-- each went. Read by the System health page and install/health-check.php.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p4-m07.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

CREATE TABLE IF NOT EXISTS ops_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(40) NOT NULL,
  status ENUM('ok','warning','failed') NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NOT NULL,
  seconds DECIMAL(10,2) NULL,
  detail TEXT NULL,
  host VARCHAR(120) NULL,
  KEY ix_ops_kind (kind, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
