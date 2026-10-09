CREATE TABLE IF NOT EXISTS client_access (
 user_id INT UNSIGNED NOT NULL, client_id INT UNSIGNED NOT NULL,
 granted_by INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id,client_id), FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS client_orders (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, client_id INT UNSIGNED NOT NULL,
 title VARCHAR(190) NOT NULL, description TEXT NOT NULL,
 source ENUM('email','phone','meeting','portal','other') NOT NULL,
 source_reference VARCHAR(190) NULL, requested_by VARCHAR(190) NULL,
 headcount INT UNSIGNED NOT NULL, site_city VARCHAR(120) NULL, site_state VARCHAR(40) NULL,
 starts_on DATE NULL, ends_on DATE NULL,
 status ENUM('new','reviewing','approved','declined','converted') NOT NULL DEFAULT 'new',
 job_id INT UNSIGNED NULL UNIQUE, created_by INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(client_id) REFERENCES clients(id), FOREIGN KEY(job_id) REFERENCES jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS client_order_events (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id INT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NOT NULL, action VARCHAR(40) NOT NULL, note TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(order_id) REFERENCES client_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS client_attendance_reviews (
 attendance_id INT UNSIGNED PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
 decision ENUM('confirmed','disputed') NOT NULL, note VARCHAR(1000) NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(attendance_id) REFERENCES attendance_records(id), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
