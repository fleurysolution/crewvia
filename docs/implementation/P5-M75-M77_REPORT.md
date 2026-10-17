# P5-M75 to M77 — the rate override as a button, hotels per project, client sites, professional wording

RSS, 17 Oct 2026, with the owner.

## P5-M75 — the rate override is a button

On a requisition, pay, bill rate, per diem and guarantee come from the line
of the scope. **Override the agreed rates** is a button that opens the four
fields. **Keep the rates of the line** empties them and closes them again.

## P5-M76 — the hotels of a project

"A project could have multiple hotels… the system needs to capture all of
those."

**Hotels → this project's hotels.** Each hotel of the project has:
- its rooms held for this project;
- the dates of the block;
- its cost per night;
- optionally, its price billed per night.

The table shows the rooms held, taken and left. Each hotel is edited in
place, and can be removed from the project as long as nobody is booked
there.

**+ Add a hotel to this project** takes a hotel already in the directory, or
a new one. A hotel added while a project is open is linked to it.

**Booking a room:**
- the project's hotels are listed first, with the rooms left;
- the booking counts against the project's block;
- the cost per night is the project's rate at that hotel.

**Billing** gains **the rate agreed for each hotel**: each night is billed
at the price set on its hotel, or at cost where none is set.

Upgrade: the hotels a project's crew already sleeps in become that project's
hotels. Reversed by `install/rollback/p5-m76.sql`.

## P5-M77 — each client's sites

"Does it save it as a site somewhere?"

- **Each project's site is kept on its client**: name, address, city, state,
  ZIP, position and radius. The same address is updated, not doubled.
- **On a new project**, a site of the chosen client fills in the site fields.
- **The client's page lists its sites**, with **New project here** and
  **Remove**.

Upgrade: the existing projects' sites are kept on their clients. Reversed by
`install/rollback/p5-m77.sql`.

## Professional wording

"These notes are ours; the client should not read back what they told us to
build the system."

The subtitles and hints that explained the design were rewritten as short,
neutral text on these screens:
- Projects, Clients, the plant and its sections (billing, scope, shifts,
  internal notes);
- Requisitions;
- the address and radius hints.

For example:
- Projects: "Your active and planned projects."
- Requisitions: "Openings for the current project."

## Tests

`tests/billing_terms_db.php` covers:
- a hotel linked with its block, cost and price;
- a block smaller than the crew already there refused;
- billing at the rate agreed for each hotel: 7 nights at $140;
- a hotel with the crew in it that cannot be removed.

`tests/client_contacts_http.py` covers:
- a hotel added with a project open is linked with its block;
- the project's hotels listed;
- the block changed;
- a hotel removed;
- the project's site kept on its client and offered for the next project;
- the client's sites listed and one removed.
