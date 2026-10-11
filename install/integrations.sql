-- P4-M04: Gmail, DocuSign and Stripe made sturdy: the audit trail of every
-- call and webhook outcome, cached provider tokens, retries with backoff
-- for the DocuSign Connect queue, and one-time keys against a message sent
-- twice. The three stay disabled until configured.
--
-- Applied by install/upgrade.php, which also adds columns to
-- gmail_connections and docusign_connect_events. Reversed by
-- install/rollback/p4-m04.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- What happened with a provider, kept: never a token, a secret or a
-- message body. reference is the provider's own id (event, envelope,
-- message) or the user it concerns.
CREATE TABLE IF NOT EXISTS integration_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider ENUM('gmail','docusign','stripe') NOT NULL,
  action VARCHAR(60) NOT NULL,
  outcome ENUM('ok','duplicate','ignored','retry','failed','refused') NOT NULL,
  reference VARCHAR(190) NULL,
  detail VARCHAR(500) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_integration_event (provider, id),
  KEY ix_integration_outcome (outcome, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A provider token Crewvia obtained for itself (the DocuSign JWT grant),
-- encrypted, until it expires.
CREATE TABLE IF NOT EXISTS integration_tokens (
  provider VARCHAR(20) NOT NULL,
  subject VARCHAR(120) NOT NULL,
  encrypted_token MEDIUMTEXT NOT NULL,
  expires_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (provider, subject)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A one-time key on a form that reaches a provider: the same form posted
-- twice reaches it once.
CREATE TABLE IF NOT EXISTS integration_nonces (
  nonce CHAR(64) NOT NULL PRIMARY KEY,
  purpose VARCHAR(40) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_at DATETIME NULL,
  KEY ix_integration_nonce_user (user_id, purpose)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
