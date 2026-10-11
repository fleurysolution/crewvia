-- P5-M02: planning the work of a project - sprints, dependencies between
-- tasks, and how many hours a week each person has.
--
-- The tasks themselves are P5-M01's project_tasks. This module adds to them
-- (by upgrade.php, guarded, since a column cannot be added conditionally
-- here): parent_id for a subtask, estimate_hours, and sprint_id.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p5-m02.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- A project's sprints, if it uses them. Planned, then active (one at a
-- time per project), then closed.
CREATE TABLE IF NOT EXISTS project_sprints (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  goal VARCHAR(500) NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  status ENUM('planned','active','closed') NOT NULL DEFAULT 'planned',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  -- How many open tasks went back to the backlog when it closed.
  returned_count SMALLINT UNSIGNED NULL,
  KEY ix_sprint_job (job_id, status),
  FOREIGN KEY (job_id) REFERENCES jobs(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A task that waits for another task of the same project.
CREATE TABLE IF NOT EXISTS project_task_dependencies (
  task_id INT UNSIGNED NOT NULL,
  depends_on_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (task_id, depends_on_id),
  KEY ix_dependency_on (depends_on_id),
  FOREIGN KEY (task_id) REFERENCES project_tasks(id),
  FOREIGN KEY (depends_on_id) REFERENCES project_tasks(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Hours a week a person can give to tasks. No row means the default, 40.
CREATE TABLE IF NOT EXISTS user_capacity (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  hours_per_week DECIMAL(4,1) NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
