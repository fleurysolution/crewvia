# P5-M34 — absences and the guarantee, guaranteed overtime, taxable per diem

The owner's terms, 14 Oct 2026:
- 50 hours guaranteed, paid as 40 regular + 10 overtime, plus the per diem;
- an absence policy;
- per diem tax-exempt or withheld;
- then the accounting department runs payroll.

## Found before building

- **The guarantee** was a floor that nothing lowered. A worker absent three
  days was still topped up to 50 hours.
- **Guarantee top-up hours** were paid as regular time, by an earlier
  decision written in the code: 41 hours worked paid 49 regular + 1 overtime.
  The owner's terms are 40 + 10.
- **Per diem** was always exported to ADP under one earnings code, so a local
  worker's per diem (taxable wages) went out as tax-free.

## What was built

**Hours → Gross payroll policy.** Beside the overtime threshold and
multiplier, each project chooses:
- **"Pay guaranteed hours above the threshold as overtime (50 = 40 regular +
  10 overtime)."**
- **An unexcused absence:**
  - the guarantee stands (as before);
  - each takes a day off it (the hours in a day, 10 by default);
  - or one loses the week's guarantee.

An absence is a day marked absent at roll call, or clocked in and not seen on
site (P5-M32), that no approved time off covers. Hours shows the unexcused
absences on the week.

The same week, 41 hours worked, $50 an hour, one unexcused Tuesday and a
Wednesday on approved leave:

| Policy | Paid | Pay |
|---|---|---|
| As before | 49 regular + 1 overtime | $2,525 |
| Guaranteed overtime | 40 + 10 | $2,750 |
| A day off per absence | guarantee 40, so 40 + 1 as worked | $2,075 |
| Forfeit | as worked | $2,075 |

The per diem stays days present × the rate ($150) under every rule.

**Per diem, tax-free or taxable (Payroll export).**
- Each worker on the project is tax-free (travelling) by default. Payroll
  marks a local worker taxable.
- ADP RUN exports a taxable per diem under its own earnings code, set in
  Settings → Payroll export → "Per diem, taxable".
- Without that code, the ADP export is refused rather than sending taxable
  wages as tax-free.

**Defaults keep the pay exactly as it was.** Weeks already approved keep the
amounts frozen in their pay snapshots.

**Data:** one reversible migration (install/rollback/p5-m34.sql): three
policy columns, `placements.per_diem_taxable`, and a setting.

## For the owner

- **Choose the policy for each plant on Hours.** For the terms above:
  overtime after 40, multiplier 1.5, guaranteed overtime ticked, and the
  absence rule you want.
- **Taxability.** Who is local is payroll's call. Crewvia does not decide
  from the address.
- **Pay rule sets** (P2) compute their own lines. A project with a confirmed
  pay rule set is paid under it, and the new options apply to the simple
  weekly calculation only.

## Tests

- **`tests/guarantee_policy_db.php`:**
  - rollback;
  - upgrade, then silence on a repeat;
  - nothing different by default;
  - the fixture.
- **`tests/guarantee_policy_http.py`:**
  - the policy form;
  - each rule against the same week, with exact hours and dollars;
  - the approved leave not counted;
  - Hours showing the absence;
  - an unknown rule refused;
  - the per diem unchanged;
  - taxable marked by payroll;
  - the ADP export refused without the code, then exporting it under that
    code, with REG and OT.
