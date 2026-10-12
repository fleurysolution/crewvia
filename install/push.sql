-- P5-M06c: push notifications. Off until an administrator turns them on.
--
-- A push carries no content: it only wakes the phone, which then says
-- "New in Crewvia" in its owner's language and opens the notifications page
-- when tapped. Google, Apple, Mozilla and Microsoft, who carry the push,
-- never see a name or a message.
--
-- The agency's key pair (VAPID, P-256) is made on the server by
-- install/push-keys.php. The private key is stored sealed with the
-- encryption key (crypto_seal), in a column the key rotation tool finds by
-- its encrypted_ prefix.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p5-m06c.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

CREATE TABLE IF NOT EXISTS push_keys (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  public_key VARCHAR(120) NOT NULL,
  encrypted_private_key TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One phone's (one browser's) push subscription, for one person.
CREATE TABLE IF NOT EXISTS push_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  endpoint VARCHAR(1000) NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  kinds VARCHAR(200) NOT NULL,
  label VARCHAR(120) NOT NULL,
  session_version INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_sent_at DATETIME NULL,
  last_status SMALLINT NULL,
  failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ended_at DATETIME NULL,
  end_reason VARCHAR(40) NULL,
  UNIQUE KEY uq_push_endpoint (endpoint_hash),
  KEY ix_push_user (user_id, ended_at),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Every push sent: when, to which subscription, and what the push service answered.
CREATE TABLE IF NOT EXISTS push_deliveries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id INT UNSIGNED NOT NULL,
  reason ENUM('notification','test') NOT NULL,
  up_to_notification INT UNSIGNED NULL,
  status SMALLINT NULL,
  error VARCHAR(255) NULL,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_delivery_subscription (subscription_id, id),
  FOREIGN KEY (subscription_id) REFERENCES push_subscriptions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
