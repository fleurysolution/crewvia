# P2-M04 — benefits: plans, eligibility, enrollment, effective dates, contributions, deductions, payroll

## What existed, verified before building

| Part | State before | Where |
|---|---|---|
| Pay items: deductions and employer contributions, fixed or a share of gross, before tax or not | existed (P1-M05) | `pay_items` |
| A pay item on a person from a date to a date, taken by gross to net each week | existed (P1-M05) | `employee_pay_items`, `gross_to_net_for_sheet()` |
| Kind of employment (hourly, salaried, contractor, another company's) | existed (P1-M01) | `employee_profiles.employment_type` |
| Benefit plans, eligibility, enrollment, waivers | **missing** | — |

## What was built

**Plans** (`/benefits`, Pay and billing; payroll and administrators).

- Each plan has a name, a kind (medical, dental, vision, life, disability,
  retirement, other), a provider, which kinds of employment it covers, and
  a waiting period after the person's first assignment begins.
- Paid for either way:
  - **fixed**: a weekly cost for each coverage level (employee only, with
    spouse, with children, family), for the person and for the agency;
  - **percent**: the person elects a share of gross up to the plan's limit,
    and the agency adds its own share (at most 25%).
- **A plan owns two pay items**: the person's deduction (before tax unless
  unticked) and the agency's contribution. They are created with the plan.
- A plan is retired, never deleted. People already on it stay on it.

**Eligibility.** A person may join from their first assignment's start
plus the waiting period, if their kind of employment is covered. **To
offer** lists everyone on assignment who is eligible and has neither
joined nor declined: due now, or from a later date.

**Enrollment.** Enrolling puts both pay items on the person from the
coverage date, at the level's cost or the elected percentage. Gross to net
then takes them every week, with nothing else to set. It is refused:

- before the person is eligible;
- if the plan does not cover their kind of employment;
- if they already have an enrollment or waiver in that plan from that date;
- if coverage would start in a week already approved for pay (the
  difference is an adjustment, P1-M06).

**Never edited.**

- A **change of coverage** (marriage, birth, a new election) ends the
  current one the day before and starts the next, with what changed.
- **Ending** sets the end on both pay items. It is refused before coverage
  started, or if it would reach back into a week already paid.
- A **waiver**, meaning coverage offered and declined, is kept with its
  reason.

**The employee folder cannot change them by hand**: a plan's pay items
cannot be added there, and an enrollment's pay items cannot be ended there.
Instead the folder links to the person's benefits.

**The worker** sees their own coverage under *My benefits*, read only.
Recruiters, logistics, supervisors and clients do not see benefits.

Schema: `benefit_plans`, `benefit_plan_tiers`, `benefit_enrollments`;
`employee_pay_items.benefit_enrollment_id`. Rollback:
`install/rollback/p2-m04.sql` (the pay items enrollments created stay and
keep being taken until ended on the folder). The P1-M05 rollback now drops
the benefit tables first.

## Tests

`tests/benefits_db.php` (rollback, upgrade, silent repeat; an hourly worker
hired 40 days ago with last week approved, a contractor, an hourly worker
hired 10 days ago) and `tests/benefits_http.py`. They check payroll's
actual figures with gross to net on a 1,000 week:

- plan refusals, and the two pay items with the right sides and tax
  treatment;
- who is to be offered;
- contractor, waiting period and approved-week refusals;
- enrollment putting 25 and 40 on the person, taken next week as 25 before
  tax and 40 from the agency;
- a second enrollment refused;
- 12% over a 10% cap refused, then 5% taken as 50 and 30;
- a change to family cover: 25 next week, 90 a month on;
- ending, which stops the savings deduction;
- a waiver, which takes the person off the offer list;
- the folder refusing both manual changes, and the link;
- the worker's read-only view;
- translations.

`/benefits` is in the access matrix and the CSRF sweep.

Full isolated run: **1426 PASS, 0 FAIL** (browser step skipped).

Mutation: I stopped ending the deduction when coverage ends. The suite
failed on "Next week still pays 25; a month on pays 90".

## Limits

- No annual open-enrollment window; enrollment is whenever payroll records it.
- A plan's costs are not re-priced for people already enrolled; a new cost
  for them is a change of coverage.
- Dependents are recorded as the coverage level, not by name.
