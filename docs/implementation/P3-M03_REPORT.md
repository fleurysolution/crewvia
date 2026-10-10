# P3-M03 — general ledger: Crewvia's own books, fed by what it records

## What existed, verified before building

| Part | State before | Where |
|---|---|---|
| Balanced entries built from invoices, payments, credits, bills, claims, payroll | existed, for the QuickBooks file only (P3-M02, P3-M04) | `app/accounting.php` |
| Each record exported once; batches reversed by correcting batches | existed (P3-M02) | `accounting_sources`, `accounting_batches` |
| Months closed against new entries | existed (P3-M05) | `app/periods.php` |
| A ledger of Crewvia's own: journals, posting, trial balance, account history | **missing**: "Crewvia keeps no ledger" | — |
| Journals for what Crewvia never records (rent, insurance, bank fees, payroll liabilities paid to ADP) | **missing** | — |
| Account numbers, equity accounts, accounts added by the agency | **missing**: 14 fixed mapping keys | — |
| Protection of posted entries beyond the application | **missing** | — |

## The rule: one entry feeds the other

Nothing is keyed twice.

```
record (invoice issued, payment, credit note, bill approved, claim paid, payroll period approved)
   -> one balanced journal in the ledger, posted by itself
   -> the QuickBooks export, drawn from the ledger's journals
```

Debits and credits are kept inside the ledger, because they are what makes
a trial balance and a balance sheet balance. Nobody enters them. The entries
are the ones the QuickBooks export already built. The function was split
in two:

- `accounting_entries()` builds them;
- `accounting_pending()` now reads the ledger.

The only journals typed by hand are for what Crewvia never sees. They go to
QuickBooks the same way, so they are not typed a second time there either.

## What was built

**Posting from the records** (`ledger_sync`).

- Each record becomes one journal; its source is unique, so it can never
  post twice. The ledger catches up whenever it, the QuickBooks export or
  the periods page is opened, so a month is always judged, and a file
  always built, on a ledger that holds everything recorded.
- It runs outside any transaction, under a database lock. Four processes
  started at once post a new record once between them.
- A journal counts on the record's own date. If that month was already
  closed when the record reached the ledger, the journal counts on the day
  it arrived and keeps the record's date beside it. A closed month is never
  changed.
- **A change to P3-M05:** an export used to be refused when a record had
  slipped into a closed month. That record now lands in the open month.
- Payroll periods are always in the ledger. The QuickBooks export still
  leaves them out while ADP posts payroll, as before.
- A record that does not balance is not posted, and the ledger page says
  so.

**Manual journals** (`/ledger`, payroll and administrators).

- Date, what it records, then 2 to 20 lines: an account, a debit or a
  credit, and optionally a name and a class (a project).
- Refused when:
  - debits and credits differ;
  - a line has both a debit and a credit, or neither;
  - an amount is negative, or has more than two decimals;
  - it is dated after today, or in a closed month;
  - an account is inactive;
  - nothing is said of it;
  - it touches client or vendor balances, which go through receivables and
    payables (otherwise the balances would no longer match the invoices).
- **Two people.** The author drafts it and someone else posts it. Every
  check runs again at posting, because the month may have closed in
  between. The author or an administrator can discard a draft.
- **Correction.** A posted manual journal is cancelled by a correcting
  journal with every line swapped, dated in an open month, with a reason.
  It is never edited. A journal posted from a record is corrected on the
  record itself (a payment reversed, a credit note), and that correction
  posts its own journal.

**Chart of accounts.**

- The 14 QuickBooks mapping accounts now carry numbers (1000 bank, 1100
  A/R, 2000 A/P, 21xx payroll liabilities, 4000 revenue, 5xxx costs).
- Two equity accounts are added: 3000 owner equity and 3900 retained
  earnings.
- Administrators add accounts (4 to 6 digits, a name, a kind) and switch
  them on or off. The built-in accounts stay on.
- A new account must be mapped to QuickBooks before a journal that uses it
  can be exported. The export says so and refuses until then.

