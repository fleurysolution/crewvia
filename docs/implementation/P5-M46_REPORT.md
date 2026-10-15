# P5-M46 — A printable job poster with a QR code that opens the application page

The owner's flow: "a candidate can apply by scanning the QR code of the job poster, on the website
or posted elsewhere." Measured before this module: each requisition drew a QR code on the
Requisitions screen, for `location.origin + /apply?id=…` (the address the office happened to be
browsing from, so a code drawn from a test or a local copy pointed there); there was no poster,
no print layout, and no way to take the QR code away as an image.

## What changes

- **Job poster** (`/poster?id=REQUISITION`, recruiting desk only), linked from Hiring ("Job
  poster", on each requisition) and from Requisitions ("Print the job poster with its QR code"):
  a page of its own, sized for a Letter sheet, with no menu: the agency, "Now hiring", the job, the
  place and when it starts, the facts in large type (pay an hour, per diem, guaranteed hours,
  openings; the scope line's terms when the requisition has none of its own), what it asks, and
  the QR code with "Scan to apply" and the address written out. A bar (hidden when printing)
  prints it, saves the QR code as a PNG image, opens the application page, goes back to Hiring.
- **The QR code alone** (`&qr=1`), large, to put on a website or a social post.
- **The QR codes encode the site's configured address** (`app_url`), on the poster and on the
  Requisitions screen alike, so whatever is printed always opens the real application page. The
  code is drawn in the browser by the bundled library; nothing is sent elsewhere.

Whoever scans it lands on the application page of P5-M45: the facts, the form, when they can
start, and their portal on the spot.

## Changes

- `app/pages/poster.php`, `app/views/poster.php` (new).
- `app/views/hiring.php`, `app/views/requisitions.php`: the links; the Requisitions QR address.
- `public/index.php`: `/poster`.
- `app/lang/messages.tsv`: 13 FR/ES strings.
- `tests/page_pass_http.py`: the poster joins the pages that stand on their own (the print pages,
  activation), outside the standard header, as the first full-suite run asked.

No table changes, so no migration and no rollback.

## Tests

- `tests/poster_http.py` (9): the links from Hiring and Requisitions; the Requisitions QR code on
  the configured address; the poster is its own page without the menu; the facts; the QR code's
  address; print and save-as-image; the QR code alone; an unknown requisition; a supervisor kept
  out.

Measured on the harness: poster 9 PASS after the walkthrough chain and the P5-M42 to M45 tests.
In the browser: the QR code is drawn (a canvas), and the save link carries the PNG image. Full
suite: see the commit message.
