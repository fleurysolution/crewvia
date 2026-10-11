-- P4-M05: external payroll and applicant-tracking connectors, sandbox
-- before live. ADP is the payroll provider RSS uses: its connector is the
-- ADP RUN import file, now recorded, confirmed against ADP and reconciled
-- with ADP's payroll register. The applicant-tracking connector is the
-- partner intake (/recruiting-webhook). No background-screening provider
-- is authorized, so none is connected: screening stays manual (/checks).
--
-- Applied by install/upgrade.php, which also widens integration_events to
-- ADP and applicant tracking. Reversed by install/rollback/p4-m05.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- Each connector's state. disabled: nothing passes. sandbox: test files,
-- test events, nothing becomes real. live: allowed only once a sandbox
-- trial has passed and an administrator has recorded who authorized it.
CREATE TABLE IF NOT EXISTS connector_modes (
  connector VARCHAR(80) NOT NULL PRIMARY KEY,
  kind ENUM('payroll','ats','screening') NOT NULL,
  mode ENUM('disabled','sandbox','live') NOT NULL DEFAULT 'sandbox',
  authorization_ref VARCHAR(190) NULL,
  authorized_by INT UNSIGNED NULL,
  authorized_at DATETIME NULL,
  sandbox_passed_at DATETIME NULL,
  sandbox_note VARCHAR(500) NULL,
  note VARCHAR(500) NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (authorized_by) REFERENCES users(id),
  FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Every payroll file produced: the file itself, its SHA-256, what it
-- covers, and what ADP said when it was imported.
CREATE TABLE IF NOT EXISTS payroll_exports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('review','adp_test','adp_live') NOT NULL,
  week_ending DATE NOT NULL,
  job_id INT UNSIGNED NULL,
  csv MEDIUMTEXT NOT NULL,
  sha256 CHAR(64) NOT NULL,
  row_count INT UNSIGNED NOT NULL,
  employee_count INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  import_result ENUM('accepted','rejected') NULL,
  adp_batch_ref VARCHAR(120) NULL,
  import_note VARCHAR(500) NULL,
  confirmed_by INT UNSIGNED NULL,
  confirmed_at DATETIME NULL,
  KEY ix_payroll_export_week (week_ending, id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (confirmed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ADP's payroll register for a week beside Crewvia's frozen pay, by ADP
-- employee id: what matched, what differed, who is on one side only.
CREATE TABLE IF NOT EXISTS adp_reconciliations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  week_ending DATE NOT NULL,
  file_sha256 CHAR(64) NOT NULL,
  matched INT UNSIGNED NOT NULL,
  differences INT UNSIGNED NOT NULL,
  result_json MEDIUMTEXT NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_adp_reconciliation_week (week_ending, id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
