# Crewvia ↔ HRM gap matrix

Each row has been checked against the code. In the reference columns:

- **HRM** paths are relative to the HRM archive root.
- **Crewvia** paths are relative to the repository root.

**Status values**

| Status | Meaning |
|---|---|
| present | Exists and works as coded. |
| partial | Some of it exists. The gap says what is missing. |
| missing | Not in Crewvia at all. |
| broken | Coded but cannot run. |
| out of scope | Excluded by a standing constraint. |

**Priority values:** H (blocks payroll or a stated requirement), M, L.

HRM is a commercial product with a weak security posture (see the
baseline audit, §6). Every port is of a *concept*. No HRM code or rows are
copied.

---

## Phase 1 — workforce, attendance, leave and payroll foundations

### GAP-101 · Placement provenance — P1-M01
**HRM:** `application/modules/employee/controllers/Employees.php` (`pos_id` on `employee_history`; position held on the person)
**Crewvia:**
- `app/placement-rates.php:28` resolves the rates.
- The three creation sites, `app/pages/candidate.php:327`, `app/contracts.php:29` and `app/pages/portal.php:24`, copy them.

**Status:** partial. The rates are copied correctly. The requisition and the scope line they came from are not recorded (audit A3).
**Missing:** a `placements.vacancy_id` and `placements.order_line_id` link; a backfill where it is unambiguous; and showing the source on the placement.
**Priority:** H, because P1-M03 premium rules must resolve per line.
**Dependencies:** none.
**Files and tables affected:**
- Code: `placements`, `app/placement-rates.php`, the three creation sites, the placement view.
- Migrations: `install/upgrade.php`, `install/rollback/p1-m01.sql`.

**Acceptance:**
- A placement created through any path that knows its requisition stores it, together with that requisition's line.
- The backfill fills a row only when the candidate applied to exactly one requisition on that project.
- The placement page names its source.

**Tests:** `tests/hr_relationships_http.py`.

### GAP-102 · Effective-dated worker classification — P1-M01
**HRM:**
- Fields on `employee_history`: `employee_type`, `duty_type`, `rate_type`, `class_code` and `class_acc_date`. Values are overwritten in place.
- `gmb_employee_types`.

**Crewvia:**
- `employee_profiles.employment_type` (`install/extension.sql:182`).
- Edited at `app/pages/employee-folder.php:36`.
- Read by `hours.php:89-91`, `payroll-export.php:16-28` and `bootstrap.php:375`.

**Status:** partial. Classification exists. Changing it is silent, undated and unexplained (audit A4). There is no overtime-exemption status at all.
**Missing:**
- A history of classification changes, each with an effective date, a reason, who made it and when.
- An FLSA status (non-exempt / exempt / not applicable / not yet determined), which P1-M03 needs.
- A function that answers "classification on date D".

**Priority:** H, because P1-M03 cannot choose an overtime rule without it.
**Dependencies:** none.
**Files and tables affected:**
- New table `employee_classifications`.
- `employee_profiles`, which keeps `employment_type` as the current value for backward compatibility.
- Code: `app/hr.php`, `app/pages/employee-folder.php`, `app/views/employee-folder.php`, `app/pages/imports.php`, the language files.

**Acceptance:**
- A change needs a reason and an effective date no later than today and no earlier than the previous change.
- The current value on `employee_profiles` follows the newest effective row.
- History is shown on the folder.
- Recruiter or payroll may change it. Hotels, workers and supervisors are refused.
- A worker never sees another person's history.
- Existing profiles receive one initial row; nothing is guessed beyond the mapping below.
  - hourly → non-exempt
  - contractor / external → not applicable
  - salaried → not yet determined

**Tests:** HTTP, covering refusal paths and roles; an upgrade idempotence check; a rollback check.

