# P5-M23 — layout walkthrough, wave 4: the page-by-page pass

The owner's decision, 13 Oct 2026: a page-by-page pass, busiest pages first.
This is the last of the four waves.

## Found before building

Measured on the version then live (1fc63fc):

- **17 pages on their own header,** the ones wave 1 left (the pending list
  in `tests/layout_http.py`). Three more opened a title outside the
  standard header: Dashboards, Reports and the placement page.
- **652 inline styles in the views.** On the 39 busiest pages (each role's
  daily screens), 273 elements carried one. Most were a handful of values
  repeated: `flex:2` 45 times, `margin:0` 34, seven nearby top margins, inline
  forms, wrapped text, a card's top strip 19 times.
- **20 hand-written section labels above titles** that restated or misstated
  the menu:
  - Projects was labelled "AGREEMENTS";
  - Requisitions, Distribution channels and AI Review said
    "RECRUITMENT / …" where the menu says "Recruiting";
  - a worker's Contracts said "HIRING" where their own menu says
    "My papers".
- **Counts that read like a draft:**
  - "1 people out across 3 jobs" on the Executive overview;
  - "(s)" on the homes and the daily screens: "1 onboarding task(s) to
    complete", "1 open job(s) fit your file", "1 night(s) at".
- **English left in other languages:**
  - Hours said "Week ending Monday 12 October" and "50-hour guarantee";
  - the employee folder's title was "Employee folder" in every language;
  - "rehire" on the employee folder;
  - "Welcome" on the account activation page;
  - the worker's assignment showed the raw status, "on_site".

## What was built

**One header on every page.**
- The 17 pending pages, and Dashboards, Reports and the placement page,
  open with the standard header.
- The pending list is now empty.
- Only five pages keep a title outside it, each named in the test:
  - three print documents, which are their own page;
  - two public pages whose title is inside a form card.

**One back link.** Every "← …" link above a detail page uses the shared
`back-link` style (17 links, plus 3 by hand), instead of its own styled
paragraph.

**Helper classes instead of inline styles** (`crewvia-ui.css`):
- **Spacing:** `m0`, and the margin scale `mt-xs`/`mt-s`/`mt`/`mt-l` and
  `mb-s`/`mb`/`mb-l` (4, 8, 12, 16px). These replace seven nearby
  margins (4 to 20px), so pages move by a few pixels at most.
- **Layout and text:** `f2`, `inline`, `w-auto`, `pre-wrap`, `wrap-any`,
  `nowrap`.
- **Colour:** `t-amber`, `t-red`, `t-green`, `faded`.
- **Blocks:** `card-bar` (a card's top strip), `row-between`.
- They are marked important, because each replaces an inline style, which
  outranked the page's own rules.
- 343 exact values were replaced by script, and 15 colours chosen by a
  condition now choose a class.
- **Views:** 652 inline styles down to 294.
- **Measured** on the 39 busiest pages: 273 styled elements down to 116.
  Before and after, no page scrolls sideways at 390px and none shows a PHP
  error.
- What is left is mostly one-off or data-driven, such as a progress bar's
  width.

**Section labels in the reader's words.**
- The 20 restating labels are gone, so the layout prints the page's
  section from the reader's own menu.
- **Kept:** the 7 that carry information:
  - the candidate's discipline;
  - the project's client;
  - the Executive overview's "Every project";
  - the public and error pages.

**Counts in words.**
- `tn()`/`ten()` in `app/i18n.php` pick the singular or the plural
  catalogue entry by the reader's language rule. French says
  "0 demande", English and Spanish "0 requests".
- **Converted:**
  - every "(s)" count and always-plural count on the role homes and the
    daily screens: Today, the portal, My team, the Executive overview,
    Activity, Hiring, Hotel folios, Settings;
  - the portal's waiting list.
- 58 new strings in French and Spanish.

**Also:**
- Hours: the week and the guarantee in words, in every language.
- The employee folder: a translated title; the employee number and the
  Contracts button in the header.
- The activation page's welcome is translated.
- The worker's assignment shows its status in words ("On site").

**Data:** no data changes, no migration.

## Left, measured

- **13 "(s)" counts** on less-used pages:
  - Report builder, Driving records, Surveys, Workload, Recognition,
    Project board, Approval chains, Advances, the employee folder;
  - Driving records puts several counts in one sentence, which needs its
    own wording.
- **294 inline styles,** mostly one-offs.
- **Ceilings:** both numbers are held by the test and can only go down.

## Tests

- **`tests/page_pass_http.py`** (new), reading the views as source:
  - every title in the standard header, except the five named pages;
  - every back link shared;
  - inline styles at most 294;
  - "(s)" counts at most 13;
  - every helper class defined.
- **`tests/page_pass_http.py`,** through `tests/page_pass_probe.php` in
  English, French and Spanish: 0, 1 and 2 by each language's rule, and
  `ten()` escaping.
- **`tests/page_pass_http.py`,** through the screens:
  - Projects and Requisitions labelled with their menu section;
  - a worker's Contracts with "My papers";
  - Hours in English and French;
  - seven of the converted pages;
  - the worker's portal counting in words, with the status in words.
- **`tests/layout_http.py`:** the pending list is empty, so every menu
  page of the four roles it walks must open with the standard header.
- **`tests/folios_http.py`:** "7 nights at $100.00".
- **`tests/my_jobs_http.py`:** "1 open job fits your file" or
  "N open jobs fit your file".
- **Mutation:** with `tn()` on the English rule for every language, the
  test failed at "French: 0 and 1 are singular". Restored afterwards.

## Next

The four waves of the walkthrough are done. The security clean-up the
owner set for after the walkthrough (DEFERRED_SECURITY X1–X5, X7) is his
to start.
