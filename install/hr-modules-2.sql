-- The last two concepts carried over from the HR system.
--
-- 1. WHERE THE MONEY GOES
--
--    RSS's onboarding desk collects this on paper today: "she's checking
--    their I-9s, their W-4s, their banking information". Crewvia stored
--    none of it.
--
--    Encrypted at rest with the workspace key, because an account number
--    is the one field here that is worth stealing on its own. The HR
--    system keeps it in clear. Only the last four digits are ever shown,
--    and only the payroll desk can open the record at all.
--
-- 2. WHAT KIND OF LEAVE, AND HOW MUCH OF IT
--
--    time_off_requests.request_type is free text, so "vacation", "Vacation"
--    and "PTO" are three different kinds of leave and nobody can say how
--    many days somebody has left. A catalogue with an allowance fixes
--    both.

CREATE TABLE IF NOT EXISTS worker_bank_details (
  candidate_id   INT UNSIGNED NOT NULL PRIMARY KEY,

  -- The whole record, encrypted as one blob: account number, routing
  -- number, bank name, the name on the account. Encrypted together so a
  -- dump of this table yields nothing readable.
  encrypted_details TEXT NOT NULL,

  -- Shown instead of the number, so a clerk can confirm they have the
  -- right account without the number ever being on screen.
  last_four      CHAR(4) NULL,
  bank_label     VARCHAR(90) NULL,

  -- A worker can propose a change; somebody here has to accept it before
  -- payroll uses it. RSS asked for exactly this: "they will tell the
  -- system and somebody in the company will be the one to go and
  -- validate that".
  status         ENUM('pending_review','verified','rejected')
                 NOT NULL DEFAULT 'pending_review',
  review_note    VARCHAR(500) NULL,
  submitted_by   INT UNSIGNED NULL,
  reviewed_by    INT UNSIGNED NULL,
  reviewed_at    DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_bank_status (status),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Every look at a bank record is recorded. These are the rows somebody
-- would come looking at if money went to the wrong account.
CREATE TABLE IF NOT EXISTS worker_bank_access (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NULL,
  action       VARCHAR(40) NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_bank_access (candidate_id, id),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS leave_types (
  slug          VARCHAR(40) NOT NULL PRIMARY KEY,
  label         VARCHAR(90) NOT NULL,

  -- Days allowed in a year. NULL means unlimited, which is not the same
  -- as zero: unpaid leave has no allowance to run out of.
  days_allowed  SMALLINT UNSIGNED NULL,
  is_paid       TINYINT(1) NOT NULL DEFAULT 1,
  sort_order    SMALLINT NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_leave_type_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO leave_types (slug, label, days_allowed, is_paid, sort_order) VALUES
  ('unpaid',      'Unpaid time off',      NULL, 0, 1),
  ('sick',        'Sick',                    5, 1, 2),
  ('bereavement', 'Bereavement',             3, 1, 3),
  ('jury',        'Jury service',         NULL, 1, 4),
  ('personal',    'Personal',                3, 0, 5);
