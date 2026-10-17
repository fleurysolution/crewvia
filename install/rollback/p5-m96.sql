-- Rollback for P5-M96 (screenings sent and received from Hiring).
UPDATE screening_cases SET status = 'scheduled' WHERE status = 'sent';
UPDATE screening_cases SET status = 'completed' WHERE status = 'needs_review';
ALTER TABLE screening_cases MODIFY status ENUM('pending','scheduled','completed','cleared','not_cleared','cancelled') NOT NULL DEFAULT 'pending',
  DROP COLUMN sent_at, DROP COLUMN sent_by, DROP COLUMN provider_ref;
