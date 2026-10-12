-- P5-M05: recognition and engagement. Peer thanks, award programmes with
-- nominations, points and a configurable rewards catalogue, feedback given
-- and requested, and engagement surveys.
--
-- Nothing here is a second HR register. An award won by a worker is written
-- into recognitions (P2-M06) by its own function. A cash reward is paid as
-- a payroll adjustment in an open pay period, and a time-off reward adds
-- days to the worker's leave allowance for the year. Performance reviews,
-- goals and disciplinary cases stay where they are.
--
-- Links into older modules (recognitions, payroll_adjustments, leave_types)
-- are ids checked by the application, not foreign keys, so the rollback of
-- those modules is not blocked by these tables.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p5-m05.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- A thank-you from one person to another. Public ones appear on the wall.
CREATE TABLE IF NOT EXISTS kudos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  from_user_id INT UNSIGNED NOT NULL,
  to_user_id INT UNSIGNED NOT NULL,
  message VARCHAR(500) NOT NULL,
  is_public TINYINT(1) NOT NULL DEFAULT 1,
  points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_kudos_to (to_user_id, id),
  KEY ix_kudos_from (from_user_id, created_at),
  FOREIGN KEY (from_user_id) REFERENCES users(id),
  FOREIGN KEY (to_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- What points can be exchanged for. A cash reward is paid through payroll,
-- a time-off reward adds days of a leave type, so both are for workers.
CREATE TABLE IF NOT EXISTS reward_catalog (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  description VARCHAR(1000) NULL,
  kind ENUM('gift_card','merchandise','experience','time_off','cash','other') NOT NULL,
  points_cost INT UNSIGNED NOT NULL,
  cash_amount DECIMAL(10,2) NULL,
  leave_type VARCHAR(40) NULL,
  leave_days TINYINT UNSIGNED NULL,
  eligible ENUM('everyone','staff','workers') NOT NULL DEFAULT 'everyone',
  stock INT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- An award given once or every month, quarter or year.
CREATE TABLE IF NOT EXISTS award_programs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  description VARCHAR(1000) NULL,
  period ENUM('once','month','quarter','year') NOT NULL DEFAULT 'month',
  eligible ENUM('everyone','staff','workers') NOT NULL DEFAULT 'everyone',
  nominated_by ENUM('staff','everyone') NOT NULL DEFAULT 'everyone',
  points INT UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One person's nomination of another, for one period of a programme.
-- recognition_id is the line written into recognitions when a worker wins.
CREATE TABLE IF NOT EXISTS award_nominations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  program_id INT UNSIGNED NOT NULL,
  period_key VARCHAR(10) NOT NULL,
  nominee_user_id INT UNSIGNED NOT NULL,
  nominated_by INT UNSIGNED NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  status ENUM('nominated','won','not_selected','withdrawn') NOT NULL DEFAULT 'nominated',
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  recognition_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_nomination (program_id, period_key, nominee_user_id, nominated_by),
  KEY ix_nomination_period (program_id, period_key, status),
  FOREIGN KEY (program_id) REFERENCES award_programs(id),
  FOREIGN KEY (nominee_user_id) REFERENCES users(id),
  FOREIGN KEY (nominated_by) REFERENCES users(id),
  FOREIGN KEY (decided_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Every point earned or spent. A balance is the sum, never a stored figure.
CREATE TABLE IF NOT EXISTS reward_points (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  points INT NOT NULL,
  source ENUM('award','kudos','grant','redemption','refund') NOT NULL,
  source_id INT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_points_user (user_id, id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A reward asked for. What it is worth is copied when it is asked for, so
-- a later change to the catalogue does not rewrite it.
CREATE TABLE IF NOT EXISTS reward_redemptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  reward_id INT UNSIGNED NOT NULL,
  points INT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  cash_amount DECIMAL(10,2) NULL,
  leave_type VARCHAR(40) NULL,
  leave_days TINYINT UNSIGNED NULL,
  status ENUM('requested','approved','declined','fulfilled','cancelled') NOT NULL DEFAULT 'requested',
  note VARCHAR(500) NULL,
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(500) NULL,
  fulfilled_by INT UNSIGNED NULL,
  fulfilled_at DATETIME NULL,
  fulfil_note VARCHAR(500) NULL,
  payroll_adjustment_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_redemption_status (status, id),
  KEY ix_redemption_user (user_id, id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (reward_id) REFERENCES reward_catalog(id),
  FOREIGN KEY (decided_by) REFERENCES users(id),
  FOREIGN KEY (fulfilled_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Feedback asked of chosen colleagues about one person.
CREATE TABLE IF NOT EXISTS feedback_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  about_user_id INT UNSIGNED NOT NULL,
  asked_by INT UNSIGNED NOT NULL,
  question VARCHAR(500) NOT NULL,
  due_on DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_feedback_request_about (about_user_id),
  FOREIGN KEY (about_user_id) REFERENCES users(id),
  FOREIGN KEY (asked_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS feedback_request_people (
  request_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  answered_at DATETIME NULL,
  PRIMARY KEY (request_id, user_id),
  KEY ix_feedback_asked (user_id, answered_at),
  FOREIGN KEY (request_id) REFERENCES feedback_requests(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Feedback given. Not shared: only the HR desk (and a worker's supervisor)
-- read it, never the person it is about.
CREATE TABLE IF NOT EXISTS feedback_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  about_user_id INT UNSIGNED NOT NULL,
  from_user_id INT UNSIGNED NOT NULL,
  request_id INT UNSIGNED NULL,
  kind ENUM('praise','suggestion','concern') NOT NULL,
  body VARCHAR(2000) NOT NULL,
  is_shared TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_feedback_about (about_user_id, id),
  KEY ix_feedback_from (from_user_id, id),
  FOREIGN KEY (about_user_id) REFERENCES users(id),
  FOREIGN KEY (from_user_id) REFERENCES users(id),
  FOREIGN KEY (request_id) REFERENCES feedback_requests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- An engagement survey, sent to an audience when it is opened.
CREATE TABLE IF NOT EXISTS surveys (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(190) NOT NULL,
  intro VARCHAR(1000) NULL,
  audience ENUM('everyone','staff','workers','role','project') NOT NULL DEFAULT 'everyone',
  audience_role VARCHAR(40) NULL,
  job_id INT UNSIGNED NULL,
  is_anonymous TINYINT(1) NOT NULL DEFAULT 1,
  min_group TINYINT UNSIGNED NOT NULL DEFAULT 5,
  closes_on DATE NULL,
  status ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
  opened_at DATETIME NULL,
  closed_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (job_id) REFERENCES jobs(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS survey_questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  survey_id INT UNSIGNED NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  kind ENUM('scale','enps','choice','text') NOT NULL,
  prompt VARCHAR(500) NOT NULL,
  options_json TEXT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  KEY ix_question_survey (survey_id, sort_order),
  FOREIGN KEY (survey_id) REFERENCES surveys(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Who was asked, and on which day they answered. Never what they answered.
CREATE TABLE IF NOT EXISTS survey_invitations (
  survey_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  invited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_on DATE NULL,
  PRIMARY KEY (survey_id, user_id),
  KEY ix_invitation_user (user_id),
  FOREIGN KEY (survey_id) REFERENCES surveys(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One set of answers. Its id is random and it carries no time, so in an
-- anonymous survey neither its order nor a clock ties it to an invitation.
-- user_id is kept only when the survey is not anonymous.
CREATE TABLE IF NOT EXISTS survey_responses (
  id CHAR(32) NOT NULL PRIMARY KEY,
  survey_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  segment VARCHAR(20) NOT NULL,
  KEY ix_response_survey (survey_id),
  FOREIGN KEY (survey_id) REFERENCES surveys(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS survey_answers (
  response_id CHAR(32) NOT NULL,
  question_id INT UNSIGNED NOT NULL,
  score TINYINT UNSIGNED NULL,
  choice_index TINYINT UNSIGNED NULL,
  answer_text VARCHAR(2000) NULL,
  PRIMARY KEY (response_id, question_id),
  KEY ix_answer_question (question_id),
  FOREIGN KEY (response_id) REFERENCES survey_responses(id),
  FOREIGN KEY (question_id) REFERENCES survey_questions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
