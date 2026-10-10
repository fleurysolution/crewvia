-- P2-M02: performance appraisals. Templates built from the criteria the
-- end-of-assignment review already uses, weighted, on a scale the template
-- sets, with grade thresholds. An appraisal moves from the worker's own
-- view, to the supervisor's scores, to approval by somebody else, and every
-- step is kept.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p2-m02.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- A form. Once an appraisal uses it, its criteria and weights are fixed:
-- a new version is a new template, the old one is retired.
CREATE TABLE IF NOT EXISTS appraisal_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  label VARCHAR(120) NOT NULL,
  kind ENUM('end_of_assignment','periodic','probation') NOT NULL DEFAULT 'end_of_assignment',
  scale_max TINYINT UNSIGNED NOT NULL DEFAULT 5,
  self_review TINYINT(1) NOT NULL DEFAULT 1,
  grade_a DECIMAL(5,2) NOT NULL DEFAULT 90,
  grade_b DECIMAL(5,2) NOT NULL DEFAULT 80,
  grade_c DECIMAL(5,2) NOT NULL DEFAULT 70,
  grade_d DECIMAL(5,2) NOT NULL DEFAULT 60,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_appraisal_template (code),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Which criteria a template scores, and how much each one counts.
CREATE TABLE IF NOT EXISTS appraisal_template_criteria (
  template_id INT UNSIGNED NOT NULL,
  criterion_slug VARCHAR(40) NOT NULL,
  weight TINYINT UNSIGNED NOT NULL DEFAULT 1,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (template_id, criterion_slug),
  FOREIGN KEY (template_id) REFERENCES appraisal_templates(id),
  FOREIGN KEY (criterion_slug) REFERENCES assignment_review_criteria(slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One appraisal of one person on one assignment. The score and grade are
-- written when the supervisor submits and frozen at approval.
CREATE TABLE IF NOT EXISTS appraisals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id INT UNSIGNED NOT NULL,
  placement_id INT UNSIGNED NOT NULL,
  candidate_id INT UNSIGNED NOT NULL,
  period_from DATE NULL,
  period_to DATE NULL,
  status ENUM('self_review','supervisor_review','awaiting_approval','approved','cancelled') NOT NULL,
  reviewer_id INT UNSIGNED NULL,
  self_comment VARCHAR(2000) NULL,
  self_submitted_at DATETIME NULL,
  supervisor_comment VARCHAR(2000) NULL,
  would_rehire TINYINT(1) NULL,
  supervisor_by INT UNSIGNED NULL,
  supervisor_submitted_at DATETIME NULL,
  score_percent DECIMAL(5,2) NULL,
  grade CHAR(1) NULL,
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(1000) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_appraisal_candidate (candidate_id, status),
  KEY ix_appraisal_placement (placement_id),
  KEY ix_appraisal_status (status),
  FOREIGN KEY (template_id) REFERENCES appraisal_templates(id),
  FOREIGN KEY (placement_id) REFERENCES placements(id),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (reviewer_id) REFERENCES users(id),
  FOREIGN KEY (supervisor_by) REFERENCES users(id),
  FOREIGN KEY (decided_by) REFERENCES users(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Each criterion's score, from the worker (self) and from the supervisor.
-- Only the supervisor's scores count toward the grade.
CREATE TABLE IF NOT EXISTS appraisal_scores (
  appraisal_id INT UNSIGNED NOT NULL,
  criterion_slug VARCHAR(40) NOT NULL,
  rater ENUM('self','supervisor') NOT NULL,
  score TINYINT UNSIGNED NOT NULL,
  comment VARCHAR(500) NULL,
  PRIMARY KEY (appraisal_id, criterion_slug, rater),
  FOREIGN KEY (appraisal_id) REFERENCES appraisals(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Every step: opened, self-review submitted, scored, returned, approved,
-- cancelled, with who and why.
CREATE TABLE IF NOT EXISTS appraisal_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  appraisal_id INT UNSIGNED NOT NULL,
  event VARCHAR(40) NOT NULL,
  detail VARCHAR(1000) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_appraisal_event (appraisal_id, id),
  FOREIGN KEY (appraisal_id) REFERENCES appraisals(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
