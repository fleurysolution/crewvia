-- P3-M02: the chart-of-accounts mapping and the QuickBooks export.
-- Crewvia keeps no ledger. It turns the records it already holds into
-- journal entries for QuickBooks Online, in batches, each source exported
-- once. A batch is never edited: it is reversed by a correcting batch.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p3-m02.sql.
-- client_invoices.issued_at and paid_at are added by upgrade.php.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- Each Crewvia account and the QuickBooks account it posts to. Nothing is
-- exported to an account an administrator has not confirmed.
CREATE TABLE IF NOT EXISTS accounting_accounts (
  account_key VARCHAR(40) NOT NULL PRIMARY KEY,
  label VARCHAR(120) NOT NULL,
  side ENUM('asset','liability','income','expense') NOT NULL,
  qb_account VARCHAR(190) NOT NULL,
  confirmed TINYINT(1) NOT NULL DEFAULT 0,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One export, or the reversal of one.
CREATE TABLE IF NOT EXISTS accounting_batches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('export','reversal') NOT NULL,
  through_date DATE NOT NULL,
  status ENUM('exported','reversed') NOT NULL DEFAULT 'exported',
  reverses_batch_id INT UNSIGNED NULL,
  journal_count INT UNSIGNED NOT NULL,
  total DECIMAL(14,2) NOT NULL,
  file_sha256 CHAR(64) NOT NULL,
  reason VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reversed_by_batch_id INT UNSIGNED NULL,
  KEY ix_batch_status (status),
  FOREIGN KEY (reverses_batch_id) REFERENCES accounting_batches(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The lines of a batch, exactly as the file carries them. The QuickBooks
-- account is copied in, so a later change of mapping never rewrites what
-- was sent.
CREATE TABLE IF NOT EXISTS accounting_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id INT UNSIGNED NOT NULL,
  journal_no VARCHAR(40) NOT NULL,
  txn_date DATE NOT NULL,
  account_key VARCHAR(40) NOT NULL,
  qb_account VARCHAR(190) NOT NULL,
  debit DECIMAL(14,2) NOT NULL DEFAULT 0,
  credit DECIMAL(14,2) NOT NULL DEFAULT 0,
  description VARCHAR(500) NULL,
  party VARCHAR(190) NULL,
  class VARCHAR(190) NULL,
  source VARCHAR(80) NULL,
  KEY ix_line_batch (batch_id, id),
  FOREIGN KEY (batch_id) REFERENCES accounting_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Which record each batch exported. active_key is unique while the batch
-- stands, so the same record cannot go out twice. A reversal empties it,
-- and the record can be exported again.
CREATE TABLE IF NOT EXISTS accounting_sources (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id INT UNSIGNED NOT NULL,
  source VARCHAR(80) NOT NULL,
  active_key VARCHAR(80) NULL,
  released_at DATETIME NULL,
  UNIQUE KEY uq_source_active (active_key),
  KEY ix_source (source),
  FOREIGN KEY (batch_id) REFERENCES accounting_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
