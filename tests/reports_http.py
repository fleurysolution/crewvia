"""P4-M01 through the screens, on the 2017 fixture of reports_db.php: each
of the ten reports with exact figures, filters by date, year and project,
who may read which report, printing, the read being logged, translations.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/reports.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []
A, B = fx['jobA'], fx['jobB']
FEB = '&from=2017-02-01&to=2017-02-28'


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


def report(client, query):
    status, body = client.get('/reports?r=' + query)
    table = {}
    for key, cells in re.findall(r'<tr data-row="([^"]+)">(.*?)</tr>', body, re.S):
        table[key] = dict(re.findall(r'data-c="([^"]+)" data-v="([^"]*)"', cells))
    m = re.search(r'id="report-totals">(.*?)</tr>', body, re.S)
    totals = dict(re.findall(r'data-c="([^"]+)" data-v="([^"]*)"', m[1])) if m else {}
    return status, body, table, totals


admin = Client(); check('P4-M01 admin login', admin.login('rp-admin@test.invalid')[0] == 200)
payroll = Client(); check('P4-M01 payroll login', payroll.login('rp-payroll@test.invalid')[0] == 200)
recruiter = Client(); check('P4-M01 recruiter login', recruiter.login('rp-recruiter@test.invalid')[0] == 200)
hotels = Client(); check('P4-M01 hotels login', hotels.login('rp-hotels@test.invalid')[0] == 200)

# ── who reads what ───────────────────────────────────────────────────────
check('The hotel desk has no reports', hotels.get('/reports')[0] == 403 and hotels.get('/reports?r=employees')[0] == 403)
status, body = recruiter.get('/reports')
listed = set(re.findall(r'data-report="([^"]+)"', body))
check('Recruiting sees employees, headcount, attendance, leave and performance', listed == {'employees', 'headcount', 'attendance', 'leave', 'performance'}, listed)
for k in ['salary', 'deductions', 'payroll', 'loans', 'benefits']:
    check('Recruiting cannot open the %s report' % k, recruiter.get('/reports?r=' + k)[0] == 403)
status, body = payroll.get('/reports')
check('Payroll sees every report but performance', set(re.findall(r'data-report="([^"]+)"', body)) == {'employees', 'headcount', 'attendance', 'leave', 'salary', 'deductions', 'payroll', 'loans', 'benefits'})
check('Payroll cannot open the performance report', payroll.get('/reports?r=performance')[0] == 403)
check('An administrator sees all ten', len(set(re.findall(r'data-report="([^"]+)"', admin.get('/reports')[1]))) == 10)
check('An unknown report is not found', admin.get('/reports?r=secrets')[0] == 404)

# ── employees ────────────────────────────────────────────────────────────
status, body, t, tot = report(recruiter, 'employees&asof=2017-02-28&job_id=%d' % A)
ann, ben, cal, dee = 'c%d' % fx['ann'], 'c%d' % fx['ben'], 'c%d' % fx['cal'], 'c%d' % fx['dee']
check('Employees on the project at the end of February: Ann, Ben and Cal, not Dee', set(t) == {ann, ben, cal}, t)
check('Ann: E-101, hourly, electrical, on assignment since 2 January, eligible for rehire',
      t[ann] == {'name': 'Ann Reportable', 'number': 'E-101', 'type': 'hourly', 'trade': 'electrical', 'availability': 'on_assignment',
                 'project': 'P4-M01 project', 'status': 'on assignment', 'start': '2017-01-02', 'rehire': 'eligible'}, t[ann])
check('Ben has ended', t[ben]['status'] == 'ended' and t[ben]['type'] == 'salaried')
check('Three people, two on assignment', tot.get('name') == '3' and tot.get('status') == '2', tot)
check('The employee list carries no pay', 'data-c="rate"' not in body and 'data-c="salary"' not in body)
status, body, t, tot = report(recruiter, 'employees&asof=2017-02-28')
check('Without a project, Dee is listed too', dee in t and t[dee]['project'] == 'P4-M01 second project')

# ── headcount ────────────────────────────────────────────────────────────
status, body, t, tot = report(recruiter, 'headcount' + FEB)
check('Headcount, February: the project starts with 3, gains Cal, loses Ben, ends with 2',
      t.get('j%d' % A) == {'project': 'P4-M01 project', 'opening': '3', 'started': '1', 'ended': '1', 'closing': '2', 'net': '-1'}, t.get('j%d' % A))
check('The second project holds 1 throughout', t.get('j%d' % B) == {'project': 'P4-M01 second project', 'opening': '1', 'started': '0', 'ended': '0', 'closing': '1', 'net': '0'})

# ── attendance ───────────────────────────────────────────────────────────
status, body, t, tot = report(recruiter, 'attendance' + FEB + '&job_id=%d' % A)
pa, pc = 'p%d' % fx['pAnn'], 'p%d' % fx['pCal']
check('Ann: 3 present, 1 absent, 75 %, 3 days of approved leave', pa in t and t[pa]['present'] == '3' and t[pa]['absent'] == '1'
      and t[pa]['rate'] == '75.00' and t[pa]['leave'] == '3', t.get(pa))
check('Cal: 1 present, 100 %', t[pc]['present'] == '1' and t[pc]['rate'] == '100.00' and t[pc]['leave'] == '0')
check('Ben, with nothing marked, is not listed; 4 present, 1 absent, 80 % in all', 'p%d' % fx['pBen'] not in t and tot['present'] == '4' and tot['absent'] == '1'
      and tot['rate'] == '80.00' and tot['leave'] == '3', tot)

# ── leave ────────────────────────────────────────────────────────────────
status, body, t, tot = report(recruiter, 'leave&year=2017&job_id=%d' % A)
check('Ann: 10 days of P4 paid leave, 3 taken, 7 left', t.get(ann + ':p4pto', {}).get('allowed') == '10.00' and t[ann + ':p4pto']['taken'] == '3.00'
      and t[ann + ':p4pto']['left'] == '7.00' and t[ann + ':p4pto']['waiting'] == '0', t.get(ann + ':p4pto'))
check('Cal: all 10 left, one request waiting', t[cal + ':p4pto']['left'] == '10.00' and t[cal + ':p4pto']['waiting'] == '1')
status, body, t, tot = report(recruiter, 'leave&year=2016&job_id=%d' % A)
check('In 2016 nothing was taken or waiting', all(r['taken'] in ('0.00', '0') and r['waiting'] == '0' for r in t.values()))

# ── salaries and rates ───────────────────────────────────────────────────
status, body, t, tot = report(payroll, 'salary&asof=2017-02-28&job_id=%d' % A)
check('At the end of February: Ann at 32 after her raise, 36 % margin on a bill rate of 50', t.get(pa, {}).get('rate') == '32.00' and t[pa]['bill'] == '50.00'
      and t[pa]['margin'] == '36.00' and t[pa]['band'] == '', t.get(pa))
check('Cal at 25, under the 26-35 band of his grade', t[pc]['rate'] == '25.00' and t[pc]['grade'] == 'P4 grade' and t[pc]['band'] == 'yes' and t[pc]['margin'] == '37.50')
check('Ben, gone by then, is not listed; one rate outside its band', 'p%d' % fx['pBen'] not in t and tot['name'] == '2' and tot['band'] == '1', tot)
status, body, t, tot = report(payroll, 'salary&asof=2017-02-10&job_id=%d' % A)
check('On 10 February: Ann still at 30, before her raise', t[pa]['rate'] == '30.00')
check('and Ben on his salary of 2,000 a period, no hourly rate', t['p%d' % fx['pBen']]['salary'] == '2000.00' and t['p%d' % fx['pBen']]['rate'] == '')

# ── deductions ───────────────────────────────────────────────────────────
status, body, t, tot = report(payroll, 'deductions' + FEB + '&job_id=%d' % A)
check('Union dues: 2 people, 3 weeks, 60', t.get('deduction:P4UNION') == {'label': 'P4 union dues', 'code': 'P4UNION', 'side': 'deduction', 'people': '2', 'weeks': '3', 'amount': '60.00'}, t)
check('The employer match: 1 person, 1 week, 15', t['employer contribution:P4MATCH']['amount'] == '15.00' and t['employer contribution:P4MATCH']['people'] == '1')
check('The 5 not taken for lack of pay is said, and taxes are left to ADP', 'not taken for lack of pay $5.00' in body and 'withheld by ADP' in body)
status, body, t, tot = report(payroll, 'deductions' + FEB + '&job_id=%d' % B)
check('The second project took nothing', t == {})

# ── payroll ──────────────────────────────────────────────────────────────
status, body, t, tot = report(payroll, 'payroll' + FEB)
r1, r2 = t.get('w' + fx['w1']), t.get('w' + fx['w2'])
check('Week ending 11 February: 2 people, gross 2,200, deductions 40, employer 15, reimbursed 100, net 2,260',
      r1 and r1['status'] == 'approved' and r1['people'] == '2' and r1['gross'] == '2200.00' and r1['deductions'] == '40.00' and r1['employer'] == '15.00'
      and r1['reimbursed'] == '100.00' and r1['net'] == '2260.00', r1)
check('with 50 of back pay: 2,310 payable, 1 of 2 sheets paid', r1['adjustments'] == '50.00' and r1['payable'] == '2310.00' and r1['paid'] == '1 / 2')
check('Week ending 18 February, locked: 1,180 payable, unpaid', r2 and r2['status'] == 'locked' and r2['payable'] == '1180.00' and r2['paid'] == '0 / 1', r2)
check('February: gross 3,400, payable 3,490', tot['gross'] == '3400.00' and tot['payable'] == '3490.00' and tot['week'] == '2', tot)

# ── advances and loans ───────────────────────────────────────────────────
status, body, t, tot = report(payroll, 'loans&job_id=%d' % A)
a, l = t.get('a%d' % fx['adv']), t.get('a%d' % fx['loan'])
check('Ann\'s advance: 600, 200 repaid, 400 left, 400 behind its schedule', a and a['amount'] == '600.00' and a['repaid'] == '200.00' and a['balance'] == '400.00'
      and a['behind'] == '400.00' and a['status'] == 'paid_out', a)
check('Cal\'s loan: written off, 300, nothing left or behind', l and l['status'] == 'written_off' and l['written_off'] == '300.00' and l['balance'] == '0.00' and l['behind'] == '0.00', l)
check('Ben\'s request, never approved, is left out', len(t) == 2 and tot['amount'] == '900.00' and tot['balance'] == '400.00' and tot['written_off'] == '300.00', tot)

# ── benefits ─────────────────────────────────────────────────────────────
status, body, t, tot = report(payroll, 'benefits&asof=2017-02-28')
d = t.get('plan:P4DENTAL')
check('Dental at the end of February: Ann covered (12 and 30 a week), Cal waived, Ben ended', d and d['covered'] == '1' and d['waived'] == '1'
      and d['employee'] == '12.00' and d['employer'] == '30.00' and d['kind'] == 'dental', d)
status, body, t, tot = report(payroll, 'benefits&asof=2017-02-05')
check('On 5 February Ben was still covered', t['plan:P4DENTAL']['covered'] == '2' and t['plan:P4DENTAL']['employee'] == '24.00')

# ── performance ──────────────────────────────────────────────────────────
status, body, t, tot = report(recruiter, 'performance' + FEB + '&job_id=%d' % A)
check('Ann: one review approved, 85 %, grade B, would rehire', t.get(ann, {}).get('reviews') == '1' and t[ann]['score'] == '85.00' and t[ann]['grade'] == 'B'
      and t[ann]['rehire'] == 'yes', t.get(ann))
check('a goal open, one achieved, a development action overdue', t[ann]['goals_open'] == '1' and t[ann]['goals_achieved'] == '1' and t[ann]['actions_overdue'] == '1')
check('Cal missed a goal and has no review', t[cal]['goals_missed'] == '1' and t[cal]['reviews'] == '0' and t[cal]['score'] == '')
check('One review, 85 % on average', tot['reviews'] == '1' and tot['score'] == '85.00', tot)
status, body, t, tot = report(recruiter, 'performance&from=2017-03-01&to=2017-03-31&job_id=%d' % A)
check('In March no review was approved', all(r['reviews'] == '0' for r in t.values()))

# ── printing and the log ─────────────────────────────────────────────────
status, body = payroll.get('/reports?r=payroll' + FEB + '&print=1')
check('The payroll report prints on Letter turned sideways, with the brand mark as an image and no browser stamp',
      status == 200 and '@page { size: Letter landscape; margin: 0; }' in body and '<img src="/assets/app-icon.svg"' in body and 'background-image' not in body)
check('with the same figures, marked confidential', 'data-v="3490.00"' in body and 'Confidential' in body)
status, body = recruiter.get('/reports?r=headcount' + FEB + '&print=1')
check('A narrow report prints upright', '@page { size: Letter; margin: 0; }' in body)
log = json.loads(subprocess.run([php, 'work/reports_probe.php', str(fx['ids']['payroll'])], check=True, capture_output=True, text=True).stdout)
check('Every report payroll read is logged with its filters', any(r['action'] == 'viewed a report' and r['detail'].startswith('salary asof=2017-02-28') for r in log)
      and any(r['action'] == 'printed a report' and r['detail'].startswith('payroll from=2017-02-01') for r in log), log[-5:])

status, body = recruiter.get('/reports?lang=fr')
check('Reports are translated into French', 'Rapports' in body and 'Effectifs' in body)
status, body = recruiter.get('/reports?r=attendance&lang=es')
check('and into Spanish', 'Asistencia' in body and 'Informes' in body)
recruiter.get('/reports?lang=en')

json.dump(results, open('work/rp-http-results.json', 'w'), indent=1)
