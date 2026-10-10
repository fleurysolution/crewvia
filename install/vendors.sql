-- P3-M06: approved vendors, approval thresholds and project purchasing
-- allocations, on top of procurement (R41-R47).
--
--   * an order goes only to an approved vendor, approved for what is bought,
--     with a W-9 on file and its insurance in date
--   * an order's total decides who approves it: the budget owner, an
--     administrator, or both, and never one person twice
--   * an order going over a project's budget line needs an administrator
--   * an order can be split across projects; costs, commitments and the
--     QuickBooks export follow the split
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p3-m06.sql.
-- The columns added to purchase_orders are added by upgrade.php.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

CREATE TABLE IF NOT EXISTS vendors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  status ENUM('pending','approved','suspended') NOT NULL DEFAULT 'pending',
  categories VARCHAR(120) NOT NULL,
  w9_on_file TINYINT(1) NOT NULL DEFAULT 0,
  insurance_expires DATE NULL,
  contact VARCHAR(190) NULL,
  note VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  UNIQUE KEY uq_vendor_name (name),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (decided_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS vendor_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT UNSIGNED NOT NULL,
  event VARCHAR(40) NOT NULL,
  detail VARCHAR(500) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_vendor_event (vendor_id, id),
  FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Who approves an order of a given total. The tier with the smallest
-- up_to the total fits under applies. The tier without up_to covers the rest.
CREATE TABLE IF NOT EXISTS procurement_thresholds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  up_to DECIMAL(14,2) NULL,
  approvers ENUM('budget_owner','admin','both') NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_threshold (up_to),
  FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Each approval an order received, and in which capacity.
CREATE TABLE IF NOT EXISTS purchase_order_approvals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id INT UNSIGNED NOT NULL,
  approver ENUM('budget_owner','admin') NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_po_approval (purchase_order_id, approver),
  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- What share of an order each project carries. The shares add up to the
-- order's total.
CREATE TABLE IF NOT EXISTS purchase_order_allocations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id INT UNSIGNED NOT NULL,
  job_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  KEY ix_po_alloc (purchase_order_id),
  KEY ix_po_alloc_job (job_id),
  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id),
  FOREIGN KEY (job_id) REFERENCES jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
