-- P3-M04: receivables and payables. Money received from clients and paid
-- to vendors is recorded once, as a payment, and applied to one or more
-- invoices. What is not applied stays on account as a credit. A credit
-- note lowers what an invoice asks. An invoice's balance is what it asks
-- less what was applied and credited, and its status follows the balance.
-- Nothing is deleted: a payment, an application or a credit note is
-- reversed, with a reason, and stays.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p3-m04.sql.
-- clients.payment_terms_days and client_invoices.due_on are added by
-- upgrade.php. No comment line in this file may end in a semicolon:
-- upgrade.php splits statements there.

CREATE TABLE IF NOT EXISTS ar_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  received_on DATE NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  method ENUM('transfer','ach','check','card','cash') NOT NULL,
  reference VARCHAR(190) NOT NULL,
  note VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reversed_at DATETIME NULL,
  reversed_by INT UNSIGNED NULL,
  reversal_reason VARCHAR(500) NULL,
  KEY ix_ar_payment_client (client_id, received_on),
  FOREIGN KEY (client_id) REFERENCES clients(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (reversed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ar_allocations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id INT UNSIGNED NOT NULL,
  invoice_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reversed_at DATETIME NULL,
  reversed_by INT UNSIGNED NULL,
  reversal_reason VARCHAR(500) NULL,
  KEY ix_ar_alloc_invoice (invoice_id),
  KEY ix_ar_alloc_payment (payment_id),
  FOREIGN KEY (payment_id) REFERENCES ar_payments(id),
  FOREIGN KEY (invoice_id) REFERENCES client_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ar_credits (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  issued_on DATE NOT NULL,
  reason VARCHAR(500) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reversed_at DATETIME NULL,
  reversed_by INT UNSIGNED NULL,
  reversal_reason VARCHAR(500) NULL,
  KEY ix_ar_credit_invoice (invoice_id),
  FOREIGN KEY (invoice_id) REFERENCES client_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Vendors are known by name, as their bills already are.
CREATE TABLE IF NOT EXISTS ap_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_name VARCHAR(190) NOT NULL,
  received_on DATE NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  method ENUM('transfer','ach','check','card','cash') NOT NULL,
  reference VARCHAR(190) NOT NULL,
  note VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reversed_at DATETIME NULL,
  reversed_by INT UNSIGNED NULL,
  reversal_reason VARCHAR(500) NULL,
  KEY ix_ap_payment_vendor (vendor_name, received_on),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (reversed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ap_allocations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id INT UNSIGNED NOT NULL,
  invoice_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reversed_at DATETIME NULL,
  reversed_by INT UNSIGNED NULL,
  reversal_reason VARCHAR(500) NULL,
  KEY ix_ap_alloc_invoice (invoice_id),
  KEY ix_ap_alloc_payment (payment_id),
  FOREIGN KEY (payment_id) REFERENCES ap_payments(id),
  FOREIGN KEY (invoice_id) REFERENCES vendor_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ap_credits (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  issued_on DATE NOT NULL,
  reason VARCHAR(500) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reversed_at DATETIME NULL,
  reversed_by INT UNSIGNED NULL,
  reversal_reason VARCHAR(500) NULL,
  KEY ix_ap_credit_invoice (invoice_id),
  FOREIGN KEY (invoice_id) REFERENCES vendor_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
