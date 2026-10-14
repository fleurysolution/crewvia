-- P5-M26: what the one-time candidate stage sweep changed, so it can be undone.
-- A candidate's own stage was left at "new" while Hiring moved their
-- application on; the upgrade moves it forward once and records the old one
-- here. Reversed by install/rollback/p5-m26.sql.
CREATE TABLE IF NOT EXISTS candidate_stage_sweep (
  candidate_id INT UNSIGNED NOT NULL PRIMARY KEY,
  old_stage VARCHAR(40) NOT NULL,
  new_stage VARCHAR(40) NOT NULL,
  swept_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
