# Crewvia — baseline audit

Taken 9 October 2026, before any Phase 1 code. Every number below was
measured on that day on the build machine; nothing is carried over from an
earlier report.

Crewvia is the primary system. The `_HRM` application is a functional
reference only.

---

## 1. Sources actually used

| Artifact named in the directive | What was used | Note |
|---|---|---|
| `crewvia10102026.zip` | **Not found** on this machine. The working tree `D:\app\rss-ops` was used instead. | The directive allows the extracted project. |
| `veloraweb_crewvia.sql` | **Not found, and deliberately not sought.** The schema was read from `install/*.sql` and `install/upgrade.php`. | It is a production dump holding real applicants (constraint C7). It does not belong in a working tree. |
| `_hrm.zip` | Extracted outside the repository, into a session scratch folder. | It was never placed under `D:\app\rss-ops`. |
| `u373056880_hr (1).sql` | Read for structure and row counts only. | It holds real personal data: SSNs, pay and contact details. No row was copied. |

---

## 2. Repository state

| Item | State |
|---|---|
| Local branch | `main` at `67c2c3b`, one commit ahead of `e7edda4`. The tree is clean. |
| GitHub `main` | **`3ce5942` "Config file code upload"**, pushed through the GitHub web interface on 9 Oct at 17:47. It adds `config.php`. |
| Tracked files | 271 at `e7edda4`; 272 on GitHub, the extra one being `config.php`. |
| Deployable archive | `crewvia-2026-10-09s.tar.gz`, sha256 `71426a9b9d9989132dedcce7a5d5393a7eb4ea2194de0aaa404e61f2a78e7e96`. It is byte-identical to `git archive e7edda4` and contains no `config.php`. |

**Open security finding — SEC-0.** The `config.php` on GitHub holds values
that are not placeholders:

- the database name, user and password;
- the 44-character encryption key;
- an `app_url` matching the production host.

It does not match the local `config.php`. The owner has decided not to
rotate credentials until the code is verified. Until then:

- No archive may be built from `origin/main`.
- `main` must not be merged locally.
- The encryption key cannot simply be replaced, because it decrypts
  contracts, proofs, résumés and bank details. Rotating it needs a
  re-encryption tool, which is recorded as a Phase 4 item.

---

## 3. Architecture (verified)

**Overall shape**

- Plain PHP 8.2, with no framework, no composer and no build step.
- A single front controller, `public/index.php`, with an explicit
  allow-list of about 64 routes plus two ID routes, `/candidates/{id}` and
  `/placements/{id}`.
- Each route maps to a controller in `app/pages/<name>.php` and a view in
  `app/views/<name>.php`.
- Shared helpers live in `app/bootstrap.php`. Domain modules are
  `app/hr.php`, `app/lifecycle.php`, `app/placement-rates.php`,
  `app/payroll-calculation.php`, `app/contracts.php` and others.
- 80 page controllers and 76 views.

**Database**

- 118 `CREATE TABLE` statements across `install/*.sql`.
- `install/install.php` runs `schema.sql` and then `upgrade.php`.
- `upgrade.php` applies every other SQL file and every ALTER, each one
  guarded by an `information_schema` lookup.

**Security mechanisms**

| Mechanism | Where |
|---|---|
| Authentication | `pages/login.php`. Constant-time miss through a dummy `password_verify`. Optional TOTP (`app/security.php`). Idle timeout of 30 minutes. The session is versioned and revoked on change. |
| Roles | `roles()` in `app/bootstrap.php`: admin, recruiter, hotels, payroll, supervisor, worker, client. |
| Permission check | `can()` and `require_role()`. Extra desks are granted through `role_permissions`. |
| Route allow-lists | `index.php:70-75` restricts client, supervisor and worker to their own lists. |
| CSRF | `csrf_check()` runs globally at `index.php:33`, after the self-authenticating webhooks. |
| Output escaping | `e()` and `te()`. CSV formula injection is guarded by `csv_cell()`. |
| Audit | `log_activity()` writes to the `activity` table. There are also domain event tables, and `worker_bank_access` records every reveal of bank details. |
| Encryption at rest | AES-256-GCM through `token_encrypt` and `token_decrypt`. |
| Throttling | `security_rate_limit()`. Login is limited to 10 attempts per 15 minutes per email (Q1, still open) and 100 per IP. |

**Tenant isolation**

- Each tenant has its own database, encryption key, storage folder and
  session cookie (`app/tenancy.php`). It fails closed.
- No business table carries a `tenant_id`. **Directive rule 9 is met by
  separate databases, not by tenant columns.**
- New tables follow the same model. Adding a `tenant_id` column inside one
  database would add a second isolation mechanism that disagrees with the
  first.

**Internationalisation**

- `t()`, `te()` and `:named` placeholders, with the English sentence as the
  key.
- `app/lang/fr.php` and `es.php`, plus `messages.tsv` and
  `remaining-views.tsv`.

---

## 4. Tests — measured baseline

**Standalone unit suites**

Run one by one with `php tests/<name>.php`.

| Suite | PASS | FAIL |
|---|---|---|
| saas_unit | 16 | 0 |
| recruiting_unit | 9 | 0 |
| security_unit | 14 | 0 |
| payroll_unit | 7 | 0 |
| connector_unit | 6 | 0 |
| docusign_unit | 11 | 0 |
| i18n_unit en / fr / es | 798 each | 0 |

**Full runner**

`python tests/run_windows.py`, run in isolation on MariaDB port 3308 and
HTTP port 8097:

- **293 PASS, 0 FAIL**, then the run stopped at `browser_tests.cjs` with
  "Cannot find module 'playwright'" (gap E1).
