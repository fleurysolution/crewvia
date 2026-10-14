-- P5-M30: the worker's travel details, the advice for travellers, and email to workers.

-- What the coordinator needs to book: the name as on the ID, the home
-- address (sealed with the workspace key), the airport they fly from.
CREATE TABLE IF NOT EXISTS travel_profiles (
  candidate_id      INT UNSIGNED PRIMARY KEY,
  legal_name        VARCHAR(190) NOT NULL,
  encrypted_address TEXT         NOT NULL,
  airport           VARCHAR(120) NOT NULL,
  notes             VARCHAR(500) NULL,
  updated_by        INT UNSIGNED NULL,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Written by the operations VP or the project's manager; a project's own
-- advice replaces the agency's (job_id NULL).
CREATE TABLE IF NOT EXISTS travel_advisories (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id     INT UNSIGNED NULL,
  body       VARCHAR(300) NOT NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_advice_job (job_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Email to a worker (an itinerary, a notice), sealed until it is sent by
-- install/deliver-mail.php.
CREATE TABLE IF NOT EXISTS mail_outbox (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id      INT UNSIGNED NULL,
  purpose           VARCHAR(40)  NOT NULL,
  recipient         VARCHAR(190) NOT NULL,
  encrypted_message LONGTEXT     NOT NULL,
  status            ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts          INT UNSIGNED NOT NULL DEFAULT 0,
  lease_until       DATETIME     NULL,
  sent_at           DATETIME     NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_mail_queue (status, lease_until),
  KEY ix_mail_person (candidate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
