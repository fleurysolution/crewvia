"""P2-M01 through the screens: grades and bands, dated pay changes, the week
paid as it stood, the band enforced, scheduled changes, employment history.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
cp = json.load(open('work/comp.json'))
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
    return json.loads(subprocess.run([php, 'work/compensation_probe.php', str(cp['job'])], check=True, capture_output=True, text=True).stdout)


def grades_post(values):
    admin.get('/pay-grades')
    return admin.post('/pay-grades', {'do': 'save', **values})


def change(client, cid, values):
    client.get('/employee-folder?id=%d' % cid)
    return client.post('/employee-folder', {'do': 'comp_record', 'candidate_id': cid, **values})


def sheet(pid, week):
    return next((s for s in probe()['sheets'] if s['placement_id'] == pid and s['week'] == week), None)


def profile(cid):
    return next(p for p in probe()['profiles'] if int(p['candidate_id']) == cid)


admin = Client(); check('P2-M01 admin login', admin.login('comp-admin@test.invalid')[0] == 200)
payroll = Client(); check('P2-M01 payroll login', payroll.login('comp-payroll@test.invalid')[0] == 200)
recruiter = Client(); check('P2-M01 recruiter login', recruiter.login('comp-recruiter@test.invalid')[0] == 200)
for c in (admin, payroll, recruiter):
    c.post('/select-project', {'job_id': cp['job']})

# ── grades and bands ─────────────────────────────────────────────────────
check('Payroll cannot manage grades', payroll.get('/pay-grades')[0] == 403)
for label, values in [('A one-letter grade is refused', {'label': 'X'}),
                      ('A band whose bottom is above its top is refused', {'label': 'Bad band', 'rate_min': '30', 'rate_max': '20'}),
                      ('An hourly band over 1,000 is refused', {'label': 'Too high', 'rate_max': '2000'})]:
    status, body = grades_post(values)
    check(label, status == 422, (status, body[:200]))
check('An administrator creates Journeyman: 20-30 an hour, 1,000-1,500 a period',
      grades_post({'label': 'Journeyman', 'rate_min': '20', 'rate_max': '30', 'salary_min': '1000', 'salary_max': '1500'})[0] == 200
      and probe()['grades'][0]['code'] == 'journeyman')
check('The same grade name twice is refused', grades_post({'label': 'Journeyman'})[0] == 422)
gid = probe()['grades'][0]['id']

for cid in (cp['hourly'], cp['salaried']):
    change(payroll, cid, {'kind': 'grade', 'grade_id': gid, 'effective_from': cp['today'], 'reason': 'Graded on entry'})
check('Both people are given the grade today, and it is current', all(str(profile(c)['grade_id']) == str(gid) for c in (cp['hourly'], cp['salaried'])))

# ── dated changes ────────────────────────────────────────────────────────
for label, values in [('A change with no reason is refused', {'kind': 'hourly_rate', 'placement_id': cp['p_hourly'], 'amount': '25', 'effective_from': cp['monday_b'], 'reason': ''}),
                      ('An hourly rate of 0 is refused', {'kind': 'hourly_rate', 'placement_id': cp['p_hourly'], 'amount': '0', 'effective_from': cp['monday_b'], 'reason': 'Zero'}),
                      ('An hourly rate on somebody else\'s assignment is refused', {'kind': 'hourly_rate', 'placement_id': cp['p_salaried'], 'amount': '25', 'effective_from': cp['monday_b'], 'reason': 'Wrong person'}),
                      ('Payroll cannot go outside the band', {'kind': 'hourly_rate', 'placement_id': cp['p_hourly'], 'amount': '35', 'effective_from': cp['monday_b'], 'reason': 'Market rate'})]:
    status, body = change(payroll, cp['hourly'], values)
    check(label, status == 422, (status, body[:200]))
check('A raise to 25 an hour from the Monday of week B', change(payroll, cp['hourly'], {'kind': 'hourly_rate', 'placement_id': cp['p_hourly'], 'amount': '25',
      'effective_from': cp['monday_b'], 'reason': 'Promoted to lead welder'})[0] == 200)
check('The same kind on the same date twice is refused', change(payroll, cp['hourly'], {'kind': 'hourly_rate', 'placement_id': cp['p_hourly'], 'amount': '26',
      'effective_from': cp['monday_b'], 'reason': 'Again'})[0] == 422)
check('A salary rise to 1,400 from week B', change(payroll, cp['salaried'], {'kind': 'salary', 'amount': '1400', 'effective_from': cp['monday_b'], 'reason': 'Annual review'})[0] == 200)

# ── the weeks are paid as they stood ─────────────────────────────────────
for week in (cp['week_a'], cp['week_b']):
    payroll.get('/hours?week=' + week); payroll.post('/hours', {'do': 'import_attendance', 'week_ending': week})
    payroll.get('/hours?week=' + week)
    check('Payroll approves the week ending %s' % week, payroll.post('/hours', {'do': 'approve_week', 'week_ending': week})[0] == 200)
a, b = sheet(cp['p_hourly'], cp['week_a']), sheet(cp['p_hourly'], cp['week_b'])
check('Week A is paid at 20: 40 x 20 = 800', a and float(a['pay_rate']) == 20 and float(a['labour_cost']) == 800, a)
check('Week B is paid at 25: 40 x 25 = 1000', b and float(b['pay_rate']) == 25 and float(b['labour_cost']) == 1000, b)
sa, sb = sheet(cp['p_salaried'], cp['week_a']), sheet(cp['p_salaried'], cp['week_b'])
check('The salary is 1,200 in week A and 1,400 in week B', sa and sb and float(sa['labour_cost']) == 1200 and float(sb['labour_cost']) == 1400, (sa, sb))
status, body = change(payroll, cp['hourly'], {'kind': 'hourly_rate', 'placement_id': cp['p_hourly'], 'amount': '27', 'effective_from': cp['tuesday_a'], 'reason': 'Backdated'})
check('A change reaching back into an approved week is refused', status == 422 and 'already approved' in body, (status, body[:200]))
check('The rate in effect is on the assignment now', next(float(r['pay_rate']) for r in probe()['rates'] if int(r['id']) == cp['p_hourly']) == 25)

# ── outside the band, and scheduled ──────────────────────────────────────
check('An administrator may go outside the band, and it is marked', change(admin, cp['salaried'], {'kind': 'salary', 'amount': '1600',
      'effective_from': cp['future'], 'reason': 'Retention offer'})[0] == 200
      and probe()['changes'][-1]['outside_band'] in (1, '1') and probe()['changes'][-1]['applied'] in (0, '0'))
check('A scheduled change does not touch the current salary', float(profile(cp['salaried'])['salary_per_period']) == 1400)
future = probe()['changes'][-1]['id']
payroll.get('/employee-folder?id=%d' % cp['salaried'])
check('Payroll cancels it before its date', payroll.post('/employee-folder', {'do': 'comp_cancel', 'candidate_id': cp['salaried'], 'change_id': future})[0] == 200
      and probe()['changes'][-1]['cancelled'] in (1, '1'))
applied = next(c['id'] for c in probe()['changes'] if c['kind'] == 'hourly_rate')
payroll.get('/employee-folder?id=%d' % cp['hourly'])
check('A change already in effect cannot be cancelled', payroll.post('/employee-folder', {'do': 'comp_cancel', 'candidate_id': cp['hourly'], 'change_id': applied})[0] == 422)
payroll.get('/employee-folder?id=%d' % cp['salaried'])
payroll.post('/employee-folder', {'do': 'payment', 'candidate_id': cp['salaried'], 'payment_method': 'direct_deposit', 'adp_employee_id': 'COMPSALARIED', 'salary_per_period': '9999'})
check('The payment form no longer types over the salary', float(profile(cp['salaried'])['salary_per_period']) == 1400)
check('A recruiter cannot record pay', change(recruiter, cp['hourly'], {'kind': 'hourly_rate', 'placement_id': cp['p_hourly'], 'amount': '26', 'effective_from': cp['future'], 'reason': 'Not mine'})[0] == 403)

# ── employment history ───────────────────────────────────────────────────
status, body = payroll.get('/employee-folder?id=%d' % cp['hourly'])
check('Payroll sees the history with the pay: promotion, grade and $20.00 -> $25.00',
      'Employment history' in body and 'Promoted' in body and 'Grade changed' in body and '$20.00 → $25.00' in body)
status, body = recruiter.get('/employee-folder?id=%d' % cp['hourly'])
check('A recruiter sees the history without any amount', 'Promoted' in body and 'Grade changed' in body and 'Hourly rate changed' not in body and '$25.00' not in body and 'Pay and grade' not in body)

status, body = admin.get('/pay-grades?lang=fr')
check('Pay grades is translated into French', 'Échelons de paie' in body)
status, body = payroll.get('/employee-folder?id=%d&lang=es' % cp['hourly'])
check('Pay and grade is translated into Spanish', 'Pago y grado' in body and 'Historial de empleo' in body)

json.dump(results, open('work/comp-http-results.json', 'w'), indent=1)
