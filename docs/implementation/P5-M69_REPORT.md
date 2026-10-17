# P5-M69 — clients first, then their projects

The owner, 17 Oct 2026, starting the rehearsal from the real beginning:
"Before a project you have to have a client."

The client was only a name typed on Projects; a new name made a new client.
Now:

- **Clients** (`/clients`, administrators; More → Clients):
  - **Add a client** with its address, contact person, email and phone, the
    email for invoices, payment terms in days, and notes.
  - **No duplicates.** The same name twice, whatever the case, is refused and
    points to the existing client.
  - **Each client's page.** It can be edited, it lists its projects, and it
    has **Take on a project for this client**.
- **Projects** chooses the client from the list, with an "Add a client" link.
  Coming from a client's page, that client is chosen. A typed name, from older
  forms and imports, still finds or makes the client.
- **The working-project bar** keeps you on the screen you are on when you
  switch project. It used to send you to the dashboard: found during the
  rehearsal, on Requisitions.

Data: clients gain address, city, state, ZIP, billing email, notes and
created_by (install/rollback/p5-m69.sql).

Tests (`tests/clients_http.py`):
- a client added with its terms;
- the same name twice refused;
- chosen on Projects;
- the project is that client's;
- the client's page lists it;
- an update;
- the project bar keeps the screen;
- a recruiter refused.

Full suite: 3,920 PASS, 0 failures.
