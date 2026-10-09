# Crewvia — implementation roadmap

Work is done one module at a time. A module stops at its boundary with a
report, and the next one starts only on the owner's approval. Gap IDs refer
to [CREWVIA_HRM_GAP_MATRIX.md](CREWVIA_HRM_GAP_MATRIX.md).

The standing constraints in [../REQUIREMENTS.md](../REQUIREMENTS.md) (C1–C10)
outrank this roadmap. Where the directive and a constraint disagree, the
constraint stands and the disagreement is written down here, not worked
around.

---

## Phase 1 — workforce, attendance, leave, payroll foundations

| Module | Gaps | Depends on | Delivers |
|---|---|---|---|
| **P1-M01** | 101 102 103 104 105 | — | Placement → requisition → line link; effective-dated classification with FLSA status; rate-change history; relationship integrity check |
| P1-M02 | 201 | M01 | Attendance correction with reason, exception detection, weekly aggregation across assignments, attendance ↔ timesheet reconciliation |
| P1-M03 | 301 | M01 | Rule tables for overtime / holiday / premium keyed by jurisdiction and classification; line-level resolution |
| P1-M04 | 401 | M01 M03 | Leave type administration, eligibility, accrual, carryover, balances, feed into attendance and payroll |
| P1-M05 | 501 | M03 M04 | Compensation configuration, deductions (advances first), employer contributions, provider export — **preparation, not withholding** |
| P1-M06 | 601 | M05 | Payroll periods, approval, lock, adjustments, reconciliation, worker payslip, payroll audit |
| P1-M07 | 701 | all | Full regression including tenant isolation, security checks, backup and restore drill |

The order follows the directive, with one dependency made explicit:
**M03 needs M01's classification history**. Overtime eligibility is a
property of the person on the date, and today nothing records what that
property was.

### Directive points that meet a constraint

| Directive | Constraint | Resolution |
|---|---|---|
| "gross-to-net payroll … tax withholding" | C9: ADP keeps tax filing and deposits; Q3 undecided | Gross-to-net *preparation* and export only. No tax table is invented (directive rule 17). |
| "versioned, reversible migrations" | S2: `upgrade.php` guarded, idempotent, loud | Steps stay in `upgrade.php`; each module adds `install/rollback/<module>.sql`, exercised by the test runner (up → down → up). |
| "tenant isolation on every new table" | Isolation is database-per-tenant (`app/tenancy.php`) | New tables carry no `tenant_id`; the tenant runner step proves isolation. |
| "responsive desktop/tablet/mobile" | — | Existing layout and classes reused; no new CSS framework. |

---

## Phase 2 — HR management and self-service

Self-service bank / I-9 / W-4 with staff validation (T12–T14, GAP-801),
the supervisor review path (A1), termination lock (T21), worker
availability check-in (T1).

## Phase 3 — finance, procurement, project costing

Ledger and chart of accounts (GAP-901), procurement chain (GAP-902),
project costing on top of D6 (GAP-903).

## Phase 4 — reporting, integrations, security, production validation

Encryption key rotation tool (GAP-1002, for SEC-0), safe parameterised
reports (not HRM's ad-hoc builder), Q1 login throttle decision, email
queue cron (T24).

## Phase 5 — project management, assets, learning, engagement, mobile

GAP-1101, GAP-1102 (authenticated clock-in, replacing HRM's open API
design), learning and engagement.
