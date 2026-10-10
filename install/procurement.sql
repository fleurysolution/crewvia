-- Procurement (REQUIREMENTS R41-R47): request -> purchase order -> receipt
-- -> commitment. No bid analysis or committee: RSS does not run bids.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/procurement.sql.
-- The columns added to jobs and vendor_invoices are added by upgrade.php.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- What a quantity is counted in. Data, created when needed (R47).
CREATE TABLE IF NOT EXISTS procurement_units (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  label VARCHAR(80) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_procurement_unit (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Something the job needs (R41). A lodging request raised by a hire
-- names the placement and says so in its source (R42).
CREATE TABLE IF NOT EXISTS purchase_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  category ENUM('lodging','vehicle','safety_equipment','other') NOT NULL,
  title VARCHAR(190) NOT NULL,
  description VARCHAR(1000) NULL,
  quantity DECIMAL(10,2) NOT NULL,
  unit_id INT UNSIGNED NOT NULL,
  needed_from DATE NULL,
  needed_to DATE NULL,
  estimated_unit_cost DECIMAL(10,2) NULL,
  hotel_id INT UNSIGNED NULL,
  placement_id INT UNSIGNED NULL,
  source ENUM('manual','hire') NOT NULL DEFAULT 'manual',
  status ENUM('requested','ordered','received','closed','cancelled') NOT NULL DEFAULT 'requested',
  cancel_reason VARCHAR(500) NULL,
  requested_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_request_job (job_id, status),
  KEY ix_request_placement (placement_id),
  FOREIGN KEY (job_id) REFERENCES jobs(id),
  FOREIGN KEY (unit_id) REFERENCES procurement_units(id),
  FOREIGN KEY (hotel_id) REFERENCES hotels(id),
  FOREIGN KEY (placement_id) REFERENCES placements(id),
  FOREIGN KEY (requested_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A price somebody quoted. Optional, attached to a request (R43).
CREATE TABLE IF NOT EXISTS purchase_quotations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  vendor_name VARCHAR(190) NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  note VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_quote_request (request_id),
  FOREIGN KEY (request_id) REFERENCES purchase_requests(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The order. Placed only once the budget owner approves it (R44).
CREATE TABLE IF NOT EXISTS purchase_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(40) NOT NULL,
  job_id INT UNSIGNED NOT NULL,
  request_id INT UNSIGNED NOT NULL,
  vendor_name VARCHAR(190) NOT NULL,
  hotel_id INT UNSIGNED NULL,
  quantity DECIMAL(10,2) NOT NULL,
  unit_id INT UNSIGNED NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  total DECIMAL(12,2) NOT NULL,
  status ENUM('awaiting_approval','approved','rejected','closed','cancelled') NOT NULL DEFAULT 'awaiting_approval',
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(500) NULL,
  UNIQUE KEY uq_po_reference (reference),
  KEY ix_po_request (request_id),
  KEY ix_po_job (job_id, status),
  FOREIGN KEY (job_id) REFERENCES jobs(id),
  FOREIGN KEY (request_id) REFERENCES purchase_requests(id),
  FOREIGN KEY (unit_id) REFERENCES procurement_units(id),
  FOREIGN KEY (hotel_id) REFERENCES hotels(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (decided_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- What arrived (R45). For lodging this is the rooms the hotel confirmed,
-- and it is what the hotel board counts down.
CREATE TABLE IF NOT EXISTS purchase_receipts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(10,2) NOT NULL,
  received_on DATE NOT NULL,
  note VARCHAR(500) NULL,
  received_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_receipt_po (purchase_order_id),
  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id),
  FOREIGN KEY (received_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
