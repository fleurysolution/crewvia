-- T20 (P5-M15): weekly hotel folio reconciliation. A hotel's folio for one
-- project and one week, its lines (rooms, taxes, incidentals) checked
-- against the bookings, and the bill it becomes in Payables.
-- Reversed by install/rollback/p5-m15.sql.

CREATE TABLE IF NOT EXISTS hotel_folios (
  id                INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  job_id            INT UNSIGNED  NOT NULL,
  hotel_id          INT UNSIGNED  NOT NULL,
  week_ending       DATE          NOT NULL,
  reference         VARCHAR(190)  NOT NULL,
  due_on            DATE          NULL,
  status            ENUM('open','reconciled') NOT NULL DEFAULT 'open',
  vendor_invoice_id INT UNSIGNED  NULL,
  created_by        INT UNSIGNED  NOT NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reconciled_by     INT UNSIGNED  NULL,
  reconciled_at     DATETIME      NULL,
  UNIQUE KEY uq_folio_week (job_id, hotel_id, week_ending),
  KEY ix_folio_job (job_id, week_ending)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS hotel_folio_lines (
  id            INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
  folio_id      INT UNSIGNED     NOT NULL,
  kind          ENUM('room','tax','incidental') NOT NULL,
  lodging_id    INT UNSIGNED     NULL,
  placement_id  INT UNSIGNED     NULL,
  guest_name    VARCHAR(190)     NULL,
  room_number   VARCHAR(30)      NULL,
  nights        TINYINT UNSIGNED NULL,
  rate          DECIMAL(10,2)    NULL,
  amount        DECIMAL(10,2)    NOT NULL DEFAULT 0,
  description   VARCHAR(255)     NULL,
  charge_to     ENUM('agency','client','worker') NULL,
  accepted_note VARCHAR(500)     NULL,
  created_by    INT UNSIGNED     NOT NULL,
  created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_line_folio (folio_id),
  KEY ix_line_placement (placement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
