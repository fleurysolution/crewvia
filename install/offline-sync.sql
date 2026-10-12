-- P5-M06b: offline attendance. A phone a worker or supervisor chose to
-- trust, and everything it sent: hours typed without signal by a worker,
-- gate marks made without signal by a supervisor for their own crew.
--
-- The phone holds a random token in an HttpOnly, SameSite=Strict cookie
-- only the hash is kept here. A grant ends at its expiry (14 days by
-- default), when it is removed, at sign-out on that phone, and whenever the
-- account's sign-in version moves (a password change, "sign out
-- everywhere"): session_version is copied in when the grant is made.
--
-- What arrives goes into attendance_records and assignment_checkins by the
-- same rules as the screens; this log keeps when it was typed on the phone,
-- when it arrived, and what the server answered. An entry id sent twice is
-- answered from here and written once.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p5-m06b.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

CREATE TABLE IF NOT EXISTS device_grants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  label VARCHAR(120) NOT NULL,
  session_version INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  revoked_by INT UNSIGNED NULL,
  revoke_reason VARCHAR(40) NULL,
  UNIQUE KEY uq_device_token (token_hash),
  KEY ix_device_user (user_id, revoked_at),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (revoked_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS attendance_sync_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  device_id INT UNSIGNED NOT NULL,
  entry_id VARCHAR(64) NOT NULL,
  kind ENUM('hours','gate') NOT NULL,
  placement_id INT UNSIGNED NULL,
  work_date DATE NULL,
  hours DECIMAL(5,2) NULL,
  present TINYINT(1) NULL,
  captured_at DATETIME NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  outcome ENUM('saved','replaced','refused') NOT NULL,
  reason VARCHAR(40) NULL,
  message VARCHAR(255) NULL,
  attendance_id INT UNSIGNED NULL,
  UNIQUE KEY uq_sync_entry (user_id, entry_id),
  KEY ix_sync_placement (placement_id, work_date),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (device_id) REFERENCES device_grants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
