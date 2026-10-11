# P4-M05 — external payroll, applicant tracking and background screening, sandbox before live

**What this module does and does not claim.**

- Only ADP is a provider RSS has named: "they use ADP". It is completed
  here through the route Crewvia actually has, the ADP RUN import file.
- Crewvia has no ADP API access. That would need an ADP Marketplace
  partnership and certificates RSS does not have. No ADP API integration
  is claimed.
- No applicant-tracking vendor and no background-screening provider has
  been named or authorized.
- The applicant-tracking side stays the provider-neutral, signed partner
  intake that already existed, now behind a sandbox. No screening provider
  is connected.
- Nothing here reached a real ADP account or partner. ADP's acceptance of
  a file is recorded by the person who imported it: Crewvia cannot see
  into ADP.

## What existed, verified before building

| Area | State before | Where |
|---|---|---|
| ADP RUN import file: company, earnings, overtime and per-diem codes; ADP employee ids; deductions with their provider codes; refusals for anything ADP RUN cannot take | existed | `/payroll-export`, `/settings` |
| Partner applications: signed (HMAC, 5-minute window), schema checked, consent required, each event once, a changed event refused, closed vacancy refused | existed, disabled by default | `/recruiting-webhook`, `install/CONNECTOR-CONTRACT.md` |
| Background and drug screening: authorization signed by the worker, statuses kept by hand | existed, manual | `/checks` |

What was missing:

| Gap | Where |
|---|---|
| No record of the files produced: no fingerprint, no way to hand out the same file again, nothing saying which was imported | ADP |
| No trial before real use: the first file went straight to the real payroll | ADP |
| Nothing recorded ADP's answer, and nothing compared ADP's results with Crewvia's | ADP |
| A partner channel enabled in the configuration went straight to creating real applications, with no sandbox | applicant tracking |
| No audit of partner events or refusals, and one secret only (no rotation) | applicant tracking |
| No authorized screening provider | screening (not built, by design) |

## What was built

**Connector modes** (`connector_modes`, Integrations page,
administrators):

- **disabled:** nothing passes.
- **sandbox:** test files and test events, nothing made real.
- **live:** allowed only once a sandbox trial has passed, and only when the
  administrator records who at RSS authorized it (an agreement, a ticket,
  an email reference) and who switched it.

Every change is in the integration audit trail, which now covers ADP and
applicant tracking. What already worked keeps working:

- an ADP setup whose codes were already filled in was recorded live;
- each partner channel already enabled was recorded live;
- a channel added afterwards starts in sandbox;
- an administrator can send any connector back to sandbox or switch it
  off.

**ADP.**

- **Sandbox:** the file is marked TEST (`adp-run-TEST-…`), for ADP's
  preview or a test company.
- **Every file kept:** review, test and live, with its SHA-256 in the
  response header. It can be downloaded again exactly as made, and is
  refused once altered.
- **ADP's answer:** payroll records it per file, accepted or rejected,
  with ADP's batch or confirmation reference. A rejection needs what ADP
  reported. A test file ADP accepted is the sandbox trial passing.
- **Disabled:** no ADP file is produced; the review file still is.
- **Reconciliation with ADP's payroll register:**
  - Upload the register CSV ADP exports for the week and name its
    employee ID and gross pay columns, because registers differ by ADP
    product. The names are remembered.
  - Each ADP id is compared with the gross pay Crewvia froze for the
    week, every project together, plus the period's adjustments.
  - Each person is shown as matched, different (with the difference), in
    ADP only, or in Crewvia only.
  - Amounts like `"1,200.00"`, `$500.00` and `(10.00)` are read.
  - Net pay is not compared, because ADP's includes the taxes it
    withholds.

**Applicant tracking (partner intake).**

- **Sandbox:** a signed, valid application is answered `202` with what
  live would do (`created: false`, whether the vacancy is open). Nothing
  is created, and the first such event records the trial as passed.
- **Live:** it creates the application for recruiter review, as before.
- **Disabled:** answered `401`, like an unknown channel.
- **Secret rotation:** a `secrets` list carries the old secret while a
  partner switches.
