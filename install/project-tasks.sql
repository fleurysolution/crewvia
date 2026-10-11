-- P5-M01: work inside a project - milestones, the tasks that reach them, and
-- every change each task went through.
--
-- A project is a row of jobs. A task belongs to one project and, optionally,
-- to one of that project's milestones. It is assigned to a person who can
-- sign in: a member of staff, or a worker placed on that same project.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p5-m01.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

CREATE TABLE IF NOT EXISTS project_milestones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  due_on DATE NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cancelled_at DATETIME NULL,
  cancelled_by INT UNSIGNED NULL,
  KEY ix_milestone_job (job_id, due_on),
  FOREIGN KEY (job_id) REFERENCES jobs(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (cancelled_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Done and cancelled are closed. Blocked carries the reason it is blocked.
CREATE TABLE IF NOT EXISTS project_tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  milestone_id INT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  assignee_id INT UNSIGNED NULL,
  status ENUM('todo','in_progress','blocked','done','cancelled') NOT NULL DEFAULT 'todo',
  priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  due_on DATE NULL,
  blocked_reason VARCHAR(500) NULL,
  completed_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_task_job (job_id, status),
  KEY ix_task_assignee (assignee_id, status),
  KEY ix_task_milestone (milestone_id),
  FOREIGN KEY (job_id) REFERENCES jobs(id),
  FOREIGN KEY (milestone_id) REFERENCES project_milestones(id),
  FOREIGN KEY (assignee_id) REFERENCES users(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The task's history: one row per change, old and new value, who and when.
-- Written by the application only, never changed or deleted by it.
CREATE TABLE IF NOT EXISTS project_task_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  kind VARCHAR(30) NOT NULL,
  old_value VARCHAR(255) NULL,
  new_value VARCHAR(255) NULL,
  note VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_task_event (task_id, id),
  FOREIGN KEY (task_id) REFERENCES project_tasks(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
