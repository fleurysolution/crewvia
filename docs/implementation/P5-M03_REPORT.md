# P5-M03 — equipment: classification, inspections, maintenance, service, lifecycle, retirement, valuation

## What existed, verified before building (Phase 2, `ASSETS_REPORT.md`)

| Asked for | Existed | Missing |
|---|---|---|
| Classifications | categories with an inspection interval | a class, valuation defaults, any screen to manage categories |
| Inspections | "inspected" moved the next date, logged in the history | a record of each inspection with its result and findings; any failed result |
| Maintenance schedules | none | everything |
| Service history | "back from repair" with a cost, in the history | a service record: kind, vendor, downtime, reference |
| Lifecycle | statuses (available, in repair, lost, retired) and the event history | in-service, retirement and disposal dates; the item's timeline |
| Retirement | retire with a reason | disposal: how, when, for how much; a final state |
| Financial valuation | purchase date and cost | depreciation, book value, cost of ownership, gain or loss |

## What was built

All of it is on top of `app/assets.php`, which Operations and the register
already share. Issuing, taking back and every rule from Phase 2 are
unchanged. The new rules sit in `app/asset-lifecycle.php`.

**Data** (migration `install/asset-lifecycle.sql` plus guarded columns;
rollback `install/rollback/p5-m03.sql`):

- `asset_inspections`: date, pass or fail, findings, next due, inspector.
- `asset_maintenance_plans`: task, every N days, next due, last done, and
  whether it blocks issue once overdue.
- `asset_service_records`: kind (repair, maintenance, calibration), date,
  vendor (Phase 3's vendors) or a name, cost, days out of use, reference,
  notes, and the plan it was done for.
- On a category: class (safety, tools, electronics, vehicles and plant,
  other), useful life in months, and residual value as a share of the cost.
- On an item:
  - in-service date, set at its first issue;
  - its own useful life or residual value, if not the category's;
  - retirement date;
  - disposal date, method and proceeds;
  - a new final status, disposed of.
- At the first upgrade, repairs already costed in the history become
  service records, once.

**Rules:**

- **A failed inspection** needs the findings. It sends the item to
  repair, and is refused while the item is out. A repair alone does not
  clear it: the item is not issued until an inspection passes.
- **A pass** sets the next inspection, from the date given or from the
  category's interval. The Phase 2 "inspected" action now writes a passed
  inspection record too.
- **Maintenance plans:** every 1 to 3,650 days, starting today or later.
  A plan that blocks issue keeps the item in once overdue (a calibration,
  say). Recording the service for the plan moves it on one interval from
  the day it was done.
- **Service records:** a date not in the future; a vendor from the list or
  a name; cost from 0 to 1,000,000; downtime from 0 to 365 days.
  "Back from repair" writes the service record itself, so the cost is
  entered once and appears in both the history and the service list.
- **Disposal:** only once retired or lost, on or after the retirement
  date. Sold needs its price; only a sale brings proceeds; only a client's
  item is returned to the client. A disposed item is final: it cannot be
  put back, inspected or serviced, and its plans stop.
- **Valuation:**
  - straight-line: cost less residual, over the useful life in whole
    months, from the in-service date (or the purchase date);
  - book value on any date;
  - cost of ownership: cost plus every service;
  - a disposed item is worth nothing after its disposal, and shows the
    gain or loss against its book value then;
  - a lost item is written off on the day it was lost;
  - not valued, and saying why: an item owned by the client, one without
    a cost, one with no useful life on the item or its category, one with
    no date.

  **It is not posted to the ledger.** Equipment is expensed when bought
  today (account 5120). Capitalising it is the agency's accountant's
  decision, and these are the figures it would need.

**Screens:**

- **The item page:**
  - class and lifecycle dates in its details;
  - value today, with the form to change what it is based on;
  - the lifecycle timeline, and the disposal form once retired or lost;
  - inspections with their form;
  - maintenance plans with theirs;
  - the service history with its form.

  Recruiters still see the item, but not its value or the forms.
- **The register:** what is due within two weeks (inspections and
  maintenance), and the categories to add and change.
- **Asset valuation** (logistics, payroll, administrators):
  - on any date, by class;
  - totals by class and overall; every item with its book value and cost
    of ownership, and why an item is not valued;
  - a CSV of it, its cells neutralised against formulas;
  - each export recorded in the audit log.
- French and Spanish.

One design choice found by the full suite: the service record names its
vendor without a foreign key. With one, P3-M06's rollback (which drops
`vendors`) failed. Vendors are never deleted, only approved or suspended,
and the vendor is checked when the service is recorded.

