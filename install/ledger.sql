-- P3-M03: Crewvia's own general ledger.
-- Nothing is keyed twice. Each record Crewvia already holds (an issued
-- invoice, a payment, a credit note, an approved bill, a paid claim, an
-- approved payroll period) posts itself here as one balanced journal, and
-- the QuickBooks export (P3-M02) is drawn from these journals. The only
-- journals typed by hand are for what Crewvia never sees (rent, insurance,
-- bank fees, payroll liabilities paid to ADP), and those go to QuickBooks
-- the same way.
--
-- The chart of accounts is accounting_accounts (P3-M02), given numbers, an
-- equity side and accounts an administrator adds. A posted journal is never
-- changed: triggers created by upgrade.php refuse it, and each journal
-- carries the SHA-256 of its content and of the journal before it, so a
-- change made around the triggers shows.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p3-m03.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- One journal. kind source: posted from a record, once (source is unique).
-- kind manual: typed, then approved by someone else. kind reversal: the
-- correcting journal of a manual one. doc_date is the record's own date,
-- posted_on the date it counts in the books: the same, unless the record's
-- month was already closed when it reached the ledger.
CREATE TABLE IF NOT EXISTS gl_journals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('source','manual','reversal') NOT NULL,
  source VARCHAR(80) NULL,
  export_key VARCHAR(80) NULL,
  doc_date DATE NOT NULL,
  posted_on DATE NULL,
  memo VARCHAR(500) NOT NULL,
  status ENUM('draft','posted','discarded') NOT NULL DEFAULT 'draft',
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  reverses_id INT UNSIGNED NULL,
  reversed_by_id INT UNSIGNED NULL,
  reason VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_by INT UNSIGNED NULL,
  posted_at DATETIME NULL,
  chain_no INT UNSIGNED NULL,
  prev_hash CHAR(64) NULL,
  hash CHAR(64) NULL,
  UNIQUE KEY uq_gl_source (source),
  UNIQUE KEY uq_gl_export_key (export_key),
  UNIQUE KEY uq_gl_chain (chain_no),
  KEY ix_gl_posted (status, posted_on),
  FOREIGN KEY (reverses_id) REFERENCES gl_journals(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Its lines. Name and Class are the same as in the QuickBooks file: the
-- client or vendor, and the project.
CREATE TABLE IF NOT EXISTS gl_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  journal_id INT UNSIGNED NOT NULL,
  account_key VARCHAR(40) NOT NULL,
  debit DECIMAL(14,2) NOT NULL DEFAULT 0,
  credit DECIMAL(14,2) NOT NULL DEFAULT 0,
  party VARCHAR(190) NULL,
  class VARCHAR(190) NULL,
  KEY ix_gl_line_journal (journal_id, id),
  KEY ix_gl_line_account (account_key),
  FOREIGN KEY (journal_id) REFERENCES gl_journals(id),
  FOREIGN KEY (account_key) REFERENCES accounting_accounts(account_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
