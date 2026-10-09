"""P1-M01 through the screens: classification, assignment provenance, rate history.

Runs against the isolated server on :8097 after hr_relationships_db.php.
Synthetic accounts only. Every refusal path is exercised, not just the
happy one, and what the screen wrote is read back from the database.
"""
import datetime, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fixture = json.load(open('work/fixture.json'))
m01 = json.load(open('work/m01.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []


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
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body, body[:800]
        return status, body

    def get(self, path):
        return self._send(base + path)

    def post(self, path, values, csrf=True):
        if csrf:
            values = {'_csrf': self.token, **values}
        return self._send(urllib.request.Request(base + path, urllib.parse.urlencode(values).encode()))

    def login(self, email):
        self.get('/login')
        return self.post('/login', {'email': email, 'password': 'TestPassword123!'})


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:600] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(candidate):
    out = subprocess.run([php, 'work/hr_relationships_probe.php', str(candidate)], check=True, capture_output=True, text=True)
    return json.loads(out.stdout)


today = datetime.date.today()
iso = lambda d: d.isoformat()
person = m01['to_place']
folder = '/employee-folder?id=%d' % person


def classify(client, kind, flsa, effective, reason, csrf=True):
    client.get(folder)
    return client.post('/employee-folder', {'do': 'classification', 'candidate_id': person, 'employment_type': kind,
                                            'flsa_status': flsa, 'effective_from': effective, 'reason': reason}, csrf=csrf)


admin = Client(); check('M01 admin login', admin.login('admin@test.invalid')[0] == 200)
admin.post('/select-project', {'job_id': fixture['a']})
recruiter = Client(); check('M01 recruiter login', recruiter.login('m01-recruiter@test.invalid')[0] == 200)
payroll = Client(); check('M01 payroll login', payroll.login('m01-payroll@test.invalid')[0] == 200)
hotels = Client(); check('M01 hotels login', hotels.login('m01-hotels@test.invalid')[0] == 200)
worker = Client(); check('M01 worker login', worker.login('worker@test.invalid')[0] == 200)

# ── who may see and change a classification ──────────────────────────────
check('Hotels cannot open an employee folder', hotels.get(folder)[0] == 403)
status, body = recruiter.get(folder)
check('Recruiter opens the folder with the classification card', status == 200 and 'Employment classification' in body)
check('A profile with no history says so', 'No change recorded yet' in body)
check('The folder names the kind of employment in words', 'Hourly employee' in body)

before = probe(person)
check('A worker cannot change a classification, not even their own',
      worker.post('/employee-folder', {'do': 'classification', 'candidate_id': person, 'employment_type': 'salaried',
                                       'flsa_status': 'exempt', 'effective_from': iso(today), 'reason': 'Worker attempt'})[0] == 403)
status, body = worker.get('/employee-folder')
check('A worker does not see the classification history or its reasons',
      status == 200 and 'Employment classification' not in body and 'Record the change' not in body)
check('A form without its token is refused', classify(recruiter, 'salaried', 'exempt', iso(today), 'No token', csrf=False)[0] == 419)

# ── every rule refuses, and refusing writes nothing ──────────────────────
refusals = [
    ('A future effective date is refused', ('salaried', 'exempt', iso(today + datetime.timedelta(days=1)), 'Scheduled change')),
    ('A missing reason is refused', ('salaried', 'exempt', iso(today), '')),
    ('A malformed date is refused', ('salaried', 'exempt', '2026-02-30', 'Bad date')),
    ('An unknown kind of employment is refused', ('boss', 'exempt', iso(today), 'Unknown kind')),
    ('An unknown overtime status is refused', ('salaried', 'sometimes', iso(today), 'Unknown status')),
    ('A contractor cannot be given an overtime status', ('contractor', 'non_exempt', iso(today), 'Contractor overtime')),
    ('An employee cannot be marked outside overtime law', ('salaried', 'not_applicable', iso(today), 'Employee outside')),
    ('Recording the classification already held is refused', ('hourly', 'not_determined', iso(today), 'No change at all')),
]
for label, args in refusals:
    status, body = classify(recruiter, *args)
    check(label, status == 422, (status, body))
check('Refused changes wrote nothing', probe(person)['classifications'] == before['classifications'] == [])

# ── a change, recorded with its date and reason ──────────────────────────
week_ago = iso(today - datetime.timedelta(days=7))
status, body = classify(recruiter, 'salaried', 'exempt', week_ago, 'Moved to site manager role')
check('Recruiter records a backdated change with a reason', status == 200, (status, body[:300]))
state = probe(person)
check('The value held before the history is written first, undated',
      len(state['classifications']) == 2 and state['classifications'][0]['effective_from'] is None
      and state['classifications'][0]['employment_type'] == 'hourly')
check('The change keeps its date, reason and author',
      state['classifications'][1]['effective_from'] == week_ago
      and state['classifications'][1]['reason'] == 'Moved to site manager role'
      and state['classifications'][1]['recorded_by'] is not None)
check('The profile follows the newest classification',
      state['profile'] == {'employment_type': 'salaried', 'flsa_status': 'exempt'})

status, body = classify(recruiter, 'hourly', 'non_exempt', iso(today - datetime.timedelta(days=10)), 'Before the last change')
check('A change dated before the latest one is refused', status == 422, (status, body))

status, body = classify(payroll, 'hourly', 'non_exempt', iso(today), 'Back on the hourly crew')
check('Payroll may record a change too', status == 200, (status, body[:300]))
state = probe(person)
check('Three rows of history, newest last', [k['employment_type'] for k in state['classifications']] == ['hourly', 'salaried', 'hourly'])
check('The profile is back to hourly non-exempt', state['profile'] == {'employment_type': 'hourly', 'flsa_status': 'non_exempt'})

status, body = recruiter.get(folder)
check('The folder lists the history with its reasons',
      'Moved to site manager role' in body and 'Back on the hourly crew' in body and 'Before history was kept' in body)
check('The overtime status reads as a sentence', 'Overtime applies (non-exempt)' in body and 'Exempt from overtime' in body)

status, body = admin.get(folder + '&lang=fr')
check('The classification card is translated', 'Classification de l' in body and 'Statut des heures suppl' in body)
status, body = admin.get(folder + '&lang=es')
check('The classification card is translated into Spanish', 'Clasificación del empleo' in body)
admin.get(folder + '&lang=en')

status, body = recruiter.post('/employee-folder', {'do': 'profile', 'candidate_id': person, 'availability': 'unavailable',
                                                   'employment_type': 'contractor', 'rehire_status': 'review'})
check('The plain profile form no longer changes the classification silently',
      probe(person)['profile']['employment_type'] == 'hourly')

# ── an assignment records where its terms came from ──────────────────────
admin.get('/candidates/%d' % person)
status, body = admin.post('/candidates/%d' % person, {'do': 'place', 'start_date': iso(today)})
check('Admin places the candidate through the screen', status == 200, (status, body[:300]))
placed = [p for p in probe(person)['placements'] if int(p['job_id']) == fixture['a']]
check('The placement records its requisition and its line of the scope',
      len(placed) == 1 and int(placed[0]['vacancy_id']) == fixture['vac'] and int(placed[0]['order_line_id']) == fixture['lineWelder'],
      placed)
pid = int(placed[0]['id'])
check('Its rates are the line\'s, copied at creation', float(placed[0]['pay_rate']) == 50.0)

status, body = admin.get('/placements/%d' % pid)
check('The placement page says the line was recorded',
      status == 200 and 'Recorded when the assignment was made' in body and 'Test welder' in body)
check('An unchanged placement says it is on its starting terms', 'No change since the assignment was made' in body)

status, body = admin.get('/placements/%d' % m01['placed_before_pid'])
check('A placement made before the link says its line is only inferred',
      status == 200 and 'Not recorded on the assignment' in body and 'Test welder' in body)

# ── rate history ─────────────────────────────────────────────────────────
rates = {'do': 'rates', 'placement_id': pid, 'pay_rate': '55', 'bill_rate': '80', 'per_diem_rate': '30', 'guarantee_hours': '50'}
admin.get('/candidates/%d' % person)
check('Admin changes the pay rate', admin.post('/candidates/%d' % person, rates)[0] == 200)
changes = probe(person)['rate_changes'][str(pid)]
check('The change keeps the old and the new figure',
      len(changes) == 1 and float(changes[0]['old_pay_rate']) == 50.0 and float(changes[0]['new_pay_rate']) == 55.0, changes)
admin.get('/candidates/%d' % person)
admin.post('/candidates/%d' % person, rates)
check('Saving the same figures again writes no second row', len(probe(person)['rate_changes'][str(pid)]) == 1)

recruiter.get('/candidates/%d' % person)
check('A recruiter cannot change what somebody is paid',
      recruiter.post('/candidates/%d' % person, {**rates, 'pay_rate': '99'})[0] == 403
      and len(probe(person)['rate_changes'][str(pid)]) == 1)

status, body = admin.get('/placements/%d' % pid)
check('The placement page shows the change', '$50.00 → $55.00' in body)
status, body = hotels.get('/placements/%d' % pid)
check('Hotels see the logistics page without the pay history', status == 200 and 'Rate changes' not in body and '$55.00' not in body)

json.dump(results, open('work/m01-http-results.json', 'w'), indent=1)
