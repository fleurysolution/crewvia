-- T19 (P5-M14): lateness. A project's shift start, grace and rounding, and
-- whether docked time is still billed; each late arrival with its reason,
-- the worker's dispute and payroll's decision. Columns on timesheets and
-- assignment_details are added by install/upgrade.php.
-- Reversed by install/rollback/p5-m14.sql.

CREATE TABLE IF NOT EXISTS lateness_policies (
  job_id            INT UNSIGNED     NOT NULL PRIMARY KEY,
  shift_start       TIME             NULL,
  grace_minutes     TINYINT UNSIGNED NOT NULL DEFAULT 5,
  round_to_minutes  TINYINT UNSIGNED NOT NULL DEFAULT 15,
  bill_docked       TINYINT(1)       NOT NULL DEFAULT 0,
  updated_by        INT UNSIGNED     NULL,
  updated_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS attendance_lateness (
  id              INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
  placement_id    INT UNSIGNED      NOT NULL,
  work_date       DATE              NOT NULL,
  shift_start     TIME              NOT NULL,
  arrived_at      TIME              NOT NULL,
  minutes_late    SMALLINT UNSIGNED NOT NULL,
  docked_minutes  SMALLINT UNSIGNED NOT NULL,
  grace_minutes   TINYINT UNSIGNED  NOT NULL,
  round_to_minutes TINYINT UNSIGNED NOT NULL,
  reason          VARCHAR(500)      NOT NULL,
  status          ENUM('recorded','disputed','upheld','reduced','cancelled') NOT NULL DEFAULT 'recorded',
  dispute_note    VARCHAR(1000)     NULL,
  disputed_at     DATETIME          NULL,
  decision_note   VARCHAR(1000)     NULL,
  decided_by      INT UNSIGNED      NULL,
  decided_at      DATETIME          NULL,
  recorded_by     INT UNSIGNED      NOT NULL,
  recorded_at     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lateness_day (placement_id, work_date),
  KEY ix_lateness_status (status, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
