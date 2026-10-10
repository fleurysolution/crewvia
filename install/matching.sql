-- P3-M08: receiving, three-way matching of order, receipt and bill,
-- purchasing exceptions, and payment readiness.
--
-- A bill against an order is matched to that order and to what was
-- accepted on receipt. What does not match is an exception. A bill is
-- ready to pay once approved and matched, or once an administrator has
-- cleared its exceptions, with a reason, for exactly those exceptions.
-- Nothing is paid that is not ready.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p3-m08.sql.
-- The columns added to purchase_receipts and vendor_invoices are added by
-- upgrade.php. No comment line in this file may end in a semicolon:
-- upgrade.php splits statements there.

CREATE TABLE IF NOT EXISTS bill_match_clearances (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  codes VARCHAR(200) NOT NULL,
  reason VARCHAR(500) NOT NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_clearance_invoice (invoice_id, id),
  FOREIGN KEY (invoice_id) REFERENCES vendor_invoices(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
