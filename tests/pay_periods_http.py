"""P1-M06 through the screens: pay periods, four-eyes approval, locks,
adjustments, reconciliation, payslips, audit trail.

Runs on :8097 after pay_periods_db.php. Runs last: it locks weeks.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
m06 = json.load(open('work/m06.json'))
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
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body

    def get(self, path):
        return self._send(base + path)

    def post(self, path, values):
        return self._send(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()))

    def login(self, email):
        self.get('/login')
        status, body = self.post('/login', {'email': email, 'password': 'TestPassword123!'})
        return (status if '<title>Sign in' not in body else 401), body


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:600] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe():
    return json.loads(subprocess.run([php, 'work/pay_periods_probe.php'], check=True, capture_output=True, text=True).stdout)


def run_of(week):
    return next((r for r in probe()['runs'] if r['week_ending'] == week), None)


def runs_post(client, values):
    client.get('/payroll-runs')
    return client.post('/payroll-runs', values)


payroll = Client(); check('M06 payroll login', payroll.login('m01-payroll@test.invalid')[0] == 200)
admin = Client(); check('M06 admin login', admin.login('m06-admin1@test.invalid')[0] == 200)
admin2 = Client(); check('M06 second admin login', admin2.login('m06-admin2@test.invalid')[0] == 200)
hotels = Client(); check('M06 hotels login', hotels.login('m01-hotels@test.invalid')[0] == 200)
worker = Client(); check('M06 worker login', worker.login('m06-worker@test.invalid')[0] == 200)
week, later = m06['week'], m06['later']

# ── a period, and what stops it being submitted ──────────────────────────
check('Payroll opens Pay periods', payroll.get('/payroll-runs')[0] == 200)
check('Hotels cannot open Pay periods', hotels.get('/payroll-runs')[0] == 403)
check('Payroll opens the period', runs_post(payroll, {'do': 'open', 'week_ending': week})[0] == 200 and run_of(week)['status'] == 'open')
check('The same week twice is refused', runs_post(payroll, {'do': 'open', 'week_ending': week})[0] == 422)
rid = int(run_of(week)['id'])

payroll.post('/select-project', {'job_id': m06['job_g']})
payroll.get('/hours?week=' + week); payroll.post('/hours', {'do': 'import_attendance', 'week_ending': week})
payroll.get('/hours?week=' + week); payroll.post('/hours', {'do': 'approve_week', 'week_ending': week})
status, body = runs_post(payroll, {'do': 'submit', 'run_id': rid})
check('A sheet still waiting on another project stops the submission', status == 422 and 'not approved yet' in body, (status, body[:200]))
payroll.post('/select-project', {'job_id': m06['job_h']})
payroll.get('/hours?week=' + week); payroll.post('/hours', {'do': 'approve_week', 'week_ending': week})
check('Once every sheet is approved, payroll submits', runs_post(payroll, {'do': 'submit', 'run_id': rid})[0] == 200 and run_of(week)['status'] == 'submitted')

# ── frozen ───────────────────────────────────────────────────────────────
payroll.post('/select-project', {'job_id': m06['job_g']})
payroll.get('/hours?week=' + week)
status, body = payroll.post('/hours', {'do': 'import_attendance', 'week_ending': week})
check('A submitted week refuses a new import', status == 422 and 'Nothing in it changes' in body, (status, body[:200]))
status, body = payroll.post('/hours', {'do': 'save', 'week_ending': week, 'hours[%d]' % m06['p_worker']: '50'})
check('A submitted week refuses typed hours', status == 422)
day = next(d for d in probe()['days'] if int(d['placement_id']) == m06['p_worker'] and d['work_date'] == m06['day'])
payroll.get('/attendance')
status, body = payroll.post('/attendance', {'do': 'correct', 'attendance_id': day['id'], 'hours': '9', 'reason': 'Too late now'})
check('A submitted week refuses an attendance correction', status == 422 and float(next(d for d in probe()['days'] if d['id'] == day['id'])['hours']) == 8)

# ── four eyes ────────────────────────────────────────────────────────────
check('Payroll cannot approve a period', runs_post(payroll, {'do': 'approve', 'run_id': rid})[0] == 403)
check('An administrator who did not submit it approves it', runs_post(admin, {'do': 'approve', 'run_id': rid})[0] == 200 and run_of(week)['status'] == 'approved')
check('Payroll locks it as paid', runs_post(payroll, {'do': 'lock', 'run_id': rid})[0] == 200 and run_of(week)['status'] == 'locked')
status, body = runs_post(admin, {'do': 'reopen', 'run_id': rid, 'reason': 'Try to reopen'})
check('A locked period cannot be reopened', status == 422 and 'final' in body)

check('An administrator opens the later period', runs_post(admin, {'do': 'open', 'week_ending': later})[0] == 200)
rid2 = int(run_of(later)['id'])
check('and submits it, with nothing in it', runs_post(admin, {'do': 'submit', 'run_id': rid2})[0] == 200)
status, body = runs_post(admin, {'do': 'approve', 'run_id': rid2})
check('The administrator who submitted it cannot approve it', status == 422 and 'does not approve it' in body, (status, body[:200]))
check('A second administrator can', runs_post(admin2, {'do': 'approve', 'run_id': rid2})[0] == 200)
check('Reopening needs a reason', runs_post(admin2, {'do': 'reopen', 'run_id': rid2, 'reason': ''})[0] == 422)
check('With a reason, an approved period reopens', runs_post(admin2, {'do': 'reopen', 'run_id': rid2, 'reason': 'Late correction to pay'})[0] == 200 and run_of(later)['status'] == 'open')

# ── adjustments ──────────────────────────────────────────────────────────
status, body = payroll.get('/payroll-runs?id=%d' % rid2)
check('The paid week whose days changed is listed to settle', 'data-difference="%d"' % m06['diff_sheet'] in body)
check('Paying the difference: 2 hours at 20',
      runs_post(payroll, {'do': 'adjust', 'run_id': rid2, 'candidate_id': m06['c_diff'], 'timesheet_id': m06['diff_sheet'],
                          'kind': 'correction', 'amount': '40', 'hours': '2', 'reason': 'Attendance corrected after the week was paid'})[0] == 200)
check('Once settled, it is no longer listed', 'data-difference="%d"' % m06['diff_sheet'] not in payroll.get('/payroll-runs?id=%d' % rid2)[1])
check('A recovery is taken back, stored negative',
      runs_post(payroll, {'do': 'adjust', 'run_id': rid2, 'candidate_id': m06['c_worker'], 'kind': 'recovery', 'amount': '10', 'reason': 'Overpaid boots'})[0] == 200
      and [float(a['amount']) for a in probe()['adjustments'] if int(a['candidate_id']) == m06['c_worker']] == [-10.0])
for label, values in [('An adjustment in a locked period is refused', {'run_id': rid, 'candidate_id': m06['c_worker'], 'kind': 'back_pay', 'amount': '5', 'reason': 'Locked'}),
                      ('An adjustment of 0 is refused', {'run_id': rid2, 'candidate_id': m06['c_worker'], 'kind': 'back_pay', 'amount': '0', 'reason': 'Zero'}),
                      ('An adjustment without a reason is refused', {'run_id': rid2, 'candidate_id': m06['c_worker'], 'kind': 'back_pay', 'amount': '5', 'reason': ''}),
                      ('An unknown kind is refused', {'run_id': rid2, 'candidate_id': m06['c_worker'], 'kind': 'bonus', 'amount': '5', 'reason': 'Unknown'}),
                      ('A week that is not this person\'s is refused', {'run_id': rid2, 'candidate_id': m06['c_worker'], 'timesheet_id': m06['diff_sheet'], 'kind': 'correction', 'amount': '5', 'reason': 'Wrong person'})]:
    status, body = runs_post(payroll, {'do': 'adjust', **values})
    check(label, status == 422, (status, body[:200]))
check('Refused adjustments wrote nothing', len(probe()['adjustments']) == 2)

# ── reconciliation and payslips ──────────────────────────────────────────
# The locked week: 5 x 8 = 40 h at 20 = 800, no deductions.
status, body = payroll.get('/payroll-runs?id=%d' % rid)
row = re.search(r'<tr data-person="%d">(.*?)</tr>' % m06['c_worker'], body, re.S)
check('Reconciliation shows 800.00 to pay for the week', row and '$800.00' in row[1])
check('The audit trail holds open, submit, approve and lock, in order',
      [e['event'] for e in probe()['events'] if int(e['run_id']) == rid] == ['open', 'submit', 'approve', 'lock'])

status, body = payroll.get('/payslip?run=%d&candidate=%d' % (rid, m06['c_worker']))
check('Payroll opens the pay statement', status == 200 and 'Pay statement' in body and '$800.00' in body and 'app-icon.svg' in body)
check('It says taxes are the provider\'s', 'withheld by the payroll provider' in body)
status, body = worker.get('/payslip?run=%d&candidate=%d' % (rid, m06['c_other']))
check('A worker sees their own statement, whatever candidate is asked for', status == 200 and 'M06 worker' in body and 'M06 elsewhere' not in body)
check('A worker cannot see a period not yet approved', worker.get('/payslip?run=%d' % rid2)[0] == 404)
status, body = worker.get('/my-payslips')
check('My pay statements lists the approved week', status == 200 and '/payslip?run=%d' % rid in body and '/payslip?run=%d' % rid2 not in body)
check('Hotels cannot open a pay statement', hotels.get('/payslip?run=%d&candidate=%d' % (rid, m06['c_worker']))[0] == 403)

status, body = payroll.get('/payroll-runs?id=%d&lang=fr' % rid)
check('Pay periods is translated into French', 'Périodes de paie' in body and 'Rapprochement' in body)
status, body = worker.get('/payslip?run=%d&lang=es' % rid)
check('The pay statement is translated into Spanish', 'Comprobante de pago' in body)

json.dump(results, open('work/m06-http-results.json', 'w'), indent=1)