### GAP-103 · Placement rate-change history — P1-M01
**HRM:** none. HRM overwrites `rate` in place.
**Crewvia:** `app/pages/candidate.php:257-297`.
**Status:** partial. Changes are logged as a free-text activity line holding only the new pay rate (audit A5).
**Missing:** a before-and-after record of all four figures (pay, bill, per diem, guarantee), with who and when.
**Priority:** M.
**Dependencies:** none.
**Files and tables affected:** new table `placement_rate_changes`; `candidate.php`; the candidate view.
**Acceptance:**
- Every save that changes a figure writes one row holding the old and new values.
- A save that changes nothing writes no row.
- Approved weeks remain unchanged, because they are snapshotted.

**Tests:** HTTP.

### GAP-104 · Position / trade on an assignment — P1-M01
**HRM:** `position` table (56 rows); `employee_history.pos_id`.
**Crewvia:**
- `assignment_details.trade`.
- Trades catalogue editable (D17).
- `personnel_changes` holds effective-dated promotions and transfers (`app/pages/personnel.php`).

**Status:** present. The scope line is the position, and promotion and transfer history already exists.
**Missing:** nothing new. GAP-101 links the placement to its line. No second position table is created, because a duplicate model is forbidden.
**Priority:** n/a.

### GAP-105 · Relationship integrity check — P1-M01
**HRM:** none, since HRM has no foreign keys.
**Crewvia:** `placements` has no foreign keys to `candidates` or `jobs` (`install/schema.sql:115`).
**Status:** missing.
**Missing:** a read-only report of orphaned rows across the person → placement → week chain. It must run before any foreign key is proposed, because adding a key to a table holding an orphan fails.
**Priority:** M.
**Files and tables affected:** new `install/verify-relationships.php`.
**Acceptance:**
- Prints one line per relationship with a count.
- Exits 0 when clean and 1 when it finds orphans.
- Writes nothing.

**Tests:** run against the isolated test database; an orphan is injected and the script must catch it.

### GAP-106 · Employment dates (hire, termination, rehire) — P1-M01 → deferred to P2
**HRM:** `employee_history.hire_date`, `original_hire_date`, `termination_date`, `termination_reason` and `rehire_date`.
**Crewvia:** placement start and end dates; `employee_profiles.rehire_status`; offboarding (`app/offboarding.php`).
**Status:** partial. For a staffing agency the employment *is* the assignment, and placements already carry its dates.
**Missing:** a lock on termination (R31 / T21), which belongs with P1-M06 / T21.
**Priority:** M.

### GAP-201 · Attendance correction and exceptions — P1-M02
**HRM:**
- `attendance/controllers/Home.php:246-332` (missing attendance).
- `Home.php:968-1016` (lateness against `gmb_setup_rules`).

**Crewvia:**
- `attendance_records` and `/attendance` (daily hours, approved by a supervisor or payroll).
- `assignment_checkins` (roll call).
- `/hours` import.

**Status:** partial. There are two separate attendance concepts plus manual timesheet entry. Nothing corrects an approved day, and nothing detects exceptions (audit A2).
**Missing:**
- Correction with a reason, for an approved day.
- Exceptions: present but no hours, hours but absent, more than 16 hours, a day on two assignments.
- Weekly aggregation across several placements.
- Reconciling attendance against the timesheet.

**Priority:** H.
**Dependencies:** GAP-101.
**Acceptance:** to be written when P1-M02 starts.

### GAP-301 · Shift, overtime, holiday and premium rules — P1-M03
**HRM:** overtime does not exist; `payroll_holiday` and `weekly_holiday` hold holidays.
**Crewvia:**
- `project_pay_policies` (threshold and multiplier per project).
- `payroll_gross()` (`app/payroll-calculation.php:3`).
- `job_order_lines.overtime_after` and `overtime_multiplier`, which are not read by `week_money` (audit A16).

**Status:** partial.
**Missing:**
- Rules resolved line → placement.
- Jurisdiction as data: a rule table keyed by state and classification. No rule is hard-coded.
- Holiday calendar and premium rates.

**Priority:** H.
**Dependencies:** GAP-101, GAP-102.

