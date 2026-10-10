# Crewvia — what was asked, and by whom

A record of the requirements as they were stated, kept separate from what
we decided to do about them. The tracker is [BACKLOG.md](BACKLOG.md); this
file is the source it answers to.

Nothing here is invented. Each item is traceable to somebody who said it.
Where a requirement arrived as a complaint about the software rather than
as a request, it is written as what the software must do instead.

---

## 1. What this is

Crewvia is a standalone staffing platform for **RSS Inc.**, an agency that
supplies replacement labour to industrial sites, principally during
strikes and plant shutdowns. It is built and operated by **Fleury
Solutions**.

It is not a fork of BPMS247. Modules were *ported* — model and logic
rewritten — not copied.

**Deployed at** `https://crewvia.bpms247.com`, from
`/home/veloraweb/public_html/crewvia`.

---

## 2. Hard constraints

These are standing instructions. They are not open for re-litigation by
anybody picking the project up.

| # | Constraint | Source |
|---|---|---|
| C1 | **Keep disabled**: DocuSign, Gmail, Stripe, AI, outbound email, partner integrations. | Fleury Solutions |
| C2 | **Never modify** BPMS247, Fleury Solutions, Edocis Pro or neighbouring projects. | Fleury Solutions |
| C3 | **Never copy keys** from BPMS247. | Fleury Solutions |
| C4 | No framework, no composer, no build step. PHP 8.2 + MySQL on cPanel shared hosting. | Fleury Solutions |
| C5 | Code comments, CLI messages, logs and identifiers in **English** — the software is sold. | Fleury Solutions |
| C6 | Real credentials never committed. `config.php` is ignored; `config.example.php` is the template. | Fleury Solutions |
| C7 | Personal data of real people never leaves the authorised installation — not into a repository, not into a demonstration. | Fleury Solutions |
| C8 | **The client gets no portal** for RSS. | the RSS VP |
| C9 | **Never promise**: tax filing or deposits (ADP keeps that), no-show alerts, client sign-off on hours. | the RSS VP |
| C10 | Security is a permanent priority: for every change, ask who else can see this and what a weaker role sees. | Fleury Solutions |

---

## 3. The domain model

Dictated directly, after the earlier model was rejected.

> "A project is an agreement between two parties, and that agreement is a
> package. The project has a description — *provide labour support to
> [site] according to the scope of work, which is the job order*."

> "The work order is going to be, say, 20 engineer, 40 mechanical
> engineer, 100 labor, 2,000 machinists. Those are part of that project.
> And then the scope, the job order, or the agreement is going to say
> maybe the per diem for the engineer is X amount, the per diem for this
> is X amount… we will be paying for transportation, we are going to pay
> for this, we are going to pay for that."

This produces:

- **M1** A project is an **agreement**: client, description in the
  client's words, their order reference, site, dates, and what it covers
  beyond the rates (lodging, travel, transport on site).
- **M2** A project has **no single rate**. An engineer and a labourer on
  the same site are neither paid nor billed the same.
- **M3** The **scope of work** is a schedule of lines, one per trade, each
  carrying its own quantity, pay rate, bill rate, per diem, guaranteed
  week, strike guarantee, overtime threshold and multiplier.
- **M4** Money resolves down one chain: **order line → requisition →
  placement**. Each step may override the one above and inherits where it
  is silent. A placement takes a copy, so renegotiating a line in March
  cannot rewrite what somebody was paid in January.
- **M5** There is **no project-level fallback**. A requisition with no
  line behind it has no agreed rate, and the screens say so rather than
  inventing one.
- **M6** The headcount a project asks for is the **sum of its order**,
  never a number typed separately that can disagree with it.
- **M7** The agency **cannot order more of a trade than the agreement
  covers**.

---

## 4. Recruiting — stated by the recruiting manager

### The database (called "the most important" twice)

