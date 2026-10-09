-- Who is actually standing on the site today.
--
-- Attendance records hours, and hours are payroll: writing a presence into
-- them as "zero hours" would put a fiction into somebody's pay. Presence is
-- its own question, asked and answered once a day by whoever is at the gate,
-- and it is what the deployment view reports.
--
-- One row per person per day: marking twice corrects the mark rather than
-- stacking a second one, which is what the unique key enforces.

CREATE TABLE IF NOT EXISTS site_checkins (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  placement_id INT UNSIGNED NOT NULL,
  work_date    DATE NOT NULL,
  present      TINYINT(1) NOT NULL DEFAULT 1,
  note         VARCHAR(255) NULL,
  marked_by    INT UNSIGNED NULL,
  marked_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_checkin_day (placement_id, work_date),
  KEY ix_checkin_date (work_date),
  FOREIGN KEY (placement_id) REFERENCES placements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
