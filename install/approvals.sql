-- Approval chains, ported from BPMS247 and generalised.
--
-- In BPMS a chain approves one thing: an estimate. A staffing agency needs
-- the same machinery over several: a client order before it is advertised, a
-- person before they are sent to site, a week of hours before it is paid, a
-- reimbursement before it is reimbursed. So the chain names what it applies
-- to, and a request names the record it is about.
--
-- The gating rules are BPMS's, unchanged: a step is either sequential, and
-- waits for every earlier required sequential step, or parallel and can be
-- decided at any time. One rejection ends the whole thing.

CREATE TABLE IF NOT EXISTS approval_chains (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  applies_to ENUM('requisition','placement','timesheet','expense','offer') NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_chain_name (name),
  KEY ix_chain_subject (applies_to, is_active, is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS approval_chain_steps (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chain_id INT UNSIGNED NOT NULL,
  step_order INT NOT NULL DEFAULT 0,
  label VARCHAR(120) NOT NULL,
  role_slug VARCHAR(60) NOT NULL,
  gate_type ENUM('sequential','parallel') NOT NULL DEFAULT 'sequential',
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_step_chain_order (chain_id, step_order),
  KEY ix_step_chain (chain_id),
  FOREIGN KEY (chain_id) REFERENCES approval_chains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One row per step per record under approval. The step's definition is copied
-- in rather than joined: editing a chain next month must not rewrite what was
-- asked of the people who already decided.
CREATE TABLE IF NOT EXISTS approval_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chain_id INT UNSIGNED NOT NULL,
  subject_type ENUM('requisition','placement','timesheet','expense','offer') NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  subject_label VARCHAR(190) NOT NULL,
  job_id INT UNSIGNED NULL,
  step_id INT UNSIGNED NULL,
  step_order INT NOT NULL DEFAULT 0,
  label VARCHAR(120) NOT NULL,
  role_slug VARCHAR(60) NOT NULL,
  gate_type ENUM('sequential','parallel') NOT NULL DEFAULT 'sequential',
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  comments VARCHAR(500) NULL,
  requested_by INT UNSIGNED NULL,
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  notified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_request_subject_step (subject_type, subject_id, step_order),
  KEY ix_request_subject (subject_type, subject_id),
  KEY ix_request_open (status, role_slug),
  KEY ix_request_job (job_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