> "We've had so many jobs… so many people have been terminated that we
> keep regurgitating. [a named worker] — I'd worked with him on other jobs.
> He should never have been on this job, he was here for two days and got
> fired. But there's no notes, no information for everybody to see… he's
> on some kind of a spreadsheet right now and he's redlined. But who gets
> to see that?"

- **R1** A register of people who must not be called again, with the
  distinction they drew: **No rehire** (do not put them back on a job)
  and **DO NOT USE** (do not contact at all).
- **R2** A **reason** recorded against every block.
- **R3** Their name shown **in red** everywhere it appears.
- **R4** A **filter** to list everybody on the register.
- **R5** Visible to the **whole recruiting desk**, not one spreadsheet.

> The worker's name is in the recording and is deliberately not repeated
> here. He is a real person, this is a negative statement about him, and
> this file is read by anybody with access to the repository.

### Work history

> "This guy's been with us five times, completed every job. He's a good
> guy, I'm going to call him."

- **R6** Every project a person has been on, and whether they completed it.
- **R7** A **rating or letter grade** at the end of an assignment, from
  the supervisor who ran them.
- **R8** The supervisor's note alongside it.

### Skills

> "It would be nice if we had some kind of a skill set. Welders,
> machinists, CNC, HEO… because these guys are broom pushers or they work
> at Taco Bell but then they're a chemical engineer."

- **R9** A real skill list, several per person, separate from discipline.
- **R10** Searchable: "punch in welder, and it'll show all your welders".
- **R11** Taken from what they put on their application or résumé.
- **R12** The list must be editable without a developer.

### Reaching them

> "We were able to highlight all of them and send them an email — hey, we
> have this project coming up, it's X amount of money, it's a contract job
> or a strike job, these are the pay rates, this is the per diem. Instead
> of having to call every single one of them."

- **R13** Select a filtered list and send **one campaign email** carrying
  the project, the rates and the per diem.
- **R14** First come, first served — they answer through their portal.

### Their portal, and the end of the phone calls

> "That stops all the phone calls coming in, because that's all we say:
> have you gone to the website? Have you checked in yet? Have you updated
> your résumé?"

- **R15** The candidate creates their own account.
- **R16** They **check in** — mark themselves available — and that is what
  recruiters filter on.
- **R17** They keep their own résumé up to date.
- **R18** They see the jobs they qualify for and express interest.

### Matching and location

- **R19** Creating a requisition **populates the matching candidates**
  automatically, filtered to those who have made themselves available.
- **R20** Filter by **location**: "the client only wants people around
  Detroit, or people in Michigan".

### What RSS actually staffs

- **R21** Three verticals: **CDL drivers**, **order selectors**,
  **manufacturing** (maintenance, machine operators, welders, and the
  rest). Manufacturing covers steel mills and chemical plants.

---

## 5. Onboarding — stated by the onboarding desk

> "She's checking their I-9s, their W-4s, their banking information…
> gets their driver's licence, gets their social security card or two
> forms of ID, and makes copies. All this paperwork is stacking up in the
> office."

- **R22** The worker fills in their **own** I-9, W-4, ADP details and
  banking information, and uploads their own identity documents.
- **R23** On a later job, **reuse what is already held**: ask only
  "has anything changed?".
- **R24** A worker may **request** a change to sensitive fields; somebody
  here **reviews and validates** before it takes effect. Some fields only
  staff can change at all.

---

## 6. Payroll — stated for the payroll desk

> "She goes through the hotel folios, because that's got to be turned in
> every week… goes through their hours, their per diem. This guy missed a
> day — does he get his per diem or not?"

- **R25** Hours captured from the site, not retyped from a spreadsheet.
- **R26** The **worker clocks in**; the **supervisor validates**. If the
  worker could not clock in, they tell the supervisor, who validates
  manually **with a note**.
- **R27** Pay and bill computed from validated hours — "you pay somebody
  for forty, maybe you are billing the guy for sixty".
