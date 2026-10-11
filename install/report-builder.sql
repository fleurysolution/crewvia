-- P4-M03: the report builder, role-controlled exports, schedules and
-- delivery inside Crewvia. Outbound email stays disabled: a scheduled
-- report is stored here, with its SHA-256, and each recipient is told in
-- the application and downloads it signed in, while still allowed to.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p4-m03.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- A saved report: one of the P4-M01 reports, its columns, its period
-- (fixed dates or relative to the day it is run), conditions on its rows,
-- an order and a limit.
CREATE TABLE IF NOT EXISTS report_definitions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  source VARCHAR(40) NOT NULL,
  columns_json TEXT NOT NULL,
  filters_json TEXT NOT NULL,
  conditions_json TEXT NOT NULL,
  sort_column VARCHAR(40) NULL,
  sort_dir ENUM('asc','desc') NOT NULL DEFAULT 'asc',
  row_limit SMALLINT UNSIGNED NOT NULL DEFAULT 1000,
  shared TINYINT(1) NOT NULL DEFAULT 0,
  owner_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  archived_at DATETIME NULL,
  KEY ix_report_definition_owner (owner_id, archived_at),
  FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Who may export each report. No row: whoever may read it may export it.
CREATE TABLE IF NOT EXISTS report_export_policy (
  source VARCHAR(40) NOT NULL PRIMARY KEY,
  roles VARCHAR(120) NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- When a saved report runs by itself, and for whom.
CREATE TABLE IF NOT EXISTS report_schedules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  definition_id INT UNSIGNED NOT NULL,
  frequency ENUM('daily','weekly','monthly') NOT NULL,
  weekday TINYINT UNSIGNED NULL,
  monthday TINYINT UNSIGNED NULL,
  run_hour TINYINT UNSIGNED NOT NULL DEFAULT 6,
  recipients_json TEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  next_run_at DATETIME NOT NULL,
  last_run_at DATETIME NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_report_schedule_due (active, next_run_at),
  FOREIGN KEY (definition_id) REFERENCES report_definitions(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One scheduled run: the file it produced, its fingerprint, until when it
-- may be downloaded. Once expired the file itself is erased. run_for is
-- the slot it was due for: a slot runs once.
CREATE TABLE IF NOT EXISTS report_deliveries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  schedule_id INT UNSIGNED NOT NULL,
  definition_id INT UNSIGNED NOT NULL,
  run_for DATETIME NOT NULL,
  period_label VARCHAR(80) NOT NULL,
  status ENUM('ready','failed','expired') NOT NULL,
  csv MEDIUMTEXT NULL,
  sha256 CHAR(64) NULL,
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  UNIQUE KEY uq_report_delivery_slot (schedule_id, run_for),
  FOREIGN KEY (schedule_id) REFERENCES report_schedules(id),
  FOREIGN KEY (definition_id) REFERENCES report_definitions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Each recipient of a run: told when, downloaded when. A recipient no
-- longer allowed when the run happened is kept with the reason.
CREATE TABLE IF NOT EXISTS report_delivery_recipients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  delivery_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  skipped_reason VARCHAR(190) NULL,
  notified_at DATETIME NULL,
  downloaded_at DATETIME NULL,
  UNIQUE KEY uq_report_delivery_user (delivery_id, user_id),
  FOREIGN KEY (delivery_id) REFERENCES report_deliveries(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Every file that left Crewvia: who, what, with which filters, how many
-- rows, its SHA-256.
CREATE TABLE IF NOT EXISTS report_exports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('download','delivery') NOT NULL,
  source VARCHAR(40) NOT NULL,
  definition_id INT UNSIGNED NULL,
  delivery_id INT UNSIGNED NULL,
  params_json TEXT NOT NULL,
  row_count INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_report_export_user (user_id, id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
