-- P1-M02: correcting an approved day, and the record of every correction.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p1-m02.sql.
-- The two columns this module adds to attendance_records (source, note)
-- are added by upgrade.php behind information_schema guards.

-- An approved day used to be final: a supervisor who approved 8 hours for
-- a 10-hour day had no way back. A correction changes the hours and keeps
-- what they were, who changed them, and why - so nobody can say they were
-- paid short without the record showing it.
CREATE TABLE IF NOT EXISTS attendance_corrections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT UNSIGNED NOT NULL,
  old_hours DECIMAL(5,2) NOT NULL,
  new_hours DECIMAL(5,2) NOT NULL,
  reason VARCHAR(500) NOT NULL,
  corrected_by INT UNSIGNED NOT NULL,
  corrected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_correction_attendance (attendance_id, id),
  FOREIGN KEY (attendance_id) REFERENCES attendance_records(id),
  FOREIGN KEY (corrected_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
