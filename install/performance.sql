-- P2-M03: evaluation cycles, performance goals with their progress, and
-- development plans with follow-up actions. Built on the appraisals of
-- P2-M02.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p2-m03.sql.
-- appraisals.cycle_id is added by upgrade.php.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- A round of reviews: one template, one period, every assignment in scope.
CREATE TABLE IF NOT EXISTS appraisal_cycles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  template_id INT UNSIGNED NOT NULL,
  job_id INT UNSIGNED NULL,
  period_from DATE NOT NULL,
  period_to DATE NOT NULL,
  due_on DATE NOT NULL,
  status ENUM('planned','open','closed') NOT NULL DEFAULT 'planned',
  close_note VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  opened_at DATETIME NULL,
  closed_at DATETIME NULL,
  FOREIGN KEY (template_id) REFERENCES appraisal_templates(id),
  FOREIGN KEY (job_id) REFERENCES jobs(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Something the person is to achieve, how it is measured, by when.
CREATE TABLE IF NOT EXISTS performance_goals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NOT NULL,
  appraisal_id INT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  measure VARCHAR(500) NOT NULL,
  target_on DATE NOT NULL,
  progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('open','achieved','missed','dropped') NOT NULL DEFAULT 'open',
  close_note VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_by INT UNSIGNED NULL,
  closed_at DATETIME NULL,
  KEY ix_goal_candidate (candidate_id, status),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (appraisal_id) REFERENCES appraisals(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (closed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The progress record: every update to a goal, by whom, and whether it was
-- the worker's own account.
CREATE TABLE IF NOT EXISTS goal_updates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  goal_id INT UNSIGNED NOT NULL,
  progress TINYINT UNSIGNED NOT NULL,
  note VARCHAR(1000) NOT NULL,
  by_self TINYINT(1) NOT NULL DEFAULT 0,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_goal_update (goal_id, id),
  FOREIGN KEY (goal_id) REFERENCES performance_goals(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The development plan: training, coaching or a certification, owned by
-- whoever follows it up, due on a date.
CREATE TABLE IF NOT EXISTS development_actions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NOT NULL,
  appraisal_id INT UNSIGNED NULL,
  kind ENUM('training','coaching','certification','other') NOT NULL,
  description VARCHAR(500) NOT NULL,
  owner_id INT UNSIGNED NOT NULL,
  due_on DATE NOT NULL,
  status ENUM('open','done','cancelled') NOT NULL DEFAULT 'open',
  outcome VARCHAR(1000) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_by INT UNSIGNED NULL,
  closed_at DATETIME NULL,
  KEY ix_action_candidate (candidate_id, status),
  KEY ix_action_owner (owner_id, status, due_on),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  FOREIGN KEY (appraisal_id) REFERENCES appraisals(id),
  FOREIGN KEY (owner_id) REFERENCES users(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (closed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
