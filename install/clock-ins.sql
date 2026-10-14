-- P5-M32: the worker's clock-in, and their supervisor's validation of it.
CREATE TABLE IF NOT EXISTS clock_ins (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  placement_id  INT UNSIGNED NOT NULL,
  work_date     DATE         NOT NULL,
  clocked_in_at DATETIME     NOT NULL,
  shift_start   DATETIME     NULL,
  validate_by   DATETIME     NOT NULL,
  clocked_by    INT UNSIGNED NULL,
  status        ENUM('waiting','validated','not_present') NOT NULL DEFAULT 'waiting',
  decided_by    INT UNSIGNED NULL,
  decided_at    DATETIME     NULL,
  decided_late  TINYINT(1)   NOT NULL DEFAULT 0,
  UNIQUE KEY uq_clock_in_day (placement_id, work_date),
  KEY ix_clock_in_waiting (status, validate_by),
  FOREIGN KEY (placement_id) REFERENCES placements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