**What cannot change.**

- Five database triggers refuse any change to, or deletion of, a posted
  journal or its lines. The only exception is the link to its correcting
  journal.
- Each posted journal carries a chain number and the SHA-256 of its
  content and of the journal before it. The integrity check recomputes the
  whole chain. If someone with direct database access removes a trigger
  and changes a line, the check names the journal.
- If the database user lacks the TRIGGER privilege, the upgrade prints a
  warning and carries on. The application still refuses changes, and the
  chain still shows them.
- **Records changed after posting.** When a record no longer says what its
  journal says (another amount, another date, or gone), the ledger lists
  it. The journal stands; the record is corrected through its own
  correction.

**What the ledger page shows.**

- Integrity.
- The trial balance by month, on posting dates.
- Each account's lines with a running balance.
- The ledger beside what was exported to QuickBooks, per account: a
  difference is still to export, or payroll left to ADP.
- Drafts, posted journals, and the chart of accounts.

Schema:

- tables `gl_journals` and `gl_lines`;
- `accounting_accounts` gains `number`, `is_system` and `is_active`, and an
  `equity` side;
- five triggers.

Rollback: `install/rollback/p3-m03.sql`. `install/rollback/p3-m02.sql` now
drops the ledger first.

## Tests

`tests/ledger_db.php` covers:

- rollback, which removes the tables, columns and triggers and keeps the
  mapping and the exports;
- upgrade, which announces 16 numbered accounts and restores the five
  triggers;
- a silent repeat;
- the fixture records: February 2025, plus an invoice in November 2022, a
  month closed before it reached the ledger.

`tests/ledger_http.py` covers:

- **Posting:** each record posts with the right lines, name and class; the
  closed-month invoice counts today with its own date; payroll is in the
  ledger but not in the export; nothing posts twice; the chain is
  unbroken; four processes syncing at once post a new record once.
- **Accounts:** adding one, and refusing a taken number, a short number or
  a formula name.
- **Manual journals:**
  - nine refusals;
  - the author cannot post their own journal; a second person can;
  - the sealed journal and its QuickBooks key;
  - posting twice is refused;
  - discard rules.
- **Export:** the manual journal waits in the QuickBooks export; an
  unmapped account blocks it; once mapped, the export holds it once; the
  ledger and QuickBooks then agree.
- **Reversal:**
  - refused for a journal posted from a record;
  - refused without a reason, or dated before the journal;
  - the swapped correcting journal;
  - reversing twice is refused;
  - the account back to zero;
  - the reversal waiting to export.
- **Account switch:** switching off is refused to payroll and refused for
  a built-in account; a switched-off account is not offered and refused in
  a journal.
- **Immutability:** the database refuses to change or delete a posted
  journal. With the trigger removed and a line changed, the chain breaks
  at that journal and the missing trigger is reported. Put back, the chain
  is intact; the upgrade restores the trigger silently.
- **Drift:** an invoice changed after posting is listed and its journal
  stands.
- **Translations:** French and Spanish.

`tests/periods_http.py` was updated for the new rule: a record slipped into
the closed month reaches the ledger in the open month and exports, and
March stays unchanged.

`/ledger` is in the access matrix and the CSRF sweep (`draft`,
`account_add`).

Full isolated run: **1642 PASS, 0 FAIL** (browser step skipped).

Mutation: I let the author post their own journal. The suite failed on "Its author cannot post it".

## Limits

- The income statement, balance sheet, cash flow and budgets are P3-M05's
  next part, built on this ledger.
- The ledger catches up when it, the export or the periods page is opened,
  not at the instant of each record. Its posting dates are the records'
  dates either way. The only difference is a record whose month closed in
  between, and that cannot happen: closing a month first brings the ledger
  up to date.
- Recomputing the records on each visit is fine at RSS's volume. Thousands
  of records a month would need it moved to a scheduled job.
- Journals typed by hand carry no attachment. The receipt stays in the
  records it belongs to.