## Tests

`tests/asset_lifecycle_db.php` (8 checks):

- rollback, which keeps every item and makes a disposed one retired
  again, then upgrade;
- a repair costed only in the history carried into the service records,
  and only once over two upgrades;
- the fixture: logistics, a recruiter, payroll; a project and a placed
  worker; an approved vendor; a gas detector category (30-day inspection,
  60-month life, 10% residual); six items:
  - a gas detector, 24 months in service;
  - a radio past its 12-month life;
  - a client's lift;
  - an item with no cost;
  - a generator, 30 months in service;
  - an item whose name is a formula.

`tests/asset_lifecycle_http.py` (58 checks):

- **Categories:** refused with a bad code, a 700-month life, a 95%
  residual, or a code already used; added, then changed; refused to
  recruiters.
- **Inspections:**
  - refused: a future date, a failure without findings;
  - a pass sets the next one 30 days on;
  - a failure sends the item to repair and keeps it from being issued;
  - back from repair it still does not go out until an inspection passes;
  - the repair's cost lands in the service history, entered once;
  - after a pass it goes out; a failure while it is out is refused;
  - the first issue sets the in-service date.
- **Maintenance:**
  - refused: every 0 days, a past start;
  - a 90-day plan that blocks issue; overdue, the item does not go out,
    and the register lists it as due;
  - services refused: a future date, nobody named, a negative cost, a
    plan of another item;
  - the calibration recorded with vendor, cost, downtime and reference,
    moving the plan on 90 days, and the item goes out again.
- **Valuation:**
  - the detector at exactly the straight-line figures for the months it
    has been in service, and a cost of ownership of 1,325.50
    (1,200 + 40 + 85.50);
  - recruiters see neither the value nor the forms;
  - the radio worth nothing, then its residual of 50 once set;
  - refused: a residual above the cost, an in-service date before the
    purchase;
  - the client's item and the uncosted one not valued, with why.
- **Disposal:**
  - refused while in use; retiring dates it;
  - refused: a sale without a price, "returned to the client" for an
    agency item, proceeds on a scrapping;
  - sold for 3,000: final, with no putting back and no inspection;
  - the gain against its book value at the sale;
  - the lifecycle timelines of the generator and the detector.
- **The report:**
  - refused to recruiters, open to payroll;
  - the same book value as the item page; the disposed generator at
    nothing;
  - a year back; filtered by class;
  - the CSV with its header, and the formula name written as text.
- **Translations:** French and Spanish.

Also:

- the Phase 2 asset tests pass unchanged on the changed module;
- the access matrix: 11 checks on the valuation page; recruiters,
  supervisors, workers and clients are refused;
- the CSRF sweep: 6 new forms on the assets page.

### Results

Full isolated run: **2384 PASS, 0 FAIL** (browser step skipped). That is
66 checks more than P5-M02's 2318, all of them this module's.

Mutation: I let an item that failed its inspection go out once repaired.
The suite failed on "Back from repair, it still does not go out: the
failed inspection stands until one passes", after 2250 passes.

## Not done here

- Posting acquisition, depreciation or disposal to the ledger: an
  accounting policy decision.
- Other depreciation methods (declining balance, units of use), and
  maintenance by hours of use: only calendar intervals.
- Checklists inside an inspection: an inspection is one result with its
  findings.
