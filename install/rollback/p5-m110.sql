-- P5-M110 rollback: reimbursements paid on the pay.
-- Run only before any claim was paid on the pay or filed as baggage (check first:
--   SELECT COUNT(*) FROM expense_claims WHERE payment_method = 'payroll' OR category = 'baggage';  -- must be 0).
DROP TRIGGER IF EXISTS expense_claims_paid_one_way;
DROP TRIGGER IF EXISTS payroll_adjustments_claim_approved;
ALTER TABLE expense_claims MODIFY category ENUM('flight','hotel','screening','transport','other') NOT NULL,
  MODIFY payment_method ENUM('direct_deposit','check','cash') NULL;
ALTER TABLE payroll_adjustments DROP INDEX uq_adjustment_claim, DROP COLUMN expense_claim_id;