- **R28** **Per diem rules**, including what happens on a missed day.
- **R29** **Lateness deductions**, with the reason visible, so nobody can
  claim they were paid short.
- **R30** Weekly **hotel folio** reconciliation.
- **R31** A worker sees **only their own hours**, and **only while
  active**. On termination their access is **locked immediately**.

---

## 7. Operations and dispatch — stated by the operations manager

> "I've got calls coming in from twenty-five supervisors, guys at airports
> that need to get picked up, van drivers… This guy's hotel room changed.
> The supervisor's sick the next two days, so I need a driver at 6 p.m. to
> pick up his crew. This guy is landing at 11:50, I need a driver to bring
> him straight to work so he can make orientation. I need a replacement
> tank welder for Monday. And I need to address that this guy showed up at
> 10 a.m. rather than 6 a.m."

> "Rather than calling me and saying I need this, this and this, he could
> just go in there, put it in — and then I know to look at it."

- **R32** An **issue board**: supervisors raise what they need, in real
  time, from a phone, instead of telephoning.
- **R33** **Delegate** an issue to a named person.
- **R34** That person is **notified** — by email and in the portal.
- **R35** When it is done, **everybody sees it is done**.
- **R36** Everything documented, so *"I told you this"* is provable.

And the shape of a job, as described:

- **R37** A call comes in — "200 guys in Michigan for Monday" — and the
  flow runs: create the agreement → write the scope → requisitions open →
  recruiters work the pool → hires confirmed → van drivers and flight
  roster → onboarding → hotels → orientation → on site.

---

## 8. Constraints on the rollout

> "They've been doing this thirty years and it works, and they don't like
> change. Start with a smaller job — show them with this ten-person job —
> and eventually they could do it for everything."

- **R38** The product must be demonstrable on **one small job** before
  anybody is asked to move everything onto it.
- **R39** **No training required**: the flow must be the same thing they
  already do every day.
- **R40** Simple. The predecessor system had 126 tabs; the target is
  roughly 10–18 screens.

---

## 8a. Procurement - stated by Fleury Solutions, 9 October 2026

RSS buys for every job: hotel rooms, cars and vans, safety boots and
other protective equipment, and more. The HR system's procurement module
is the reference, cut down to how RSS actually buys.

- **R41** A **purchase request** for anything the job needs: rooms,
  vehicles, protective equipment, other items.
- **R42** **Lodging is requested automatically** when a person is hired
  and needs a bed: the hire raises the request, nobody retypes it.
- **R43** **No bid analysis.** RSS does not run bids. A quotation may be
  attached, but the flow is request -> purchase order.
- **R44** The purchase order is **approved by the person who owns the
  budget** - the supervisor or whoever holds it - before it is placed.
- **R45** What arrives is recorded as **available**: for hotels, the
  goods receipt *is* the rooms available, and it is what the hotel board
  counts down.
- **R46** **Commitments**: the bills that follow from what was ordered -
  the rooms, the units used - so what is owed is visible before the
  invoice arrives.
- **R47** **Units of measure** (room-night, van-day, pair, each) are
  created when needed, and every quantity is accounted in its unit.

---

## 9. Standards the code must meet

Not requested by the client, but binding on the work.

- **S1** Every sentence shown to a person is translated into French and
  Spanish. The English sentence is the key.
- **S2** `install/upgrade.php` is guarded, idempotent and loud: one line
  per change, silence on a repeat run.
- **S3** No screen emits a PHP warning or notice with `display_errors` on.
- **S4** Every value a person reads is a word, not a stored enum.
- **S5** A list that can be empty says what the emptiness means.
- **S6** One vocabulary per concept, in one function. A second hand-kept
  copy is how two screens come to disagree.
- **S7** A behaviour is proved by exercising it, not by reading the code.
- **S8** A record is never silently narrowed: widen an ENUM before
  offering a new value, because MySQL empties what it will not accept.
