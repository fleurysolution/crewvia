# P5-M56 — A placement made from an offer never starts on a day already gone

Measured in production, 15 Oct 2026, after Leo Noonan's offer was countersigned (P5-M53): his
placement started on 14 Sep 2026, the requisition's start date, a month in the past - while his offer
letter said "Starts: immediately". The same for Tyler Staab.

## What changes

`offer_start_date()` (`app/placement-rates.php`): the placement made from an offer (countersigned, or
signed through the contract workflow) starts on the requisition's start date, or today when that date
has passed. The office changes it on the placement as before (Placement, Dates).

No table changes, so no migration and no rollback.

## In production, with the owner's approval

Leo Noonan's placement (1014) starts 16 Oct 2026 (Placement, Dates).

## Tests

`tests/start_date_db.php` (3): a requisition whose start has passed gives today; a later start is kept;
no start date gives none.

Mutation: the requisition's date returned as it is (caught: "A requisition whose start has passed").

Full suite: 3758 PASS, 0 FAIL (3755 before, plus the 3 new checks).
