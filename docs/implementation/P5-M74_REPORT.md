# P5-M74 — requisitions one per line, Cancel on every form opened by a button, "Fix" that leads somewhere

RSS, 17 Oct 2026:
- "Just the lines, and to add a new one, click the button… the QR code is too
  big… I just want a link, copy."
- "There needs to be an X… cancel, just in case I clicked it and I don't
  need it."
- "It says the agreement says what the work is… Fix doesn't take me
  anywhere."

## Requisitions

**One line per requisition**:
- the title, with its trade, place, shift and start date;
- its pay per hour, and what is billed;
- a progress bar ("0 of 6 placed");
- the applications received;
- its status;
- **Copy link**, which puts the application link on the clipboard;
- **QR code & more**, a small panel next to the button with:
  - the QR code;
  - the public page;
  - the poster;
  - the scope;
  - Close and Remove.

**+ Add a requisition** opens the form. The form is open only when the
project has no requisition yet. Its button is now **Create the requisition**.

The candidate journey is folded under its title.

## Cancel

Every form opened by a button has **Cancel**, which empties it and folds it
again:
- requisition;
- trade;
- project;
- client;
- contact.

## "Fix" on the project's stage

The signal is now "The project says what the work is". Its **Fix** opens the
folded project form at the work description. Before, it reloaded the page.
Every other link to a section of the plant opens it the same way: scope,
shifts, client contacts, billing terms.

## Tests

`tests/client_contacts_http.py` covers:
- the form open when there is no requisition, with Cancel;
- a requisition created, then shown as one line with Copy link and the QR
  code on demand, the form folded;
- Cancel on the plant, Projects and a client.

`tests/browser_tests.cjs` waits for the QR code to be in the page, not
visible: it is in a folded panel.

Checked in the local preview:
- the lines;
- the panel opening above the table;
- Cancel;
- Fix → the description.
