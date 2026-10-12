-- P5-M08: Twilio for texts and calls from Crewvia itself, prepared and
-- not live. The connector starts in sandbox: nothing leaves Crewvia until
-- an administrator has entered the agency's Twilio credentials, a check of
-- the account has passed, and live is authorized with a reference (the
-- connectors' rule, P4-M05).
--
-- The credentials are entered on Integrations and stored sealed with the
-- encryption key (encrypted_secret, so the key rotation tool re-seals it).
-- They are never shown back.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p5-m08.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

CREATE TABLE IF NOT EXISTS provider_credentials (
  provider VARCHAR(40) NOT NULL PRIMARY KEY,
  public_json TEXT NOT NULL,
  encrypted_secret TEXT NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Every text sent from Crewvia or received by it, with what Twilio said.
CREATE TABLE IF NOT EXISTS sms_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  direction ENUM('out','in') NOT NULL,
  phone VARCHAR(20) NOT NULL,
  body VARCHAR(1600) NOT NULL,
  template_id INT UNSIGNED NULL,
  mode ENUM('sandbox','live') NOT NULL,
  provider_sid VARCHAR(64) NULL,
  status VARCHAR(20) NOT NULL,
  error_code VARCHAR(20) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sms_sid (provider_sid),
  KEY ix_sms_candidate (candidate_id, id),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A call placed through Crewvia: Twilio rings the staff member's phone,
-- then connects the person. The token names the call in the address
-- Twilio asks for its instructions.
CREATE TABLE IF NOT EXISTS voice_calls (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  staff_phone VARCHAR(20) NOT NULL,
  candidate_phone VARCHAR(20) NOT NULL,
  token CHAR(32) NOT NULL,
  mode ENUM('sandbox','live') NOT NULL,
  provider_sid VARCHAR(64) NULL,
  status VARCHAR(20) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_call_token (token),
  UNIQUE KEY uq_call_sid (provider_sid),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
