-- P5-M33: who may open identity documents, and the one-time codes for a W-4 signed in person.
CREATE TABLE IF NOT EXISTS identity_readers (
  user_id    INT UNSIGNED PRIMARY KEY,
  granted_by INT UNSIGNED NULL,
  granted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Only the hash of a code is kept: the code is shown once, on the coordinator's screen.
CREATE TABLE IF NOT EXISTS signing_codes (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NOT NULL,
  purpose      VARCHAR(20)  NOT NULL,
  code_hash    CHAR(64)     NOT NULL,
  created_by   INT UNSIGNED NOT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at   DATETIME     NOT NULL,
  used_at      DATETIME     NULL,
  outcome      VARCHAR(20)  NULL,
  KEY ix_signing_person (candidate_id, purpose, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
