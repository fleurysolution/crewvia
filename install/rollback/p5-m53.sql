-- Rollback for P5-M53 (the office countersigns an offer the candidate signed; only then are they hired).
--
-- READ BEFORE RUNNING
--   * First countersign or reject, in Hiring, every offer marked "Signed: to countersign": the earlier
--     code hires on the candidate's signature and does not know a signature waiting for the office.
--   * Who countersigned and when is dropped with the columns; the application's history keeps it
--     (application_events, "Offer countersigned by ...").
--   * The general conditions typed in Settings stay in platform_settings (offer_conditions), unused.

ALTER TABLE offer_acceptances DROP FOREIGN KEY fk_offer_countersigned_by;
ALTER TABLE offer_acceptances DROP COLUMN countersigned_by, DROP COLUMN countersigned_at, DROP COLUMN countersigner_name;
