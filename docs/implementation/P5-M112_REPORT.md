# P5-M112 — The offer is made from Hiring, after the approval

## What the walk found (29 Oct 2026, production)

After P5-M111, a person approved by the hiring manager stays a candidate until the offer is signed and countersigned.
But in Hiring, an approved application showed only "From approval to the first day": Approved, Assigned to the job,
HR onboarding, Portal, the checks, W-4, I-9, Flight and hotel. The stage form (where "Move to Offered" and the offer
terms were) is hidden once approved (P5-M94). The office had nowhere to make Sample Applicant's offer, and W-4 and
I-9 read "Not filled in yet · Open" for someone not hired.

## What changed

- A step **Offer** after Approved: *Not made yet*, *Sent, waiting for their signature*, *Signed: to countersign*
  (the countersign card above), *Signed and countersigned*.
- While it is not made: a form under the steps, **Offer terms** (required) and **Make the offer**. It is the same
  move to Offered as before (`hw_move()`): screening checks, required questions, the contract workflow where the
  project requires it, the offer validation chain where the agency has one, then the candidate is told in the
  portal and by email.
- **W-4** and **I-9** read *After the hire*, with no Open, until the application is accepted.

## Tests

`client_contacts_http.py`: the approved person shows the Offer step and its form; the W-4 and I-9 wait for the
hire; an offer without terms is refused. `offer_flow_http.py` still covers the letter, the signature and the
countersignature.

## Still to do

- The HR onboarding task is still created at the approval (P5-M87); with the hire now at the countersignature it
  may belong there too.
