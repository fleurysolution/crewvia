CREATE TABLE IF NOT EXISTS saas_subscription (
 id TINYINT UNSIGNED PRIMARY KEY,
 stripe_customer_id VARCHAR(100) NULL,
 stripe_subscription_id VARCHAR(100) NULL,
 status VARCHAR(40) NOT NULL DEFAULT 'not_configured',
 quantity INT UNSIGNED NOT NULL DEFAULT 0,
 period_end DATETIME NULL,
 last_checkout_id VARCHAR(100) NULL,
 last_checkout_url TEXT NULL,
 checkout_expires_at DATETIME NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT IGNORE INTO saas_subscription(id) VALUES (1);
CREATE TABLE IF NOT EXISTS saas_webhook_events (
 event_id VARCHAR(100) PRIMARY KEY,
 event_type VARCHAR(120) NOT NULL,
 processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS saas_usage_snapshots (
 period_month DATE PRIMARY KEY,
 unique_workers INT UNSIGNED NOT NULL,
 basis VARCHAR(100) NOT NULL,
 candidate_ids_json LONGTEXT NOT NULL,
 recorded_by INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
