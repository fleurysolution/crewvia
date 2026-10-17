# P5-M65 — forms filled beforehand, signed in person; copies of the I-9 documents; E-Verify ready

The owner, 17 Oct 2026:
- The employee fills everything beforehand.
- At the office, the coordinator finds them, opens their forms, checks them,
  and only then lets them sign, on a keypad or their phone.
- The original documents are compared with what is in the file. A document the
  employee did not scan, the office scans.
- RSS keeps copies of everyone's documents.
- E-Verify may be used later. It must be ready, and must not block anything
  meanwhile.
- The signature is in person only: it is safer, and it prevents fraud.

## Two federal rules the design keeps

- **The employee chooses the documents.** Any document, or combination of
  documents, from the Lists of Acceptable Documents: List A, or List B and
  List C. Asking for a particular document, for instance the Green Card of
  someone who ticked box 3, is document abuse, an unfair immigration-related
  employment practice. Sending photos beforehand is therefore **optional**,
  never tied to the box ticked, and the screens say so.
- **A photo never replaces the original.** The office examines the originals in
  person, within three business days of the first day.

## The flow

1. **Beforehand, on the employee's phone.**
   - The W-4 and Section 1 of the I-9 are filled and **saved**, not signed:
     status `draft`.
   - The draft can be changed until signed. The SSN and the document number
     already given stay unless typed again.
   - With the I-9, the employee may send photos of the documents they chose,
     with the list and the title (the official lists are suggested).
   - The task reads "Filled in, to sign at the office".
2. **At the office.** Change requests → "Forms filled in, to sign at the
   office" (with a search box) → the person.
   - The coordinator sees their W-4 and I-9 (SSN by its last four), and the
     photos they sent.
   - The coordinator clicks **Open the signature**. A one-time code (and its QR
     code), valid 15 minutes, is shown on the coordinator's screen only.
3. **The employee signs on their own phone.**
   - They scan the QR code, or open "I am at the office: sign" in their
     portal and enter the code.
   - They read each form again, tick each official statement, type their name
     and **draw their signature** with a finger.
   - All the drafts are signed at once. Each record keeps the witness (the
     coordinator who made the code), the time, the address and the encrypted
     drawn signature, and the hash covers all of it.
   - The code works once. Wrong codes are counted (10 tries in 15 minutes per
     account), outside the transaction, so a refused try still counts.
4. **Section 2, with the originals.** On the I-9, the office:
   - examines the originals and compares them with the photos (opened from the
     page, each look recorded);
   - photographs any original without a copy;
   - completes Section 2.

   Crewvia compares before accepting:
   - **refused:**
     - an expired document;
     - a document examined without a copy kept (RSS keeps copies for
       everyone);
     - no tick that the names match Section 1;
   - **warned** (the office ticks that it reviewed the difference):
     - a Permanent Resident Card while box 3 is not attested;
     - an EAD while box 4 is not;
     - a U.S. passport while box 1 or 2 is not;
     - an A-Number that does not appear in the card number;
     - an EAD date different from the "authorized until" of Section 1.

   On acceptance, the copies are validated: from then on, only administrators
   and authorized readers open them (P5-M33).
5. **The official PDFs** (P5-M64c) now carry the drawn signature beside the
   typed one: "/s/ name (signed in person)".

**E-Verify.** It is off by default and blocks nothing.
- An administrator turns it on under Employment ("This employer uses
  E-Verify").
- When it is on, Section 1 requires the SSN.
- After Section 2, the person's employment record shows the date to open the
  case by (the third business day after the first day).
- The office records the case number and its result: Open, Employment
  Authorized, TNC, Case in Continuance, Final Nonconfirmation, Close Case and
  Resubmit, or Closed.

**Forms signed from the portal before this module.** They had been signed
without a witness and had not been checked yet. They go back to "filled in, to
sign at the office": the upgrade line counts them. An accepted form stays as it
is.

## Data

Reversible (install/rollback/p5-m65.sql):

`worker_tax_forms`:
- status `draft`;
- signer fields nullable until the signature;
- `witness_id`;
- `encrypted_signature`;
- `everify_case`, `everify_result`, `everify_by`, `everify_at`.

Other:
- `i9_document_copies`, linking each copy (a restricted, encrypted
  `worker_documents` file) to its list and title;
- setting `everify_enabled`.

## Tests

`tests/tax_forms_http.py`:

**W-4**
- a draft is saved encrypted and unsigned;
- changed without retyping the SSN;
- the task says to sign at the office.

**Signing in person**
- no signature without the coordinator's code;
- the coordinator finds the person and opens the signature (masked SSN, QR);
- these are refused: a wrong name, no drawn signature;
- signed: witnessed, drawn signature encrypted, hash intact;
- a code works once.

**I-9 and Section 2**
- saved with a photo the worker chose (restricted, encrypted on disk);
- the coordinator opens the photo;
- signed in person;
- Section 2 is refused without a copy of the List C document;
- the office photographs it;
- these are refused: an expired document; acceptance without "names match";
- accepted: copies validated.

**E-Verify**
- off by default;
- on: the date to open the case is shown;
- case recorded;
- the I-9 requires the SSN.

**Unchanged**: the official PDFs, payroll's W-4 in ADP, the office's I-9,
direct deposit.
