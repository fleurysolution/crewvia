# P5-M12 — T8: filter by state, city and distance from the site

The owner's request, 12 Oct 2026 (BACKLOG T8, requirement R20), with these
decisions:

- **Distance data:** download the US Census Bureau's public gazetteer
  (places and ZIP codes) and bundle it, so nothing is sent to a map
  service.
- **Where:** Candidates (the talent pool), and Hiring (applicants and
  talent pool matches).

## What was found before building

- **Projects** carry a site city and a two-letter state.
- **Candidates** carry a city and a free-text state ("TX", "tx", "Texas").
- **Nothing** had a ZIP code or coordinates.
- **The only location search** was the Candidates text box, which matched
  part of a city name.
- **Five screens write a candidate's address:** the profile, the
  spreadsheet import, the public application, the worker's self-service
  change (after validation), and client orders.

## What was built

**The data** (`install/gazetteer/`, public domain):

- `us-places.tsv` (32,114 places): every Census place with its
  coordinates, keyed by state and name. The Census descriptor ("city",
  "CDP", "(balance)") is removed. Consolidated governments also answer to
  their first name, so "Nashville-Davidson metropolitan government" is
  found as "Nashville".
- `us-zips.tsv` (33,791 ZIP codes), with coordinates.
- `build-gazetteer.py` rebuilds both from the Census's 2023 files, with
  their addresses in its header.

They are kept apart from `install/data/`, which git ignores because it
holds real applicants' records.

**Where a person or a site is** (`app/geo.php`):

1. A **ZIP code** when one is given: five digits, or ZIP+4.
2. Otherwise the **city and state**. The state is read as a code or a
   name, in any case. "St." and "Saint", "Ft." and "Fort", and "Mt." and
   "Mount" are the same place.
3. Otherwise **nowhere**: an unknown city is left unplaced, never
   guessed.

A candidate's coordinates are kept on the candidate, with what they were
worked out from. Whenever the city, state or ZIP no longer matches, the
next Hiring page, or Candidates search by place, works them out again.
That covers every screen and import that changes an address, without
touching them. The upgrade places everyone once.

Distances are great-circle miles, filtered first by a box the index on the
coordinates narrows. Houston to Dallas measures about 225 miles.

**Candidates** (a second row in the filter card):

- **State:** a list of the 52 Census areas (50 states, DC and Puerto
  Rico), matched against the state however it was typed.
- **City:** part of the name.
- **Within 25 / 50 / 100 / 250 / 500 miles** of a project's site, or of a
  ZIP code.

With a project or a ZIP code chosen, the list is ordered nearest first and
shows "≈ N mi" under each place. A project whose site cannot be placed
says so, and asks for a city and state or a ZIP code.

**Hiring:**

- **Applicants:**
  - "Within N miles of the site" (the requisition's project site);
  - a "Nearest the site" sort;
  - "≈ N mi" on each row and in the split review's list.
- **Talent pool matches:** the same filter, and nearest first after past
  workers.
- The stage tabs keep the distance chosen.
- A site with no location says "No distance: the project's site has no
  city and state or ZIP code."

**ZIP codes** can now be entered:

- on the candidate's profile (a wrong one is refused);
- on a new project and on the project page. There, one that is not a ZIP
  code is left empty, as other optional fields are.

**Data:** `install/geo.sql` and a guarded step in `install/upgrade.php`.

- **New tables:** `geo_places` and `geo_zips`, loaded once in batches of
  1,000.
- **New candidate columns:** `zip`, `geo_lat`, `geo_lng`, `geo_from` (zip
  or city), `geo_state` and `geo_basis`, with an index on the
  coordinates.
- **New project column:** `jobs.site_zip`.
- **The first upgrade prints:** "Locations: 32,114 places and 33,791 ZIP
  codes (US Census 2023); X of Y candidate(s) and A of B project site(s)
  placed from their city, state or ZIP." A repeat is silent.
- **Rollback:** `install/rollback/p5-m12.sql`. The typed ZIP codes and the
  coordinates are lost; candidates and projects are kept.

**Translations:** 14 new strings in French and Spanish.

## Tests

- **`tests/geo_db.php`:**
  - rollback (everyone kept), then upgrade with every row loaded and
    announced, then a repeat that is silent and loads nothing twice;
  - states read as codes and names;
  - "St."/"Saint" and "Ft."/"Fort";
  - a city found, a ZIP code winning over the city (ZIP+4 too), and an
    unknown place not invented;
  - Houston to Dallas about 225 miles;
  - the fixture: a site in Katy, TX, a site with no location, and people
    in Houston, Austin ("Texas"), Dallas ("tx"), a Katy ZIP code, an
    unknown city, and St. Louis.
- **`tests/geo_http.py`:**
  - **Candidates:**
    - the state filter, typed either way;
    - the new people placed when the list opens;
    - the city filter;
    - within 50 miles of the Katy site, nearest first, with the distance
      shown;
    - within 250 miles of a ZIP code;
    - distance sorting without a radius;
    - an unplaceable site saying so.
  - **Hiring:**
    - the filter and sort offered;
    - each applicant's distance, none for an unknown city;
    - within 50 miles;
    - nearest first with the unplaced last;
    - the stage tabs keeping the radius;
    - matches within 250 miles, and without a radius;
    - a site with no location explained.
  - **Forms and translation:**
    - a wrong ZIP code refused, a right one kept;
    - a changed ZIP code placing the person again, now within 50 miles;
    - the ZIP code on a new project, and a bad one left empty;
    - Spanish.
- **`tests/geo_probe.php`:** reads the fixture's people and the projects.
- **Hiring (P5-M10) tests** re-run: 56 of 56.
- **Mutation:** when the refresh only placed people never placed before,
  the test failed at "and the next search places them by it: now within
  50 miles of Katy". Restored afterwards.

## Not done

- **Addresses outside the United States** are not placed.
- **Driving distance:** distance is a straight line, not road miles or
  driving time.
- **ZIP codes elsewhere:** no ZIP code field on the public application,
  the import or the worker's self-service. Those people are placed by city
  and state.
- **Places the Census does not list** (a neighbourhood, a misspelling) are
  left unplaced. A ZIP code on the profile places them.
