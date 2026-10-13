# P5-M16 — T25: the Executive overview, grouped above the project table

The owner's request, 13 Oct 2026 (BACKLOG T25, requirement R40: simple,
roughly 10 to 18 screens): better grouping above the project table.

## What was found before building

- **Above the table:** one flat row of four numbers.
  - on site;
  - still to place;
  - crew cost a week;
  - things needing somebody.
- **"Things needing somebody"** was a single total; what it was made of was
  only visible project by project, in the table.
- **Not shown at all:**
  - the hiring pipeline;
  - the margin's share of billing;
  - the work waiting on the newer screens: lateness disputes (P5-M14),
    open hotel folios (P5-M15), overdue bills, open safety incidents.
- **The table** listed every project, with no way to see one stage.

## What was built

Three titled groups above the table, each leading to where the work is
done. The figures come from the same functions as before
(`project_figures`, `project_exceptions`), so the overview and each
project can never disagree.

- **People:**
  - on site now;
  - confirmed, travelling or on site;
  - still to place, against the headcount asked for;
  - a fill bar with the percentage;
  - **the hiring line:** new applicants this week, applicants being
    reviewed, offers out, open requisitions, leading to Hiring.
- **This week's money:** crew cost, billed to clients, and the margin with
  its share of billing.
  - Shown in whole dollars, so the three amounts fit side by side; the
    table keeps the cents.
  - A link to Project costs.
- **Needs somebody today,** with its total in the heading. Each kind of
  problem is added up across the running projects ("4 with no bed on 2
  projects") instead of once per project, then come:
  - open safety incidents (Safety & learning);
  - lateness disputes to settle (Lateness);
  - hotel folios still open (Hotel folios);
  - bills past due (Payables).

  Red ones come first, then the largest. When there is nothing: "Nothing
  waiting. Every project is clear."
- **Stage chips** above the table (All, then each stage that has projects,
  with counts) narrow the table to one stage. An unknown stage shows
  everything.

Styles are in `public/assets/crewvia-ui.css` (now `?v=2`). The groups stack
into one column below 1,100 px.

**Data:** none. No table or column changes, so no migration and no
rollback.

**Translations:** 20 new strings in French and Spanish.

## Tests

- **`tests/overview_groups_db.php`:** an administrator, a running project
  asking for 3 people with an open requisition, and a finished project.
- **`tests/overview_groups_http.py`:** other suites share this database,
  so each check adds one known thing and watches exactly that figure move.
  - the three groups and the chips are there;
  - **two people placed, one on site:**
    - on site +1, placed +2, still to place -2;
    - crew cost +$2,400, billing +$4,000, margin +$1,600 at 40 guaranteed
      hours;
  - three applicants: new this week +3, reviewed +1, offers +1, with the
    link to Hiring;
  - one more of each of the four new waiting items, each with its own
    link;
  - the heading equals the sum of what is listed;
  - red first;
  - the chips: all projects, then closed only, then on site only, then an
    unknown stage;
  - French.
- **`tests/overview_groups_probe.php`:** adds each of those things.
- **Mutation:** with the lateness disputes removed from the list, the test
  failed at "One more lateness disputes to settle, leading to /lateness".
  Restored afterwards.

## Not done

- **Charts or trends over time:** these were not added. `/analytics` and
  `/reports` keep them.
- **The project table's own columns** are unchanged.
- **Links for project problems** (no bed, travel unbooked…) still open the
  page for the working project, as the table's own links do.