- Everything after that step did not run: the workflows, the contract
  reconciliation, the scale test and the tenant isolation test (gap E2).
- Exit code 1.
- No process was left running.

**Side effect:** the runs rewrite the `tests/i18n-unit-*.json` evidence
files inside the repository. They were restored after the baseline run.

---

## 5. Defects found during the audit

| ID | Defect | Evidence |
|---|---|---|
| A1 | Supervisor end-of-assignment reviews can never run. | `roster.php:9` refuses supervisors before the review handler at line 22. Roster is not in the supervisor allow-list (`index.php:74`). |
| A2 | Roll call is admin-only and today-only. | `structure.php:15` (`require_role('admin')`) and the `CURDATE` insert at line 49. |
| A3 | A placement keeps no link to the requisition or scope line its rates came from. | `placements` has no `vacancy_id` or `order_line_id`. The rates are copied at the three creation sites (`candidate.php:327`, `contracts.php:29`, `portal.php:24`) and the source is then lost. |
| A4 | Changing a person's classification (`employment_type`) overwrites the value silently, with no date, reason or history. That classification drives salaried pay and the ADP export. | `employee-folder.php:36` |
| A5 | Changing a placement's pay or bill rate overwrites it. The activity line keeps only the new pay rate, as text. | `candidate.php:287-293` |
| A6 | Leave types have no administration screen; they are seeded only. | `hr-modules-2.sql:76-81` |
| A7 | Workers cannot submit bank details, and the `pending_review` state is never written. | `employee-folder.php:42-101` |
| A8 | The payroll export does not deduct wage advances. There is no pay-run entity. | `payroll-export.php` |
| A9 | `upgrade.php` prints "Approvals: 3 tables in place." on every run. This breaks S2 (silent on repeat). | `upgrade.php:612` |
| A10 | `install.php --blank` is accepted but ignored. | `install.php:5` |
| A11 | The supervisor allow-list repeats two entries. | `index.php:74` |
| A12 | A real person's first name appears in a code comment. This breaks the no-real-names rule. | `app/pages/dashboard.php:3` |
| A13 | `dashboard.php` has no role guard of its own. Every staff desk sees the weekly money figures. | `app/pages/dashboard.php` |
| A14 | `employees.php` does not escape LIKE wildcards in search. | `employees.php:3` |
| A15 | The `available_from` and `checked_in_at` columns on candidates are added but never used (T1). | `upgrade.php:503-504` |
| A16 | `week_money()` takes the guarantee and overtime from the project, not from the placement or its scope line, even though the placement stores `guarantee_hours`. | `bootstrap.php:357-375` |

---

## 6. The HRM reference

**What it is**

- **Bdtask HRM 4.5**, a commercial product sold under an Envato standard
  licence.
- CodeIgniter 3.1.4 with HMVC: 23 modules and 1,336 PHP files.
- It phones a licence server (`system/core/compat/lic.php`) and an
  auto-update server.

**Licensing consequence:** no HRM code is copied. Crewvia takes concepts
only, and has done so since the first port (commit `2a6bc05`).

**Data:** 110 tables in a live, small installation: 3 employees, 11 users,
522 punches and 10 generated payroll months. Its `employee_history` table
holds SSNs.

**Security posture — reasons not to port the code**

- CSRF is disabled (`application/config/config.php:467`).
- Passwords are stored as unsalted MD5.
- About 30 SQL statements interpolate request values.
- The device and mobile APIs take employee IDs from GET with no
  authentication.
- Views echo values raw.

**Payroll correctness — reasons not to port the rules**

- Federal, Social Security and Medicare tax are calculated but never
  subtracted from net pay (`payroll/controllers/Payroll.php:916-918`).
- The state tax query ignores the tax type.
- Brackets are applied to a monthly gross against annual limits.
- There is no overtime.
- Payslip PDFs overwrite one another.

**Worth taking as concepts**

- Effective-dated employee fields: hire, termination and rehire dates, and
  class code.
- The leave catalogue and balance.
- Payroll as period → sheet → approval → posting.
- Loan and advance deduction, which Crewvia already has.
- A lateness report against a shift rule.
- Holidays.
- Procurement, accounts, assets and project management, for later phases.

---

## 7. Environment

| Need | State |
|---|---|
| PHP 8.2 | `C:\xampp\php\php.exe` 8.2.12 |
| MariaDB binaries | `C:\xampp\mysql\bin`, including `mysql_install_db.exe` |
| Python 3 | 3.11 |
| Node | 23.3 |
| Playwright and Chromium | **Missing** (E1). Installing them is a download and needs the owner's approval. |
| Local shell | PowerShell 5.1. The VPS uses bash. |

---

## 8. Risks carried into Phase 1

1. **SEC-0 (above).** Every archive must be built from a local commit, never
   from `origin/main`.
2. **The directive's payroll scope runs into constraint C9.** C9 says
   "never promise tax filing or deposits — ADP keeps that", and
   decision Q3 is still open. P1-M05 is therefore planned as gross-to-net
   *preparation* with provider export. No withholding table is invented
   (directive rule 17).
3. **Migration mechanism.** The directive asks for "versioned, reversible
   migrations". Crewvia's mechanism is the guarded, idempotent
   `upgrade.php`. Phase 1 keeps that mechanism and adds a written,
   *tested* rollback script per module (see the migration plan). A second
   migration system is not introduced.
4. **The browser step cannot run** until Playwright is installed. The
   runner gains an explicit, loud `SKIP_BROWSER=1` switch, so that the
   steps after it (tenant isolation included) can run without it.
