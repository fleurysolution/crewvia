-- T7 (P5-M18): campaign email. One email about one requisition to a
-- filtered list, its recipients fixed when it is queued, sent a few at a
-- time by install/deliver-campaigns.php. The email opt-out is a column on
-- contact_preferences, added by install/upgrade.php.
-- Reversed by install/rollback/p5-m18.sql.

CREATE TABLE IF NOT EXISTS email_campaigns (
  id             INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  vacancy_id     INT UNSIGNED  NOT NULL,
  language       ENUM('en','es') NOT NULL DEFAULT 'en',
  subject        VARCHAR(190)  NOT NULL,
  body           TEXT          NOT NULL,
  filters_json   TEXT          NOT NULL,
  reply_to       VARCHAR(190)  NULL,
  status         ENUM('draft','awaiting_approval','sending','sent','cancelled') NOT NULL DEFAULT 'draft',
  recipients     INT UNSIGNED  NOT NULL DEFAULT 0,
  created_by     INT UNSIGNED  NOT NULL,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_by    INT UNSIGNED  NULL,
  approved_at    DATETIME      NULL,
  queued_at      DATETIME      NULL,
  finished_at    DATETIME      NULL,
  KEY ix_campaign_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS email_campaign_recipients (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id  INT UNSIGNED NOT NULL,
  candidate_id INT UNSIGNED NOT NULL,
  email        VARCHAR(190) NOT NULL,
  token        CHAR(64)     NOT NULL,
  status       ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  sent_at      DATETIME     NULL,
  error        VARCHAR(255) NULL,
  UNIQUE KEY uq_campaign_person (campaign_id, candidate_id),
  UNIQUE KEY uq_recipient_token (token),
  KEY ix_recipient_queue (status, campaign_id),
  KEY ix_recipient_sent (status, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
