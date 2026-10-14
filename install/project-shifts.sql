-- P5-M27: a plant's shifts, each with its start, its end and when the bus leaves the hotels.
-- A worker's assignment points at one; its label and start time follow it, so lateness,
-- roll call and clock-in read the plant's own hours. Reversed by install/rollback/p5-m27.sql.
CREATE TABLE IF NOT EXISTS project_shifts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  starts_at TIME NOT NULL,
  ends_at TIME NOT NULL,
  bus_departs TIME NULL,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY project_shift_name (job_id, name),
  KEY project_shift_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
