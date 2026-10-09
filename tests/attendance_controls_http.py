"""P1-M02 through the screens: corrections, staff-entered days, the week check.

Runs on :8097 after attendance_controls_db.php built the synthetic week.
Every refusal is exercised, and what a screen wrote is read back from the
database rather than inferred from the page.
"""
import datetime, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fixture = json.load(open('work/fixture.json'))
m02 = json.load(open('work/m02.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []
week, days = m02['week'], m02['days']          # days[0] is Sunday, days[6] Saturday = week ending


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def _send(self, req):
        try:
            with self.opener.open(req) as r:
                body, status = r.read().decode(), r.status
        except urllib.error.HTTPError as e:
            body, status = e.read().decode(), e.code
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body

    def get(self, path):
        return self._send(base + path)

    def post(self, path, values):
        return self._send(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()))

    def login(self, email):
        self.get('/login')
        status, body = self.post('/login', {'email': email, 'password': 'TestPassword123!'})
        # A refused sign-in also answers 200, with the form again. Only leaving it counts.
        return (status if '<title>Sign in' not in body else 401), body


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:600] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(pid):
    out = subprocess.run([php, 'work/attendance_controls_probe.php', str(pid)], check=True, capture_output=True, text=True)
    return json.loads(out.stdout)


def day_of(pid, date):
    return next((d for d in probe(pid)['days'] if d['work_date'] == date), None)


def act(client, values):
    client.get('/attendance')
    return client.post('/attendance', values)


def week_check(client):
    return client.get('/attendance-week?week=' + week)


def state_of(body, pid):
    m = re.search(r'/placements/%d".*?data-state="([a-z_]+)"' % pid, body, re.S)
    return m[1] if m else None


payroll = Client(); check('M02 payroll login', payroll.login('m01-payroll@test.invalid')[0] == 200)
payroll.post('/select-project', {'job_id': fixture['a']})
supervisor = Client(); check('M02 supervisor login', supervisor.login('supervisor@test.invalid')[0] == 200)
other = Client(); check('M02 other supervisor login', other.login('m02-supervisor@test.invalid')[0] == 200)
hotels = Client(); check('M02 hotels login', hotels.login('m01-hotels@test.invalid')[0] == 200)
worker = Client(); check('M02 worker login', worker.login('m02-mixed-week@test.invalid')[0] == 200)

# ── the week check: who sees it, and what it finds ───────────────────────
status, body = week_check(payroll)
check('Payroll opens the week check', status == 200 and 'Approved days against the weekly sheet' in body)
for kind, label in [('present_no_hours', 'present at roll call with no hours'), ('hours_while_absent', 'hours on a day marked absent'),
                    ('long_day', 'a day over 16 hours'), ('two_assignments', 'hours on two assignments the same day'),
                    ('waiting_review', 'a submission left waiting')]:
    check('The week check finds ' + label, 'data-kind="%s"' % kind in body)
check('Approved days with no sheet are flagged for import', state_of(body, m02['p']) == 'not_on_sheet', state_of(body, m02['p']))
check('A paid week that differs is flagged as an adjustment', state_of(body, m02['q']) == 'frozen_differs', state_of(body, m02['q']))
check('Hours typed with nothing behind them are flagged', state_of(body, m02['r']) == 'typed_without_attendance', state_of(body, m02['r']))
check('The person on two assignments is listed with the week total',
      re.search(r'M02 mixed week</td><td class="num mono">2</td>\s*<td class="num mono">36</td>', body) is not None)
check('Exceptions read as sentences, not codes', 'Hours on two assignments the same day' in body and 'More than 16 hours in one day' in body)

status, body = payroll.get('/attendance-week?week=%s&lang=fr' % week)
check('The week check is translated', 'Contrôle de la semaine' in body and 'Jours qui ne concordent pas' in body)
payroll.get('/attendance-week?lang=en')

check('Hotels cannot open the week check', week_check(hotels)[0] == 403)
check('A supervisor is sent away from the week check', 'Approved days against the weekly sheet' not in week_check(supervisor)[1])
check('A worker is sent away from the week check', 'Approved days against the weekly sheet' not in week_check(worker)[1])

# ── correcting an approved day ───────────────────────────────────────────
long_date = days[3]
status, body = act(supervisor, {'do': 'correct', 'attendance_id': m02['long_day'], 'hours': '10', 'reason': 'Site log shows 10 hours'})
check('The supervisor corrects an approved day on their crew', status == 200, (status, body[:300]))
d = day_of(m02['p'], long_date)
check('The day now carries the corrected hours', float(d['hours']) == 10.0 and d['status'] == 'approved')
c = probe(m02['p'])['corrections']
check('The correction keeps the old hours, the new hours and the reason',
      len(c) == 1 and float(c[0]['old_hours']) == 18.0 and float(c[0]['new_hours']) == 10.0 and c[0]['reason'] == 'Site log shows 10 hours')

refusals = [
    ('Correcting to the hours already held is refused', supervisor, {'attendance_id': m02['long_day'], 'hours': '10', 'reason': 'Same again'}),
    ('A correction without a reason is refused', supervisor, {'attendance_id': m02['long_day'], 'hours': '9', 'reason': ''}),
    ('More than 24 hours in a day is refused', supervisor, {'attendance_id': m02['long_day'], 'hours': '25', 'reason': 'Too many'}),
    ('Negative hours are refused', supervisor, {'attendance_id': m02['long_day'], 'hours': '-1', 'reason': 'Negative'}),
    ('A day still waiting for review is not corrected', supervisor, {'attendance_id': m02['waiting'], 'hours': '7', 'reason': 'Not approved yet'}),
    ('A supervisor cannot correct somebody else\'s crew', other, {'attendance_id': m02['absent_day'], 'hours': '7', 'reason': 'Not my crew'}),
    ('A day inside a week approved for pay is not corrected', payroll, {'attendance_id': m02['frozen_day'], 'hours': '10', 'reason': 'Paid already'}),
]
for label, client, values in refusals:
    status, body = act(client, {'do': 'correct', **values})
    check(label, status == 422, (status, body[:300]))
