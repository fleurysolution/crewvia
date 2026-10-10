-- P3-M07: requests for quotation, supplier quotations, order revisions and
-- their authorization. No bid analysis (R43): quotations are recorded and
-- listed as they come, never ranked or compared, and any one of them can
-- become the order.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p3-m07.sql.
-- The columns added to purchase_quotations, purchase_orders and
-- purchase_order_approvals are added by upgrade.php.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- One request for quotation, to one vendor, for one request.
CREATE TABLE IF NOT EXISTS purchase_rfqs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(40) NOT NULL,
  request_id INT UNSIGNED NOT NULL,
  vendor_name VARCHAR(190) NOT NULL,
  reply_by DATE NOT NULL,
  status ENUM('open','answered','declined','withdrawn') NOT NULL DEFAULT 'open',
  status_note VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  UNIQUE KEY uq_rfq_reference (reference),
  KEY ix_rfq_request (request_id),
  FOREIGN KEY (request_id) REFERENCES purchase_requests(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Each change to an order, with the order as it was and as it became.
CREATE TABLE IF NOT EXISTS purchase_order_revisions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id INT UNSIGNED NOT NULL,
  revision SMALLINT UNSIGNED NOT NULL,
  before_json TEXT NOT NULL,
  after_json TEXT NOT NULL,
  reason VARCHAR(500) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_po_revision (purchase_order_id, revision),
  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
