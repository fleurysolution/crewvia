-- The scope of work: what the agreement actually commits both parties to.
--
-- A project is an agreement between two parties with a description - "provide
-- labour support to the site in accordance with the scope of work". The scope
-- is a schedule of lines: twenty engineers, forty mechanical, a hundred
-- labourers. Each line carries its own commercial terms, because an engineer
-- and a labourer are not paid the same and are not billed the same.
--
-- Putting one pay rate and one per diem on the project was wrong. There is no
-- such thing as "the project's rate"; there is a rate per line of the order.

CREATE TABLE IF NOT EXISTS job_order_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,

  -- what the client asked for
  role_title VARCHAR(190) NOT NULL,
  discipline ENUM('mechanical','chemical','electrical','instrumentation','operator','other')
    NOT NULL DEFAULT 'other',
  quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  shift VARCHAR(60) NULL,

  -- what this line is worth, per the agreement
  pay_rate DECIMAL(10,2) NULL,
  bill_rate DECIMAL(10,2) NULL,
  per_diem_rate DECIMAL(8,2) NULL,
  guarantee_hours SMALLINT UNSIGNED NULL,
  strike_guarantee_hours SMALLINT UNSIGNED NULL,
  overtime_after SMALLINT UNSIGNED NULL,
  overtime_multiplier DECIMAL(4,2) NULL,

  notes VARCHAR(500) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY ix_order_line_job (job_id, sort_order),
  FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
