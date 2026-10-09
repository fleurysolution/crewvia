-- Two things carried over from the Fleury Solutions HR system.
--
-- The concepts are taken from it; the tables are not. In the HR system
-- dates and money are VARCHAR, the collation is latin1, and nothing has
-- a foreign key - so a loan can point at an employee who no longer
-- exists, and a repayment date of "next friday" is a valid value. That
-- is not worth carrying across.
--
-- 1. END-OF-ASSIGNMENT REVIEW
--
--    RSS asked for it in their own words: "we need to have that process
--    to know when they're on a job, when their assignment ends, and even
--    if a letter grade or some kind of information, because we don't
--    always get that." And what it is for: "this guy's been with us five
--    times, five different jobs, completed every job. He's a good guy.
--    I'm going to call him."
--
-- 2. ADVANCES AGAINST WAGES
--
--    From the HR system's loan and salary-advance modules. Real for this
--    workforce: somebody flies in on Monday and needs money before the
--    first cheque. Repaid in instalments out of later weeks.

-- ── what an assignment is scored on ─────────────────────────────────────
-- A catalogue, like trades and skills, so a supervisor's form can change
-- without a schema change. Retired rather than deleted: past reviews must
-- keep meaning what they meant.
CREATE TABLE IF NOT EXISTS assignment_review_criteria (
  slug       VARCHAR(40) NOT NULL PRIMARY KEY,
  label      VARCHAR(90) NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_criterion_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO assignment_review_criteria (slug, label, sort_order) VALUES
  ('workmanship',   'Quality of work',            1),
  ('timekeeping',   'Turned up, and on time',     2),
  ('safety',        'Worked safely',              3),
  ('instructions',  'Followed instructions',      4),
  ('teamwork',      'Got on with the crew',       5);

-- ── the review itself ───────────────────────────────────────────────────
-- One per assignment. The grade is what a recruiter reads at a glance a
-- year later; would_rehire is the question that actually decides whether
-- the telephone rings.
CREATE TABLE IF NOT EXISTS assignment_reviews (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  placement_id  INT UNSIGNED NOT NULL,
  grade         ENUM('A','B','C','D','F') NOT NULL,
  would_rehire  TINYINT(1) NOT NULL DEFAULT 1,
  note          VARCHAR(2000) NULL,
  reviewed_by   INT UNSIGNED NULL,
  reviewed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_review_placement (placement_id),
  FOREIGN KEY (placement_id) REFERENCES placements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS assignment_review_scores (
  review_id      INT UNSIGNED NOT NULL,
  criterion_slug VARCHAR(40) NOT NULL,
  score          TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (review_id, criterion_slug),
  FOREIGN KEY (review_id) REFERENCES assignment_reviews(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── advances against wages ──────────────────────────────────────────────
-- Money handed over before it is earned, repaid out of later weeks. The
-- amount is DECIMAL, not VARCHAR: an advance of "500" and an advance of
-- " 500 " must be the same debt.
CREATE TABLE IF NOT EXISTS wage_advances (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id     INT UNSIGNED NOT NULL,
  job_id           INT UNSIGNED NULL,
  amount           DECIMAL(10,2) NOT NULL,
  weekly_repayment DECIMAL(10,2) NOT NULL,
  reason           VARCHAR(500) NULL,

  -- requested  somebody asked
  -- approved   agreed, not yet handed over
  -- paid_out   the money is with them, repayment running
  -- cleared    repaid in full
  -- cancelled  never happened
  status           ENUM('requested','approved','paid_out','cleared','cancelled')
                   NOT NULL DEFAULT 'requested',

  requested_by     INT UNSIGNED NULL,
  approved_by      INT UNSIGNED NULL,
  approved_at      DATETIME NULL,
  paid_out_on      DATE NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_advance_person (candidate_id, status),
  KEY ix_advance_job (job_id),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS wage_advance_payments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  advance_id  INT UNSIGNED NOT NULL,
  amount      DECIMAL(10,2) NOT NULL,
  paid_on     DATE NOT NULL,
  note        VARCHAR(255) NULL,
  recorded_by INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_payment_advance (advance_id, paid_on),
  FOREIGN KEY (advance_id) REFERENCES wage_advances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