check('Refused corrections wrote nothing', len(probe(m02['p'])['corrections']) == 1 and len(probe(m02['q'])['corrections']) == 0)
check('Hotels cannot correct a day', act(hotels, {'do': 'correct', 'attendance_id': m02['absent_day'], 'hours': '7', 'reason': 'x y z'})[0] == 403)
status, body = act(worker, {'do': 'correct', 'attendance_id': m02['absent_day'], 'hours': '12', 'reason': 'More please'})
check('A worker cannot correct a day, not even their own', status == 403, (status, body[:400]))

status, body = worker.get('/attendance')
check('The worker sees the correction and its reason on their own day',
      'Was 18 h, changed by' in body and 'Site log shows 10 hours' in body)

# ── a day the worker could not enter ─────────────────────────────────────
roll_call_day = days[1]
status, body = act(supervisor, {'do': 'enter', 'placement_id': m02['p'], 'work_date': roll_call_day, 'hours': '9', 'note': 'Phone died on site'})
check('The supervisor enters a day for a worker on their crew', status == 200, (status, body[:300]))
d = day_of(m02['p'], roll_call_day)
check('The entered day is approved, marked as staff-entered, with its note',
      d and d['status'] == 'approved' and d['source'] == 'staff' and d['note'] == 'Phone died on site')

tomorrow = (datetime.date.today() + datetime.timedelta(days=1)).isoformat()
before_start = (datetime.date.fromisoformat(m02['start']) - datetime.timedelta(days=1)).isoformat()
enter_refusals = [
    ('An entered day without a note is refused', supervisor, {'placement_id': m02['p'], 'work_date': days[0], 'hours': '8', 'note': ''}),
    ('An entered day in the future is refused', supervisor, {'placement_id': m02['p'], 'work_date': tomorrow, 'hours': '8', 'note': 'Ahead of time'}),
    ('An entered day before the assignment started is refused', supervisor, {'placement_id': m02['p'], 'work_date': before_start, 'hours': '8', 'note': 'Too early'}),
    ('Entering over a day the worker submitted is refused', supervisor, {'placement_id': m02['p'], 'work_date': days[5], 'hours': '8', 'note': 'Already there'}),
    ('Entering over an approved day is refused', supervisor, {'placement_id': m02['p'], 'work_date': roll_call_day, 'hours': '8', 'note': 'Twice'}),
    ('Entering a day in a week approved for pay is refused', payroll, {'placement_id': m02['q'], 'work_date': days[3], 'hours': '8', 'note': 'Paid already'}),
    ('A supervisor cannot enter a day for somebody else\'s crew', other, {'placement_id': m02['p'], 'work_date': days[0], 'hours': '8', 'note': 'Not mine'}),
    ('Hours outside 0 to 24 are refused', supervisor, {'placement_id': m02['p'], 'work_date': days[0], 'hours': '30', 'note': 'Too long'}),
]
for label, client, values in enter_refusals:
    status, body = act(client, {'do': 'enter', **values})
    check(label, status == 422, (status, body[:300]))
check('Refused entries wrote nothing', day_of(m02['p'], days[0]) is None and day_of(m02['q'], days[3]) is None)
check('A worker cannot enter a day as staff',
      act(worker, {'do': 'enter', 'placement_id': m02['p'], 'work_date': days[0], 'hours': '8', 'note': 'Myself'})[0] == 403)

status, body = week_check(payroll)
check('The roll-call gap is gone once the day is entered',
      not re.search(r'data-kind="present_no_hours">\s*<td>[^<]*</td>\s*<td><a href="/placements/%d"' % m02['p'], body))

# ── the sheet follows the days, and says so when it stops following ─────
payroll.get('/hours?week=' + week)
check('Payroll imports the approved days into the weekly sheet',
      payroll.post('/hours', {'do': 'import_attendance', 'week_ending': week})[0] == 200)
sheet = probe(m02['p'])['sheet']
check('The sheet holds the approved hours: 9 + 8 + 10 + 6', sheet and float(sheet['hours_worked']) == 33.0, sheet)
check('After the import the week check says the sheet agrees', state_of(week_check(payroll)[1], m02['p']) == 'agrees')

act(payroll, {'do': 'correct', 'attendance_id': m02['absent_day'], 'hours': '7', 'reason': 'Left early, gate log'})
check('A correction after the import is caught', state_of(week_check(payroll)[1], m02['p']) == 'changed_since_import')
payroll.get('/hours?week=' + week)
payroll.post('/hours', {'do': 'import_attendance', 'week_ending': week})
check('Importing again brings the sheet back into agreement',
      float(probe(m02['p'])['sheet']['hours_worked']) == 32.0 and state_of(week_check(payroll)[1], m02['p']) == 'agrees')

# ── the existing path still works ────────────────────────────────────────
saturday = days[6]
check('A worker still submits their own day',
      act(worker, {'do': 'submit', 'placement_id': m02['p'], 'work_date': saturday, 'hours': '5'})[0] == 200
      and day_of(m02['p'], saturday)['status'] == 'submitted' and day_of(m02['p'], saturday)['source'] == 'worker')
d = day_of(m02['p'], saturday)
act(supervisor, {'do': 'review', 'attendance_id': d['id'], 'status': 'approved'})
check('Their supervisor still approves it', day_of(m02['p'], saturday)['status'] == 'approved')

json.dump(results, open('work/m02-http-results.json', 'w'), indent=1)
