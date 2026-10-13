# P5-M15 — T20: weekly hotel folio reconciliation

The owner's request, 13 Oct 2026 (BACKLOG T20, requirement R30: "She goes
through the hotel folios, because that's got to be turned in every
week"), with these decisions:

- **Incidentals:** decided charge by charge: agency, client (rebill) or
  worker. A worker's charge is shown to them with what the hotel printed,
  and payroll recovers it with an adjustment they approve, never on its
  own.
- **Payables:** a reconciled folio becomes the hotel's bill in one step.

## What was found before building

- **Bookings** (`lodging`): one stay per worker, with hotel, room,
  check-in, check-out, own nightly rate, and status. Hotels carry a
  default rate.
- **Hotel bills** in Payables (`vendor_invoices`) were typed by hand as
  one amount.
- **Lodging orders** were matched on rooms × price × nights (P3-M08).
- **Nothing compared a hotel's folio** with who actually stayed which
  nights. The roll call (present or absent) was never set against the
  nights billed.

## What was built

**A folio** (Hotel folios, Logistics and payroll; under Deployment):

- **Scope:** one hotel, one project, one pay week (Sunday to Saturday),
  with the folio number the hotel printed and the payment due date.
- **Opening it** fills one room line per guest booked at that hotel for
  that week: their nights inside the week (check-out day excluded) at
  their booked rate, or else the hotel's. Cancelled bookings and other
  hotels are left out.
- **The desk only corrects** what the hotel billed.
- **It can add:**
  - a guest Crewvia did not book;
  - a tax;
  - an incidental, with who pays.

**Each room line is checked against its booking.** These need a note
before the folio can be reconciled:

- **more nights than booked;**
- **fewer nights than booked;**
- **a rate that differs from the booking;**
- **no booking in Crewvia for this guest.**

A room billed on nights the worker was **absent at roll call** is pointed
out for the per diem question, without blocking.

**Totals:**

- billed by the hotel, against the rooms as booked;
- who pays: agency, client, workers;
- the lines still to check.

**Reconciling** freezes the lines. **Reopening** frees them, until the
folio is a bill.

**The bill** (payroll only):

- It becomes the hotel's bill in Payables: the hotel, the folio number as
  the reference, the billed total and the due date.
- It is linked to the folio and made once.
- It is refused when a bill from that hotel with that reference was
  already typed in Payables, so nothing is paid twice.

**The worker** sees, on their portal, the hotel charges they pay from
reconciled folios: the week, the hotel, what was printed, the note, and
the amount.

**Data:** `install/folios.sql` and a guarded step in `install/upgrade.php`.

- **New tables:** `hotel_folios` and `hotel_folio_lines`.
- **The first upgrade prints:** "Hotel folios: each hotel's weekly folio
  checked against the rooms booked…". A repeat is silent.
- **Rollback:** `install/rollback/p5-m15.sql`. Bills already made stay in
  Payables as ordinary bills.

**Translations:** 79 new strings in French and Spanish.

## Tests

- **`tests/folios_db.php`:**
  - rollback (bookings and bills kept), then upgrade announced, then a
    repeat silent;
  - a stay's nights inside a week: through the week, check-out excluded,
    starting after;
  - the fixture: Logistics, payroll, a recruiter, two hotels, bookings
    (the whole week at the hotel's rate, three nights at the guest's own
    rate, one cancelled, one at the other hotel) and one night absent.
- **`tests/folios_http.py`:**
  - **Opening:**
    - refused without a number, a hotel, or a started week;
    - opened with exactly the two bookings (7 × $100, 3 × $90);
    - nothing differs except the absent night, which is shown without
      blocking;
    - billed equals booked;
    - not twice.
  - **Correcting:**
    - a night more flagged, and reconciling refused until explained;
    - another rate flagged;
    - a line's limits checked;
    - an unbooked guest flagged;
    - a tax added;
    - an incidental refused without who pays or which guest;
    - a laundry charge to a guest and a minibar to the client;
    - a line removed;
    - the totals: billed $1,445.50, booked $970.00, agency $1,380.50,
      client $40.00, worker $25.00.
  - **Reconciling:**
    - reconciled, with the lines frozen;
    - the worker sees their charge on the portal, and nobody else does;
    - Logistics cannot make the bill;
    - reopened and reconciled again.
  - **The bill:**
    - the due date is required;
    - payroll makes the bill with the right hotel, reference, amount and
      due date;
    - linked once, not again, and not reopened;
    - listed in Payables;
    - a bill typed by hand in Payables is never entered again from the
      other hotel's folio.
  - **Access:** a recruiter is refused and a worker is sent to their
    portal, both unable to change anything.
  - **French.**
- **`tests/folios_probe.php`:** reads the folios as the screens compute
  them, and the bills.
- **Access matrix:** `/folios` is refused to supervisors, workers and
  clients.
- **CSRF sweep:** open, reconcile and bill.
- **Mutation:** with reconciling no longer counting the unexplained lines,
  the test failed at "Reconciling is refused while a difference is
  unexplained". Restored afterwards.

## Not done

- **Reading the hotel's folio file:** nothing reads a PDF or a
  spreadsheet. The desk corrects the pre-filled lines.
- **Recovering a worker's charge:** this is not automatic. Payroll records
  the recovery under Payroll runs, as decided.
- **Rebilling a client's charge:** it is totalled on the folio, but not
  added to a client invoice yet.
- **Hotels with a different week:** a folio is the agency's Sunday-to-
  Saturday week; a hotel that bills another week is entered against the
  pay week it falls in.
