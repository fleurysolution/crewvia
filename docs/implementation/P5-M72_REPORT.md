# P5-M72 — the scope line made clear, "Add a trade" folded

RSS, 17 Oct 2026, looking at the plant:
- "I'm just looking at markup on pay, but then there's pay rate and then bill
  rate. It's confusing."
- "The overtime is sometimes 38… put it automatically to be 40, and they can
  override it."
- "Too much information in my face… just a button, add trade."

## The scope line in two parts

**1. What the person is paid**
- The pay rate, per diem, guaranteed week and strike guarantee.
- Overtime after **40** hours at **1.5**, filled in on a new line. Change them
  when the contract says otherwise, for example 38 hours.

**2. What the client is billed for this trade, per hour**
- Choose either **a bill rate agreed with the client** or **a markup on the
  pay rate**.
- With the markup chosen, the bill rate is calculated as pay × (1 + markup)
  and cannot be typed. The server applies the same rule, even if a bill rate
  is sent.
- With a rate chosen, the markup is shown for information.
- A live line shows what is billed against what is paid, with the margin per
  hour and the markup %. It turns red when the bill rate is below the pay
  rate.
- A note points to the project's **What the client is billed** (P5-M70).
  Hotel, travel, per diem, vehicles and overtime each carry their own markup
  there.

## "+ Add a trade"

- Once the scope has a line, the form is folded behind a **+ Add a trade**
  button.
- It is open when there is no line yet, when a line is being changed, and
  after a discipline is added.

## What the client is billed: each amount only where it applies

"If it's at cost, this markup should not be allowed."

On the project's billing terms, each amount appears only with the way of
billing that uses it:
- a markup % only with **at cost plus a percentage**;
- the flat price only with **a flat rate per night**, labelled "billed $ per
  room per night, whatever the hotel";
- the overtime multiplier or overtime rate only with its own mode;
- the transport rate only when transport is billed.

The server keeps the same rule. A markup sent with "at cost" is dropped, and
"cost plus" without a percentage is refused.

**Several hotels on one project.** "At cost" bills each booking at its own
nightly rate, so every hotel is billed at its price. The rate can be filled
in later, when the room is booked. Linking a project to its hotels and room
blocks is the next step, on the Hotels screen.

## Tests

`tests/client_contacts_http.py` covers:
- the open form with 40 / 1.5 when the scope is empty;
- a line billed by markup: 40% on $38 gives $53.20, even with $50 typed, and
  overtime after 38 is kept;
- a line billed by rate keeps its rate;
- the form folded behind the button once the scope has lines.

`tests/billing_terms_db.php` also covers:
- "at cost" keeps no markup and no flat rate;
- "cost plus" needs its percentage.

Checked in the local preview:
- the folded button;
- the live figure: "Billed $53.20/h for $38.00/h paid: $15.20/h margin (40%
  markup)".