### GAP-401 · Leave: eligibility, accrual, carryover, balances — P1-M04
**HRM:** `leave/controllers/Leave.php:630-641`. A fixed allowance; no accrual and no carryover.
**Crewvia:**
- `leave_types` and `leave_balance()` (`app/hr.php:273`), counting approved days in the year.
- `/timeoff`.

**Status:** partial. There is no admin screen for types (audit A6). There is no accrual, carryover or eligibility, and leave does not feed payroll.
**Priority:** M.
**Dependencies:** GAP-102.

### GAP-501 · Compensation and gross-to-net preparation — P1-M05
**HRM:** `payroll/controllers/Payroll.php:742-1056`. Its rules are defective (audit §6).
**Crewvia:**
- `week_money()` and `payroll_gross()`.
- `/payroll-export`, which produces an ADP RUN draft.
- `wage_advances`.

**Status:** partial. Gross only. Advances are not deducted (audit A8).
**Missing:**
- Deduction and contribution configuration.
- Preparing the advance repayments.
- Provider export.

**Withholding:** out of scope until a validated provider or expert-approved rules exist (C9, Q3, directive rule 17).
**Priority:** H.
**Dependencies:** GAP-301.

### GAP-601 · Payroll periods, approval, locking, payslips — P1-M06
**HRM:**
- `gmb_salary_sheet_generate` → `gmb_employee_salary_approval` → `save_employee_salary_approval`.
- Payslip at `payroll/views/employee_salary/pay_slip.php`.

**Crewvia:** weekly `timesheets` with `pay_snapshots`, frozen at approval.
**Status:** partial. Weeks are frozen. There is no period or run entity, no adjustments, and no payslip.
**Priority:** H.
**Dependencies:** GAP-501.

### GAP-701 · Regression, security, isolation, backup and restore — P1-M07
**Crewvia:** `tests/run_windows.py`.
**Status:** partial. The browser step is missing, so the tenant isolation step never runs (E1, E2).
**Missing:**
- Running every step after the browser step.
- A backup-and-restore drill on the isolated database.

**Priority:** H.

---

## Phases 2–5 — inventory only

These modules are not yet verified at row level. Each one will be verified
when its phase opens.

| ID | Module | HRM reference | Crewvia today | Status |
|---|---|---|---|---|
| GAP-801 | Employee self-service (bank, I-9, W-4 requests) | `controllers/Api.php` (mobile) | `/employment`, `/employee-folder` | partial (A7, T12–T14) |
| GAP-802 | Performance reviews | `employee/controllers/Employees_performance.php` | `assignment_reviews` (D: 2a6bc05) | present, but the supervisor path is broken (A1) |
| GAP-803 | Awards, notice board, reward points | `award`, `noticeboard`, `rewardpoint` | `/comms`, `/notifications` | partial |
| GAP-804 | Assets and equipment | `asset/controllers/*` | `equipment`, `equipment_issues`, `vehicles` | present |
| GAP-901 | Chart of accounts, vouchers, ledgers | `accounts/controllers/Accounts.php` | none | missing |
| GAP-902 | Procurement (request → quote → PO → receipt) | `procurements/controllers/*` | `vendor_invoices`, `/accounts-payable` | partial |
| GAP-903 | Project costing | `projectmanagement` | D6 (committed vs placed cost), `/billing` | partial |
| GAP-904 | Loans | `loan/controllers/Loan.php` | `wage_advances` | present (advances); interest-bearing loans are not wanted |
| GAP-1001 | Ad-hoc report builder | `reports/controllers/Adhoc_controller.php` | none | missing. **Not to be ported as designed**: it queries any table. |
| GAP-1002 | Encryption key rotation tool | none | none | missing; needed for SEC-0 |
| GAP-1101 | Project management, sprints, kanban | `projectmanagement` | none | missing |
| GAP-1102 | Biometric device / geofenced mobile punch | `Api_handler_v2.php`, `Api.php:456-555` | none (T15) | missing. HRM's unauthenticated design is not reusable. |
