# P5-M33 — identity documents, and the W-4 signed in person

The owner's flow, 14 Oct 2026:
- **W-4:** signed in person, with a signing code the coordinator generates or
  a QR code on site.
- **Identity documents** (citizenship, SSN, two IDs) are scanned and
  encrypted. Only the VP or authorized people can see them, and the
  coordinator loses access after validation.

## Found before building

- **Storage was already right.** Documents are encrypted at rest with the
  workspace key, checked against their fingerprint before being served, and
  every download is logged.
- **Access was not:**
  - anyone on the recruiting desk could open any document, a passport or a
    Social Security card included, forever;
  - nobody could be given access without also getting the recruiting desk.
- **The W-4** was a file the worker uploaded and ticked. There was no record
  that it was signed in front of anyone.

## What was built

**Identity documents.** A document whose type names a passport, Social
Security card, driver's license or state ID, birth certificate, citizenship
or naturalization papers, permanent resident card, visa or work permit, or an
I-9 is restricted.
- While it waits for validation, the office (recruiting) opens it to check
  it, as before.
- Once validated (approved or rejected), only these can open it:
  - the worker, their own;
  - an administrator (the VPs);
  - the staff an administrator authorizes on Documents → "Who may open
    identity documents".
- The coordinator who checked it no longer can. Their screen says
  "Restricted since it was validated: an administrator can open it."
- Each refused opening is logged.
- The upgrade restricts the identity documents already stored, and says how
  many.
- Other documents (receipts, W-4s, certificates) are as before.

**The W-4 signed in person (Employment):**
1. The coordinator, with the worker in front of them, scans the completed
   W-4 under Documents. Then they make a signing code.
2. The code (8 digits, valid 15 minutes, working once) is shown once on the
   coordinator's screen, with its QR code. Only its hash is stored.
3. The worker enters it, or scans the QR code, which opens their own portal
   with the code filled in. They choose their scanned W-4 and confirm they
   completed and signed it themselves.
4. The record shows:
   - the W-4 submitted;
   - the time;
   - who witnessed it ("witnessed by …");
   - the coordinator told.
5. A wrong or used code is refused. Five tries in 15 minutes at most.
6. Staff cannot sign for the worker.

**Data:** one reversible migration (install/rollback/p5-m33.sql):
- `identity_readers`;
- `signing_codes`;
- `worker_documents.restricted`;
- the W-4's in-person time and witness.

## For the owner

- **Payroll** keeps opening W-4s and payroll forms. A W-4 is not an identity
  document here: payroll enters its withholding.
- **Classification.** It reads the document's type as typed. A passport filed
  as "Scan 1" is not restricted. The office should name documents for what
  they are.
- **A QR code on site** for a whole crew (one poster) is not built. Each code
  belongs to one worker, witnessed by one coordinator.

## Tests

- **`tests/identity_docs_db.php`:**
  - rollback keeps every document;
  - upgrade restricts the stored identity documents (an SSN card and a
    license, not a receipt or a W-4);
  - silence on a repeat;
  - the fixture.
- **`tests/identity_docs_http.py`:**
  - a passport restricted, a certificate not;
  - the coordinator and the office opening it while it waits;
  - validated: both refused, refusals logged, the screen saying so, the
    certificate still opening;
  - the administrator and the worker opening it;
  - payroll refused, then authorized and opening it, then withdrawn;
  - a recruiter unable to authorize;
  - the code once with its QR code, hash only, 15 minutes;
  - staff unable to sign;
  - the QR link filling the code;
  - a wrong code refused;
  - signed with time and witness, the coordinator told;
  - the code working once;
  - Employment showing the witness.
- **Mutation:** with the coordinator keeping access after validation, the
  test fails.
