# P1-M07 — Phase 1 verification

Run on the build machine, 9 October 2026, with `tests/run_windows.py` on its
isolated MariaDB (port 3308) and PHP server (port 8097). Nothing here touches
the live server or its database.

## What the full run covers

| Part | What it proves | Checks |
|---|---|---|
| Unit suites | the pure calculations: pay rules, leave, gross to net, payroll, security, SaaS, recruiting, connectors, DocuSign, i18n | ~2,500 |
| HTTP regression | every screen and workflow built before Phase 1 | ~290 |
| P1-M01 … P1-M06, procurement | each module's rollback, upgrade, silent repeat, and its screens with every refusal path | ~460 |
| **Access matrix** | all 71 routes opened by each of 7 roles (admin, recruiter, hotels, payroll, supervisor, worker, client): **497 pages**, no PHP error, warning, notice or 500; every page outside a role's desk is refused or redirected | 1 summary + per-page assertions |
| **CSRF sweep** | every form added in Phase 1, posted without its token, is refused with 419 | 10 |
| Tenant isolation | two tenant databases, sessions and storage that cannot see each other | in the runner |
| **Backup and restore drill** | the test database is dumped with `mysqldump --single-transaction`, restored into a fresh database, and every one of the 134 tables has the same row count and `CHECKSUM TABLE`; one value changed in the copy is detected | 6 |

Last measured: **798 PASS, 0 FAIL, exit 0**, twice in a row on the final code.

## Mutation checks

Every module was proved able to fail by breaking one rule and watching the
suite fail on exactly that case: the future-dated classification (M01), the
paid-week freeze (M02), the shift premium (M03), the approval balance re-check
(M04), the once-a-week deduction (M05), the second-person approval (M06), and
the author-cannot-approve rule (procurement — which first survived, and got a
test of its own).

## Not covered here

- The browser screenshot step (`SKIP_BROWSER=1`; Playwright is not installed —
  DEFERRED_SECURITY X7).
- Restoring the **live** server: the drill proves the procedure on the isolated
  copy. On the VPS, the same procedure is `mysqldump --single-transaction` of the
  live database, then `mysql <new_db> < dump.sql`, then the checksum comparison.
- Load and penetration testing, and any payroll tax or legal compliance claim.
