# P5-M09 — driving records: MVR (MVRNOW, with the CDL), FMCSA Clearinghouse, PSP

The owner's request, 12 Oct 2026, with these decisions:

- **MVRNOW:** prepare it so drivers' records can be checked.
- **"The CDL one":** all three of the CDL status that MVRNOW returns, the
  FMCSA Clearinghouse, and FMCSA PSP.
- **How:** "they will be a link for them to follow and access their
  account and then record results for processing and records".

## What was found before building

- **MVRNOW** sells motor vehicle records for every state, which also say
  whether the licence is commercial. It needs a business account, prepaid
  escrow, and DMV-authorized use. **It publishes no API**, so nothing is
  called. Staff run the record in the agency's MVRNOW account.
- **The FMCSA Clearinghouse:** an employer queries it before a CDL driver
  drives (a full query, with the driver's consent in the Clearinghouse) and
  every year after (a limited query, on a consent the employer keeps). It
  is used through FMCSA's portal; no employer API was found.
- **PSP:** five years of crashes and three of inspections, on the driver's
  signed disclosure and authorization, through FMCSA's PSP site.
- **In Crewvia:**
  - background and drug cases on `/checks` (per placement, with a
    signature) had no driving record;
  - Qualifications already track expiry dates and renewal alerts, and P5-M04
    writes certificates into them.

## What was built

**Data** (migration `install/driver-records.sql`; rollback
`install/rollback/p5-m09.sql`):

- **`driver_checks`:** one check for one person.
  - **The request:**
    - which check, and the issuing state;
    - **the licence number sealed** (`encrypted_license_number`, re-sealed
      by the key rotation; only its last four kept in clear);
    - the consent: how, when, and where it is kept;
    - for the Clearinghouse, full or limited.
  - **The result:**
    - the date and the provider's reference;
    - for an MVR: licence status, CDL, class, endorsements, restrictions,
      licence and medical certificate expiry, violations and accidents of
      the last 3 years, a major violation;
    - for the Clearinghouse: what it returned;
    - for PSP: crashes (5 years), inspections and out-of-service violations
      (3 years).
  - **The decision**, the pre-adverse notice date, notes, who recorded it,
    and **when it is due again**.
  - The limited query a full query follows.
- **Two qualification types**, added if missing: *Commercial driver's
  licence (CDL)* and *DOT medical certificate*.

**The flow** (`/driver-checks`, *Driving records*, recruiters and
administrators; linked from each person's page):

1. **Request:** choose the check, the state, the licence number, and the
   consent it needs.
   - An MVR needs the signed consent the agency keeps.
   - A Clearinghouse limited query needs the same. A full query needs the
     driver's consent given in the Clearinghouse.
   - PSP needs the signed PSP disclosure and authorization.
   - Without the right one, or dated in the future, it is refused. One
     check of a kind waits at a time.
2. **Open the provider's own site,** in a new tab: MVRNOW, the
   Clearinghouse or PSP (an administrator may change these HTTPS
   addresses). Staff sign in to the agency's account there and run the
   check. **Nothing is sent from Crewvia.**
3. **Record the result and the decision:** acceptable, needs review, or
   not acceptable.

**Rules kept here** (records, not legal advice):

- **MVR:**
  - a licence that is not valid can never be acceptable;
  - a commercial licence needs its class;
  - endorsements are among H, N, P, S, T, X;
  - a valid CDL needs its expiry;
  - the result is dated after the consent and not in the future.
- **Clearinghouse:**
  - "prohibited" can only be not acceptable;
  - information found can never be acceptable;
  - information found in a limited query **opens the full query** that must
    follow it, on the driver's consent in the Clearinghouse. It is shown as
    following the limited query.
- **PSP:** out-of-service violations cannot outnumber inspections.
- **A decision against the person on an MVR or a PSP** (reports from a
  consumer reporting agency) needs at least 10 characters of reason and
  **the date the pre-adverse notice was sent**. A Clearinghouse decision
  needs only the reason.
- **Due again:** the MVR and the Clearinghouse a year after the result (the
  yearly MVR review and the yearly limited query); PSP is not repeated.
  - The overview shows each person's latest check of each kind as current,
    due within the month, or overdue.
  - **The scheduled workflows tell recruiters once** when one comes due or
    falls overdue, and not if a new check is already waiting.

**One entry feeds the other:**

- A valid CDL on an MVR is written into the person's **qualifications**,
  verified, with its expiry.
  - Its detail is the class, endorsements, state and last four.
  - Its evidence is "MVR <state> <date> <reference>".
- The **medical certificate** on an MVR is written the same way.
- A qualification already verified for longer is never shortened.
- Qualifications then do what they already did: requirements per project
  and trade, and the renewal alert 30 days before expiry.
- Each request and decision is in the person's history.

**Translations:** French and Spanish (98 strings).

## Tests

`tests/driver_db.php` (6 checks):

- **Rollback:** it keeps every qualification and the two types.
- **Upgrade:** announced with "no provider is connected"; silence on a
  repeat, no type twice.
- **The fixture:** a driver already holding a medical certificate verified
  until 2035.

`tests/driver_http.py` (52 checks):

- **Requests:**
  - refused: an unknown state, a 3-character licence, the wrong consent
    for an MVR, a consent dated tomorrow, a Clearinghouse request without
    its kind, a full query on a kept consent, a PSP without its form;
  - an MVR requested on the signed consent;
  - the licence normalised and sealed, its last four only on the page;
  - the link to MVRNOW in a new tab;
  - a second waiting MVR refused.
- **The MVR result:**
  - refused: a date before the consent, a future date, no decision, a CDL
    without class, endorsement Q, a valid CDL with no expiry, 100
    violations, a suspended licence as acceptable, not acceptable without
    the pre-adverse notice, a reason of 2 characters;
  - recorded: CDL A, HN, acceptable, due in a year;
  - the CDL in Qualifications with its expiry, detail and evidence;
  - the medical certificate verified until 2035 not shortened to 2027;
  - the qualification's and the person's histories;
  - not recorded twice;
  - a suspended licence not acceptable with its notice, writing no CDL but
    keeping the medical certificate.
- **The Clearinghouse:**
  - a limited query on the kept consent, opening the Clearinghouse;
  - information found refused as acceptable;
  - recorded for review, opening the full query (on the portal consent,
    with the same licence), shown as following it;
  - prohibited refused as review, recorded not acceptable without a
    notice.
- **PSP:** more out-of-service violations than inspections refused;
  recorded, and not due again.
- **Due dates:**
  - current, then due within the month, then overdue;
  - the workflows tell the recruiters once, and not when a new check
    waits.
- **Cancelling** needs a reason.
- **Links:** HTTPS only, administrators only; the others keep their sites.
- **Access:** Logistics cannot open the page.
- **Screens:** the person's page links to the records; French.

Also:

- **The access matrix:** *Driving records* refused to Logistics, payroll,
  supervisors, workers and clients.
- **The CSRF sweep:** requesting, recording, the links.

### Results

Full isolated run: **2879 PASS, 0 FAIL** (browser step skipped), with
P5-M08. That is 122 checks more than P5-M07's 2757: this module's 58 and
P5-M08's 64.

Mutation, on the module's own tests (the fast harness, not the whole
suite): I let a licence that is not valid be recorded as acceptable. The
tests failed on "An MVR result with a suspended licence recorded as
acceptable is refused", after 23 passes.

## Not done here

- **Calling MVRNOW, the Clearinghouse or PSP from Crewvia:** none offers
  the agency an API. If MVRNOW provides one on request, only the request
  and the result import change.
- **Keeping the provider's report file** (PDF) in Crewvia: the provider's
  reference is kept, and the report stays in the provider's account.
- **The 3-year employment history inquiry** of previous DOT employers, and
  the yearly driver's certification of violations.
- **Real accounts:** no MVRNOW, Clearinghouse or PSP account was used.
