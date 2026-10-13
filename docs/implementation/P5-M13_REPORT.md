# P5-M13 — T10: jobs a worker qualifies for, and "I'm interested"

The owner's request, 12 Oct 2026 (BACKLOG T10, requirement R18): the
worker portal shows the jobs the person qualifies for, and lets them
express interest.

## What was found before building

- **The worker portal** (`/portal`) showed only a person's own assignments
  and applications.
- **Open jobs** were on the public job board (`/careers`), for anyone,
  with nothing about fit.
- **The interest on Hiring** (P5-M10) is the recruiter's own mark, not the
  worker's.
- **What a requisition asks:** a trade (`discipline`, where `other` means
  any), a number of years (`years_experience`), and free-text requirements
  and degree.
- **What a person's file says:** a trade (`other` until known) and years
  of experience (empty until known).
- **Screening questions** belong to a project or to one requisition, and
  were drawn only inside the public application form.

## What was built

**Jobs for you** (`/my-jobs`, in the worker's menu, plus a card on the
portal saying how many open jobs fit):

- **Which jobs:** open requisitions on running projects whose posting has
  not expired (the job board's rule), where the person's file fits:
  - **the trade**, when the requisition names one;
  - **the years**, when it asks a number.
- **What the file does not say counts for the person.** A trade not on the
  file, or years not on the file, still lets the job show, with a note:
  "Your trade is not on your file yet."
- **The do-not-rehire register:** nobody on it is offered anything, and
  the page says nothing about the register.
- **Each job shows:**
  - its place and how far it is from the person (P5-M12);
  - start date and shift;
  - pay per hour and per diem (the requisition's own rate, otherwise its
    scope line's);
  - lodging and travel when provided;
  - the description and what it asks;
  - its screening questions.

  The client is not named, as on the job board.

**"I'm interested":**

- **It makes an application**, from source "Worker portal", with the
  answers recorded and scored as on the public form. It arrives in the
  recruiter's Hiring workspace as a new applicant, with an event saying
  where it came from and who sent it. Nothing is typed twice.
- **It is refused when:**
  - a required question is unanswered;
  - the person is already on the job;
  - the job is closed or expired;
  - the job does not fit the file. This is checked again on the server,
    so a forged form is refused too.
- **"No longer interested"** takes it back while it is still new. The
  application is kept, withdrawn, with an event. Interested again later
  reopens the same application with the new answers.
- **Once a recruiter has moved it on,** the worker is told to contact
  them instead.

**Shared with the public form:** the screening questions are now one
partial, `app/views/_screening-fields.php`, used by `/apply` and by Jobs
for you. Each job's fields carry their own id prefix, so several forms on
one page do not clash. The public form renders the same fields as before.

**Data:** no new table or column, so no migration or rollback.

**Translations:** 32 new strings in French and Spanish.

## Tests

- **`tests/my_jobs_db.php`** builds the fixture:
  - **a Katy, TX project** with six requisitions:
    - chemical, three years, with a required and an optional question;
    - mechanical;
    - chemical, ten years;
    - closed;
    - posting expired;
    - any trade;
  - **one requisition on a closed project;**
  - **workers:**
    - chemical, five years, in Houston;
    - a file with no trade or years;
    - one on the do-not-rehire register;
    - an account with no crew record;
  - **a recruiter.**
- **`tests/my_jobs_http.py`** covers:
  - **What is offered:**
    - only the fitting open jobs, and nothing closed, expired, on a closed
      project, outside the trade, or asking more years;
    - pay, per diem, lodging and distance shown;
    - the questions, the required one marked;
    - an unknown file not held against the worker, with the notes;
    - the register offered nothing, without saying why;
    - the portal card counting.
  - **"I'm interested":**
    - refused without the required answer;
    - with it, a portal application with its answers, its score and its
      event;
    - moved to "Jobs you are interested in";
    - refused twice, for a job that does not fit, for a closed or expired
      job, and for the register by a forged form;
    - seen by the recruiter in Hiring.
  - **Taking it back:**
    - taken back while new, and offered again;
    - interested again reopens the same application;
    - refused once a recruiter is working on it, with the button gone;
    - nobody takes back someone else's.
  - **Who gets the page:**
    - an unlinked account told so;
    - a recruiter refused.
  - **The public form** still asks its questions.
  - **Spanish.**
- **`tests/my_jobs_probe.php`:** reads applications, events and answers,
  and moves an application as a recruiter would.
- **Access matrix:** `/my-jobs` is refused to Logistics, payroll,
  supervisors and clients.
- **CSRF sweep:** interest and withdraw.
- **Mutation:** with the fit check removed from `jm_interest()`, the test
  failed at "Never for a job that does not fit". Restored afterwards.

## Not done

- **Skills, certificates and degree** are not matched: requisitions do not
  store them in a form that can be compared. They are shown as "What it
  asks".
- **Telling the recruiter:** they are not notified. The applicant shows as
  new in Hiring, as public applications do.
- **Distance as a filter:** the worker sees how far each job is but cannot
  filter by it.
- **The worker's menu groups** borrow the staff names: their own contracts
  and I-9 sit under "Hiring". That is unchanged here.
