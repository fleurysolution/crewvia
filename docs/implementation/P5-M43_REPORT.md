# P5-M43 — Crewvia's own scoring: graded answers, and a job board's answers scored by our rules

The owner, on seeing every Mechanical Engineer applicant at 100%: the job board's score cannot be
relied on; Crewvia needs its own scoring method. Measured in production before this module, from
the 425 Indeed applications:

- Chemical Engineer: years of experience from 1 to 30; travel 100% (88), 50% (38), 75% (34), 25% (21), 0% (1).
- CNC Machinist: three years-of-experience questions (milling, lathe, setup); travel 100% (119), 50% (20), 75% (12), 25% (9).
- Mechanical Engineer: every one of the 83 answered 100% travel, a degree, authorized: Indeed's
  share of requirements met is 100% for all, which ranks nobody.
- Sponsorship is a free-text answer ("No", "ELIGIBLE", "yes, in future"): for a person to read.

Crewvia's screening engine (P2, ported from BPMS247) already had weights and knockouts, but an
answered number or choice question earned its whole weight: two years scored like twenty.

## What changes

1. **Graded rules on a question** (Screening questions, on add and on Change):
   - *Full marks at* (a number question): the answer that earns the whole weight; below it, in
     proportion (full marks at 10 years: 4 years earn 40%). Under a knockout minimum, nothing.
   - *Points per answer* (a choice question), one per line out of 100: `100% = 100`, `50% = 30`,
     `25% = 0`. An answer not listed earns nothing.
   - Without a rule, an answer earns its weight as before (no score changes for existing questions).
2. **A job board's answers are recorded against the requisition's own questions.** On an Indeed
   import, each board question is found by its wording, or created for the requisition the first
   time ("From the job board", type guessed from the answer, no weight yet, not required), and
   the answer is stored as a screening answer marked as from the board. Choices the board offered
   are added to the question's list.
3. **Crewvia's rules score them.** Once the office gives the board's questions a weight, the
   applicants are re-ranked by Crewvia's rules; until then the board's verdict (P5-M42) stands.
   A board applicant is scored only on the questions the board asked, never on the office's own
   questions they were never put to (that would invent a low score).
4. **Hiring shows what each answer earned** ("30 of 60 points"), and a knockout answer with ✗.
5. **The 425 production applicants** are brought in once by the upgrade: their answers, kept in
   the note the import left, are recorded against the requisitions' questions.

## Changes

- `app/screening.php`: `screening_answer_share()`, `screening_choice_points()`,
  `screening_rules_from_post()`, `screening_record_board_answer()`; scoring by share; board
  applicants scored on the board's questions only; the board verdict kept while nothing is weighted.
- `app/pages/imports.php`: board answers recorded and scored by Crewvia's rules.
- `app/pages/screening-questions.php`, `app/views/screening-questions.php`: the rules, their
  summary on each question, "From the job board", the explanation.
- `app/pages/hiring.php`, `app/views/hiring.php`: points earned per answer.
- `install/upgrade.php`: `screening_questions.full_marks_at`, `choice_points`, `board_question`,
  `origin`; `screening_answers.from_board`; the one-time recording of the board answers.
- `install/rollback/p5-m43.sql`: board answers and board questions removed, columns dropped
  (run before p5-m42.sql, newest first).
- `app/lang/messages.tsv`: 15 FR/ES strings.

## Tests

- `tests/own_scoring_http.py` (7): board questions created from the import, unweighted, with the
  answers recorded; the page shows them and the rule fields; rules set through the page and stated
  ("Full marks from 10", "100% 100 · 75% 60 · 50% 30 · 25% 0"); applicants re-ranked by them
  (5 years + 100% = 70; 3 years + 25% = 18); points above 100 refused; Hiring shows "30 of 60
  points" and "40 of 40 points".
- `tests/own_scoring_db.php` (8): the share arithmetic (full marks, part marks, under the
  minimum, points per answer, no rule), the rule form's checks, board applicants scored only on
  the board's questions, rollback, upgrade re-recording from the notes, a silent second upgrade.
- `tests/board_scores_db.php`: rolls back newest first (P5-M43, then P5-M42).

Measured: walkthrough chain + Indeed import + add applicant 95 PASS, board scores 8, own scoring
7 + 8. Mutation runs: graded number scoring removed (caught: scores 100/60 instead of 70/18);
import not recording the board answers (caught: no board question). Seen on the test server:
Screening questions with the rules stated.

Full suite: the first run stopped once in `hiring_http.py` ("An empty note is refused", a check P5-M43
does not touch); that test passed alone on the harness (114 PASS) and the rerun of the whole suite
passed: 3613 PASS, 0 FAIL (3598 before, plus the 15 new checks).

## After deploying: the production rules

The upgrade records the board answers with no weight, so nothing moves until the rules are set.
The proposed starting rules (set by the office on Screening questions, changeable at any time):

| Requisition | Question | Weight | Rule |
|---|---|---|---|
| Chemical Engineer | Years of chemical engineering experience | 40 | full marks at 10 |
| | Travel | 30 | 100% = 100, 75% = 60, 50% = 30, 25% = 0, 0% = 0 |
| | Authorized to work in the US | 30 | knockout No |
| Mechanical Engineer | Years of mechanical engineering experience | 50 | full marks at 10 |
| | Mechanical engineering degree | 20 | knockout No |
| | Travel | 15 | as above |
| | Authorized to work in the US | 15 | knockout No |
| CNC Machinist | Years of CNC milling / lathe / setup (each) | 20 | full marks at 10 |
| | Travel | 25 | as above |
| | Authorized to work in the US | 15 | knockout No |

Sponsorship stays unweighted (free text, for the recruiter to read).
