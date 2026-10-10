# P2-M01 — classifications, positions, grades, bands, transfers, promotions, employment history, dated pay

## What existed, verified before building

| Part | State before | Where |
|---|---|---|
| Classification, effective-dated, with FLSA status | done in P1-M01 | `app/classification.php` |
| Positions | the scope line's trade, and the trade on the assignment | `job_order_lines`, `assignment_details.trade` |
| Promotions and transfers, effective-dated, with reason | existed | `personnel_changes`, `app/pages/personnel.php` |
| Grades and pay bands | **missing** | — |
| Effective-dated salary and rate changes | **missing**: the salary was typed over in place on the folder; an assignment's rate changed at once | `employee-folder.php`, `candidate.php` |
| One employment history | **missing**: the pieces were on four screens | — |

No second position or employee table was created: positions stay the scope
line and the trade, as REQUIREMENTS M2–M4 set them.

## What was built

- **Pay grades** (Administration, admin only): each grade has an hourly band
  and a salary band, either end open. Retired, never deleted.
- **Dated pay changes**, on the employee folder under *Pay and grade*
  (payroll): a salary, an assignment's hourly rate, or the grade, from a date,
  with a reason.
  - A change applies to every week ending on or after its date.
  - It is refused if its first week is already approved; that difference is
    an adjustment in an open pay period (P1-M06).
  - Pay outside the band of the person's grade on that date is refused for
    payroll and allowed for an administrator, and marked *outside the band*.
  - A change whose date has not come can be cancelled; one in effect cannot.
  - The pay calculation asks for the figure on the week, so a week before a
    change is paid the change's "before" amount even after the assignment or
    profile holds the new one. A mutation that removed this paid the earlier
    week at the new rate, and the suite failed on it.
- **The salary is no longer typed over** in the payment form; it shows there
  read-only.
- **Employment history** on the folder (recruiter and payroll): assignments,
  promotions and transfers, classification, grade and pay changes, newest
  first. Amounts are shown to payroll only.

Schema: `pay_grades`, `compensation_changes`, `employee_profiles.grade_id`.
Rollback: `install/rollback/p2-m01.sql`.

## Tests

`tests/compensation_db.php` (rollback, upgrade, silent repeat) and
`tests/compensation_http.py` (34 checks). They cover:

- grades and band refusals;
- a raise from 20 to 25 dated inside week B: week A freezes at 800, week B at 1,000;
- a salary of 1,200 rising to 1,400 the same way;
- a backdated change refused;
- outside the band refused for payroll, allowed and marked for an administrator;
- a scheduled change left alone, then cancelled;
- an applied change not cancellable;
- the payment form unable to change the salary;
- history shown with amounts to payroll and without them to a recruiter.

Full isolated run: **885 PASS, 0 FAIL** (browser step skipped).

## Limits

- A change takes a whole week; a raise mid-week is not pro-rated.
- Bands are advisory for payroll and binding only in that crossing them needs
  an administrator. Nothing here sets what a band should be.
