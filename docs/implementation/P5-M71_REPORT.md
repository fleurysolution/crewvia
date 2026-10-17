# P5-M71 — client contacts, who receives the invoices, the site address, projects not agreements

Asked by RSS, 17 Oct 2026, going through Crewvia with the owner.

## Client contacts

"Each client could have multiple points of contact… one main point of
contact… in the projects, select who from that company is in charge."

**Clients → a client → Contacts at the client.** This is the client's
directory:
- Each contact has a name, title or role, email, phone and notes.
- One contact is the **main point of contact**. "Make main" changes it.
- Contacts are added, edited and removed here. A removed contact leaves the
  projects they were on.
- A contact needs an email or a phone number.

The contact given when the client is added becomes its first, main contact.

## Who receives the invoices

"You could select which person is set to billing email."

- Tick **Receives the invoices** on any contact. That contact must have an
  email.
- The client's invoice email becomes those contacts' emails.
- The client form then shows **Invoices go to**, which is chosen in the
  contacts.
- With no contact ticked, the email typed on the client stays in use.

## Contacts on each project

The plant has a new card, **Client contacts on this project**:
- Tick the client's contacts who are on the project, and choose the one
  **In charge**.
- The one in charge is shown on the plant ("In charge at the client") and on
  the client's list of projects.

## The invoice

The printed invoice gains a **Bill to** block:
- the client and its address;
- **Attention**: the contacts receiving the invoices, or the invoice email;
- the project's contact in charge;
- the terms (net days) and the due date once issued.

It carries no Fleury logo: it is RSS's document to its own client.

## Site address → position, city, state, ZIP

"Give the system the site address, and the latitude and longitude pull
automatically."

- Projects (Take on a project) and the plant now have a **Site address**
  field. When the address is typed, or on **Find the address**, the app fills
  the city, state, ZIP and the position used for the clock-in. The latitude
  and longitude stay visible so they can be checked, and "My position here"
  still works on site.
- The lookup is `GET /geocode` (administrators):
  - with `google_maps_key` in the configuration, it uses Google's Geocoding
    API;
  - without it, it uses the US Census Bureau geocoder, which needs no key.
    Checked live: 401 Broadway, Gary IN → 41.6038, -87.3371.
- Only the address is sent.

## Projects, not agreements

"The agreement's already signed… this is just for execution and tracking."

- The labels change:
  - "Create the agreement" becomes **Create the project**;
  - "NEW AGREEMENT" becomes **NEW PROJECT**;
  - "Agreements" becomes **Projects**;
  - "The agreement" becomes **The project**;
  - "What the agreement covers" becomes **What the contract covers**.
- The intro says the contract is signed before the work comes into Crewvia.
- **End date unknown (ongoing)** is a checkbox on Projects and on the plant.
  The project runs, and is billed, until it is closed. The plant shows
  "ongoing, no end date".

## Disciplines

"We need to be able to add more disciplines."

- Under the Discipline list of a scope line there is **+ Add a discipline**.
  The new discipline is added to the catalogue (the same one as Settings →
  Trades) and chosen at once, without losing what was typed on the line.
- A retired discipline typed again comes back.

## Data

Reversed by `install/rollback/p5-m71.sql`:
- `client_contacts`;
- `job_contacts` (job, contact, in_charge);
- `jobs.site_address`.

The upgrade turns each client's existing contact into its main contact. An
existing billing email different from that contact becomes an "Accounts
payable" contact receiving the invoices.

## Tests

`tests/client_contacts_http.py` covers:
- the main contact at creation, and the refusals (no email or phone; invoices
  without an email);
- contacts added, edited, made main and removed;
- another client's contact refused;
- where invoices go;
- the project's contacts and the one in charge, shown on the plant and on the
  client; the one in charge is added even when not ticked;
- the site address kept;
- the address lookup: a short address refused, and a recruiter refused;
- a discipline added from the scope line (by the page and by fetch), and a
  discipline without a name refused.

`tests/geocode_db.php` covers, with stand-in answers:
- Census and Google results read into position, city, state and ZIP;
- only the address sent;
- an address not found;
- a short address never sent.