- **Audit:** every outcome is in the trail, with sandbox events,
  duplicates and failures; refusals are bounded per sender.
- `install/CONNECTOR-CONTRACT.md` documents the modes.

**Background screening.** It is shown as not connected, with the reason.
Cases stay on `/checks`. A provider is connected only once RSS chooses and
authorizes one, then through the same sandbox-before-live rule.

Schema:

- `connector_modes`
- `payroll_exports` (with the files)
- `adp_reconciliations`
- `integration_events.provider` widened to `adp` and `ats`

Rollback: `install/rollback/p4-m05.sql`.

## Tests

`tests/connectors_db.php` covers:

- rollback and upgrade, with the configured partner channel kept live and
  the announcement saying no screening provider is connected;
- a silent repeat;
- the fixture: a project and three employees with ADP ids, approved and
  frozen for the week ending 13 June 2015, ADP codes, an open vacancy;
- ADP put in sandbox.

`tests/connectors_http.py` covers:

- **ADP in sandbox:** the TEST file with the three employees under the
  codes; kept with its SHA-256 (in the header and the record) as a test
  file; the review file kept.
- **Going live:**
  - refused before ADP accepted a test file;
  - the import without a batch reference refused, a rejection without
    reason refused;
  - the acceptance recorded, which is the trial passing;
  - recorded once only;
  - payroll cannot switch connectors;
  - live without an authorization refused, then live with it, recording
    who.
- **Live files:**
  - the real file;
  - downloaded again identically;
  - refused once altered;
  - off: no ADP file, but the review file;
  - back live.
- **Reconciliation:** wrong column refused, non-amount refused; matched,
  different, in Crewvia only and in ADP only; 1 and 3 with the −10; the
  columns remembered; every ADP step in the trail.
- **Partner:**
  - live before;
  - back to sandbox;
  - a signed application answered 202 with nothing created, which passes
    the trial;
  - a wrong signature refused;
  - live refused without the agreement, then live;
  - the same event creating the application once, then a duplicate;
  - off: refused even when correctly signed;
  - every partner outcome in the trail.
- **Screening:** shown as not connected.
- **Unknown connector:** refused.
- **Translations:** French and Spanish.

The existing ADP export tests (pay rules, leave, gross-to-net) and the
partner webhook tests pass unchanged. The CSRF sweep covers `mode`,
`confirm_import` and `reconcile`.

### A blind spot in the test suite, closed

Every HTTP suite refuses a page carrying `Warning:`. But the test server
printed PHP errors as HTML (`<b>Warning</b>:`), so a warning on a page was
never caught, only fatal errors. That included the access matrix's "no PHP
error" check across 651 pages.

Building this module exposed it: a fixture's frozen pay lacked fields, and
the warning sat at the top of an ADP file. The test server now runs with
`html_errors=0`.

The whole suite was then run again. The only warnings were in four older
fixtures (accounting, costing, pay periods, reports), whose synthetic pay
snapshots lacked fields every real approval freezes. Those fixtures now
carry every field, at zero where they say nothing, so their figures are
unchanged (a missing field was already read as zero). No application page
the suite opens produced a warning.

Full isolated run: **2011 PASS, 0 FAIL** (browser step skipped), with
warnings now visible to the tests.

Mutation: I let a connector go live without a passed sandbox trial. The
suite failed on "Live is refused before ADP has accepted a test file".

## Before going live, for real

- **ADP:**
  - In production, ADP may already be marked live, if its codes were set
    up. If not, it is in sandbox: produce a TEST file for a past week,
    import it into ADP RUN's preview (or a test company), record ADP's
    answer, then switch it live under Integrations with the authorization.
  - After a payroll, compare ADP's register for the week.
- **Applicant tracking:** a partner is chosen and authorized by RSS. It is
  configured on the server as a channel with its secret, starts in
  sandbox, sends signed test events, and goes live with the agreement
  reference.
- **Background screening:** RSS chooses a provider. Its API or file
  exchange is reviewed, and it is built behind the same rule as a
  separate, authorized piece of work.
