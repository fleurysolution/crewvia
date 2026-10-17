# P5-M73 — less scrolling: sections folded, lists first, the strike switch on top, bookmarks and recent screens

RSS, 17 Oct 2026, going through Crewvia:
- "Collapse everything… so that we reduce the scrolling."
- "The strike switch… good for it to be at the top."
- "When I click Projects, I want to see my projects… if you want to add a new
  project, click the button."
- "After I added a contact, the add contact should go away."
- "Some addresses are different… you need to confirm."
- "Bookmarks on the left menu… and recents, like QuickBooks."

## The plant

Each section folds to its title and a one-line summary. A click opens it.
- The scope of work stays open. The other sections start folded, each with
  its summary:
  - a full week of the scope (its amount);
  - the crew;
  - shifts (their names);
  - client contacts (who is in charge);
  - what the client is billed ("terms set", or every hour at the bill rate);
  - the project (address and dates);
  - internal notes.
- Each section stays as the person left it, in their browser.
- A link to a section, for example after saving the billing terms, opens it.

## The strike switch

The strike switch is at the top of the plant:
- "Declare the strike live", or "Strike is live – stand it down".
- A confirmation is asked first. The change is logged and its effect on the
  weekly amount is shown.
- Saving the project no longer touches the strike.

## Lists first

- **Projects** shows the projects. **+ New project** opens the form. The form
  is open when there is no project yet, and when coming from a client.
- **Clients** shows the clients. **+ Add a client** opens the form.
- On a client, **+ Add a contact** is a button once the client has a contact.

## The site address is confirmed

What the lookup finds is shown first, with **Use this address** and **Not
this one**. Nothing is filled in until it is confirmed, so a near match never
moves the geofence by itself.

The radius says it is in metres: 300 m is about 1,000 ft. Clocking in farther
away is allowed, and the supervisor is told.

## Bookmarks and Recent

At the top of the left menu:
- **Bookmarks**: "+ Bookmark this screen" on any screen of the menu. Click it
  again to remove the bookmark.
- **Recent**: the last five screens opened.

Each person has their own. Only screens of their own menu are kept, so a link
never leads to a refusal.

Data: `user_nav_marks`, reversed by `install/rollback/p5-m73.sql`.

## Tests

`tests/client_contacts_http.py` covers:
- the plant's folded sections, with the scope open;
- the strike switch above the scope; declared and stood down from the top;
  saving the project keeps it;
- "+ Add a contact" folded once there is a contact;
- Projects and Clients showing the list first, and the form open when coming
  from a client;
- Recent listing a screen opened, and keeping at most five;
- a bookmark added and removed;
- a screen outside the person's menu refused as a bookmark.

Checked in the local preview:
- the folded plant;
- the address confirmed before anything is filled in;
- Bookmarks and Recent in the menu;
- the list first on Projects and on Clients.
