# P3-M02 — chart-of-accounts mapping and QuickBooks export

## Decision

External accounting: RSS keeps its books in **QuickBooks Online** and runs
payroll through **ADP**. Crewvia keeps no ledger (P3-M03 is not needed under
this decision). Crewvia sends what it already records to QuickBooks as
journal entries.

## What existed, verified before building

| Record | State before | Where |
|---|---|---|
| Client invoices: draft, issued, paid, void | existed, **no issue or payment date** | `client_invoices` |
| Vendor bills: received, approved, paid, with paid date, linked to orders | existed | `vendor_invoices` |
| Expense claims: approved, paid, payer, paid date | existed | `expense_claims` |
| Payroll periods, approved and locked, with frozen figures and adjustments | existed (P1-M06) | `payroll_runs`, `pay_snapshots`, `payroll_adjustments` |
| An account mapping, an export, a record of what was exported | **missing** | — |

## What was built

**QuickBooks export** (`/accounting`, Pay and billing; payroll and administrators).

| Record | Entry | Date |
|---|---|---|
| Client invoice issued (or paid) | Dr A/R (client) · Cr revenue | issued (created, for older invoices) |
| Client invoice paid | Dr bank · Cr A/R (client) | paid |
| Vendor bill approved (or paid) | Dr cost by category · Cr A/P (vendor) | due date |
| Vendor bill paid | Dr A/P (vendor) · Cr bank | paid |
| Agency-paid claim paid | Dr cost by category · Cr bank | paid |
| Payroll period approved (**off by default**) | Dr wages, employer contributions, per diem · Cr deductions, employer contributions owed, net pay owed | week ending |

The QuickBooks *Class* on every line is the project, and *Name* is the
client or vendor. A worker's name is never sent.

Costs go to accounts by category, the same split as P3-M01: hotels,
transportation, equipment, other.

**Payroll** is off until an administrator turns it on with a reason. ADP
can post payroll to QuickBooks itself, and both would count the same pay.

Records that are never sent: draft and void invoices, bills not approved,
claims not paid or paid by the client.

**Rules that keep the books right**

- **The mapping is confirmed first.** It covers 14 Crewvia accounts, each
  typed as the QuickBooks chart spells it (sub-accounts as `Parent:Child`).
  Nothing is exported to an account an administrator has not ticked as
  checked. Names a spreadsheet would run as a formula are refused.
- **Every entry balances**, or the export is refused.
- **Each record goes out once.** A unique key holds every exported record,
  and a concurrent export that reaches the same record is refused whole.
- **A batch is never edited.** It is reversed by a correcting batch: the
  same lines with debits and credits swapped, dated when the reversal is
  made. That frees its records to be exported again. The original lines
  stay.
- **The file** is QuickBooks Online's journal-entry import: Journal No,
  Journal Date (MM/DD/YYYY), Account, Debits, Credits, Description, Name,
  Class. It is rebuilt from the stored lines, so every download is the
  same file. Its SHA-256 is recorded, and the download is refused if the
  stored lines no longer match. A later change of mapping never rewrites
  what was already sent.
- Client invoices now record when they were issued and paid.

Schema: `accounting_accounts`, `accounting_batches`, `accounting_lines`,
`accounting_sources`; `client_invoices.issued_at`, `paid_at`. Rollback:
`install/rollback/p3-m02.sql`.

## Tests

`tests/accounting_db.php` (rollback, upgrade with 14 unconfirmed accounts
and payroll off, silent repeat) builds 2024 records, some of which must not
go out. `tests/accounting_http.py` covers:

- unconfirmed accounts blocking the export;
- the mapping and its refusals;
- the seven records exported and the five others not, every journal
  balanced, and the entries for each kind;
- a second export unable to resend anything;
- the CSV format, its fingerprint, identical repeat downloads, a refused
  download after a stored line is altered, and a renamed account not
  rewriting the past;
- payroll absent until turned on, then the journal (2,680 / 50 / 320
  against 100 / 50 / 2,900);
- the reversal (swapped lines, original untouched, refused twice, its own
  fingerprint) and re-export after it;
- issue dates recorded from now on;
- roles and translations.

`/accounting` is in the access matrix (payroll-only list) and the CSRF sweep.

Full isolated run: **1099 PASS, 0 FAIL** (browser step skipped).

Mutation: I made already-exported records show as still waiting. The suite
failed on "Exported records are no longer waiting".

## Limits and next steps

- **File, not API.** Partners stay disabled, so nothing calls QuickBooks.
  A direct connection (Intuit OAuth, posting journal entries) needs RSS's
  Intuit app and approval. It can reuse these batches unchanged.
- QuickBooks Online is assumed to be a U.S. company (MM/DD/YYYY), with
  Class tracking on. Without Class tracking, the column is ignored.
- Vendor bills are dated by their due date: Crewvia records no bill date.
- Records issued before this module have no issue date, and are dated when
  they were created.
- Editing a record after its export is not detected. Reverse the batch and
  export again.
