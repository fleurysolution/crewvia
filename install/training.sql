-- P5-M04: training - agency courses with an optional assessment, plans
-- that sequence them, requirements by desk role, crew trade or project,
-- each person's assignments and every attempt.
--
-- Built beside Phase 1's project briefings (learning_content), which stay
-- as they are. A course passed that awards a qualification writes it into
-- candidate_qualifications, so certifications are tracked, verified and
-- renewed in one place, as before.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p5-m04.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- A course. valid_months: how long a completion counts before a refresher
-- (empty: for good). awards_type_id: the qualification it certifies.
-- pass_mark: the share of answers needed, when it has questions.
CREATE TABLE IF NOT EXISTS training_courses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(190) NOT NULL,
  description VARCHAR(1000) NULL,
  content MEDIUMTEXT NULL,
  resource_url VARCHAR(500) NULL,
  valid_months SMALLINT UNSIGNED NULL,
  awards_type_id INT UNSIGNED NULL,
  pass_mark TINYINT UNSIGNED NOT NULL DEFAULT 80,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (awards_type_id) REFERENCES qualification_types(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The assessment: questions with their options and the right one. The
-- answer is never sent to the person taking it.
CREATE TABLE IF NOT EXISTS training_questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id INT UNSIGNED NOT NULL,
  question VARCHAR(500) NOT NULL,
  options_json TEXT NOT NULL,
  correct_index TINYINT UNSIGNED NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  KEY ix_question_course (course_id, sort_order),
  FOREIGN KEY (course_id) REFERENCES training_courses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A plan: courses in order, each due some days after the plan is assigned.
CREATE TABLE IF NOT EXISTS training_plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  description VARCHAR(1000) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS training_plan_courses (
  plan_id INT UNSIGNED NOT NULL,
  course_id INT UNSIGNED NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  due_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  PRIMARY KEY (plan_id, course_id),
  FOREIGN KEY (plan_id) REFERENCES training_plans(id),
  FOREIGN KEY (course_id) REFERENCES training_courses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Who must take a course: everyone of a desk role, every worker of a trade
-- (their assignment's trade), or every worker placed on a project.
CREATE TABLE IF NOT EXISTS training_requirements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id INT UNSIGNED NOT NULL,
  scope ENUM('staff_role','trade','project') NOT NULL,
  staff_role VARCHAR(40) NULL,
  trade VARCHAR(190) NULL,
  job_id INT UNSIGNED NULL,
  due_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_requirement_course (course_id),
  FOREIGN KEY (course_id) REFERENCES training_courses(id),
  FOREIGN KEY (job_id) REFERENCES jobs(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A person's assignment to a course: why (a requirement, a plan, by hand),
-- by when, and once done, the score and until when it counts. A refresher
-- is a new row, so every completion is kept.
CREATE TABLE IF NOT EXISTS training_assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  course_id INT UNSIGNED NOT NULL,
  source ENUM('requirement','plan','manual','renewal') NOT NULL,
  requirement_id INT UNSIGNED NULL,
  plan_id INT UNSIGNED NULL,
  due_on DATE NOT NULL,
  assigned_by INT UNSIGNED NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  score TINYINT UNSIGNED NULL,
  expires_on DATE NULL,
  qualification_id INT UNSIGNED NULL,
  KEY ix_assignment_user (user_id, completed_at),
  KEY ix_assignment_course (course_id, user_id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (course_id) REFERENCES training_courses(id),
  FOREIGN KEY (requirement_id) REFERENCES training_requirements(id),
  FOREIGN KEY (plan_id) REFERENCES training_plans(id),
  FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Every attempt at an assessment, passed or not, with what was answered.
CREATE TABLE IF NOT EXISTS training_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  assignment_id INT UNSIGNED NOT NULL,
  score TINYINT UNSIGNED NOT NULL,
  passed TINYINT(1) NOT NULL,
  answers_json TEXT NOT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_attempt_assignment (assignment_id),
  FOREIGN KEY (assignment_id) REFERENCES training_assignments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
