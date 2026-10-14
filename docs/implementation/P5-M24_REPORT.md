# P5-M24 — the remaining "(s)" counts

The owner's request, 14 Oct 2026: finish the "(s)" counts wave 4 left.

## Found before building

**35 strings the app shows still said "(s)" or "(es)":**
- **in the views (13):**
  - Report builder: conditions, recipients, runs and rows;
  - Driving records: two sentences of three counts each;
  - Advances' schedule;
  - Approval chains;
  - the employee folder;
  - Project board;
  - Recognition;
  - Surveys;
  - Workload;
- **in Integrations:** the Gmail mailboxes;
- **in page messages and helpers (21):**
  - Hiring's "3 hour(s) ago";
  - the lateness block on Hours;
  - the pay period check;
  - assets, push, training, surveys, sprint and review messages;
  - the hotel folio checks.

## What was built

- **Each count uses `tn()`,** the singular or the plural form by the
  reader's language rule (wave 4).
- **Several counts in one sentence:** the sentence is a frame and each
  count agrees on its own. For example, ":violations, :accidents in 3
  years" gives "1 violation, 0 accidents in 3 years" in English, "1
  infraction, 0 accident en 3 ans" in French and "1 infracción, 0
  accidentes en 3 años" in Spanish.
- **A few singular messages read better without the number:** "Close its
  open subtask first." Their verbs agree too: "1 weekly sheet in this week
  is not approved yet. Approve it on Hours".
- The hotel folio flag now reads "Billed for nights the worker was absent
  at roll call".
- 74 new strings in French and Spanish.
- **Data:** no data changes, no migration.

**Left as they are, on purpose:** command-line and log output, which stays
English by the project's rule:
- the key rotation tool ("Re-encrypted 5 value(s)");
- the report runner ("to 2 recipient(s)", "Expired 1 delivered file(s)");
- the ADP export log line.

None of these is shown in the app's pages.

## Tests

- **`tests/page_pass_http.py`:**
  - The ceiling of 13 is now a rule: no `t()`/`te()` string in any of the
    app's PHP files (361 read) says "(s)" or "(es)".
  - **New:** a two-count sentence agrees part by part in English, French
    and Spanish.
  - **New:** "1 hour ago" in all three languages.
  - The probe's output is read as UTF-8. It had been read with the Windows
    code page, which garbled "infracción"; the earlier checks passed only
    because their strings had no accents.
- **Tests that read the old wording:**
  - `tests/assets_http.py`: "2 items received";
  - `tests/lateness_http.py`: "1 lateness dispute" or "N lateness
    disputes" in this week, by pattern.
- **Mutation:** with Surveys' ":n answer(s)" put back in the test copy, the
  test failed and named the string. Restored afterwards.
