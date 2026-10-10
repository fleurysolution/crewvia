"""P3-M01 through the screens: every cost category from its one source,
what must not count, overtime, burden and overhead rates, profitability,
budget against actual with its history, roles, and that reading the report
writes nothing.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
cj = json.load(open('work/cost.json'))
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
    return json.loads(subprocess.run([php, 'work/costing_probe.php', str(cj['job'])], check=True, capture_output=True, text=True).stdout)


def report(client):
    status, body = client.get('/project-costs')
    lines = {m[0]: (float(m[1]), float(m[2]) if m[2] else None) for m in re.findall(r'data-line="(\w+)" data-actual="([\d.-]+)"(?: data-budget="([\d.-]+)")?', body)}
    variance = dict(re.findall(r'data-line="(\w+)"[^>]*>(?:(?!</tr>).)*?data-variance="([\d.-]*)"', body, re.S))
    figures = {k: v for k, v in re.findall(r'data-figure="([\w-]+)">([^<]+)<', body)}
    return status, body, lines, variance, figures


admin = Client(); check('P3-M01 admin login', admin.login('cost-admin@test.invalid')[0] == 200)
payroll = Client(); check('P3-M01 payroll login', payroll.login('cost-payroll@test.invalid')[0] == 200)
recruiter = Client(); check('P3-M01 recruiter login', recruiter.login('cost-recruiter@test.invalid')[0] == 200)
for c in (admin, payroll, recruiter):
    c.post('/select-project', {'job_id': cj['job']})

check('A recruiter cannot see project costs', recruiter.get('/project-costs')[0] == 403)
before = probe()['counts']

# ── every category from its one source, no rates yet ─────────────────────
status, body, lines, variance, figures = report(payroll)
actual = {k: v[0] for k, v in lines.items()}
check('Payroll opens the report', status == 200 and len(lines) == 9, lines)
check('Revenue: approved and paid weeks only, 45 x 95 + 40 x 95 = 8,075', actual['revenue'] == 8075, actual)
check('Labour: 1,425 + 1,200 wages and 75 back pay = 2,700; the submitted week is left out', actual['labour'] == 2700, actual)
check('Burden without a rate: the 50 of employer contributions', actual['burden'] == 50, actual)
check('Per diem and expenses: 150 + 150 + 20 on the sheets and 30 reimbursed = 350', actual['per_diem'] == 350, actual)
check('Hotels: 6 nights x 100 and a 40 claim = 640; the cancelled stay and the hotel invoices are not added', actual['hotels'] == 640, actual)
check('Transportation: 350 flight booked, 300 flight claim, 800 vehicle invoice = 1,450; cancelled and unapproved left out', actual['transportation'] == 1450, actual)
check('Equipment: the 300 invoice against the safety order', actual['equipment'] == 300, actual)
check('Other: the approved 90 invoice; the client-paid claim and the unapproved invoice are not counted', actual['other'] == 90, actual)
check('Overhead without a rate is 0', actual['overhead'] == 0, actual)
check('Overtime: 5 hours costing 225', figures.get('overtime-hours') == '5.00' and figures.get('overtime-cost') == '$225.00', figures)
check('Still on order and not invoiced: 200 of the 500 safety order', 'id="committed">$200.00<' in body)
check('To reconcile: 8,000 invoiced to the client (the void one left out), 840 of hotel invoices',
      figures.get('invoiced-clients') == '$8,000.00' and figures.get('invoiced-hotels') == '$840.00', figures)
check('Week by week: the week ending 8 March, 8,075 against 2,625 + 300 + 20 + 50 = 2,995',
      re.search(r'data-week="2025-03-08">.*?\$8,075\.00.*?\$2,995\.00', body, re.S) is not None)
check('Payroll sees no budget or rate form', 'id="budget-form"' not in body and 'id="rates-form"' not in body)

# ── rates ────────────────────────────────────────────────────────────────
admin.get('/project-costs')
check('Payroll cannot change the rates', payroll.post('/project-costs', {'do': 'rates', 'burden_percent': '10', 'overhead_percent': '5'})[0] == 403)
check('A rate above 100% is refused', admin.post('/project-costs', {'do': 'rates', 'burden_percent': '101', 'overhead_percent': ''})[0] == 422)
check('An administrator sets 10% burden and 5% overhead', admin.post('/project-costs', {'do': 'rates', 'burden_percent': '10', 'overhead_percent': '5'})[0] == 200
      and probe()['rates'] == {'burden_percent': 10, 'overhead_percent': 5}, probe()['rates'])
status, body, lines, variance, figures = report(admin)
actual = {k: v[0] for k, v in lines.items()}
check('Burden: 50 + 10% of 2,625 wages = 312.50', actual['burden'] == 312.5, actual)
check('Overhead: 5% of 8,075 = 403.75', actual['overhead'] == 403.75, actual)
check('Direct cost 5,842.50, gross margin 2,232.50 (27.6%), after overhead 1,828.75 (22.6%)',
      figures.get('direct') == '$5,842.50' and figures.get('gross') == '$2,232.50' and figures.get('net') == '$1,828.75'
      and 'Gross margin · 27.6%' in body and 'After overhead · 22.6%' in body, figures)

# ── budget against actual ────────────────────────────────────────────────
def budget(client, category, amount, reason):
    client.get('/project-costs')
    return client.post('/project-costs', {'do': 'budget', 'category': category, 'amount': amount, 'reason': reason})

check('Payroll cannot set a budget', budget(payroll, 'labour', '2500', 'Initial estimate')[0] == 403)
check('A budget without a reason is refused', budget(admin, 'labour', '2500', '')[0] == 422)
check('A negative budget is refused', budget(admin, 'labour', '-1', 'Initial estimate')[0] == 422)
check('An unknown budget line is refused', budget(admin, 'bonus', '10', 'Initial estimate')[0] == 422)
check('Labour budgeted at 2,500', budget(admin, 'labour', '2500', 'Initial estimate')[0] == 200)
check('Revenue budgeted at 8,000', budget(admin, 'revenue', '8000', 'Signed order')[0] == 200)
status, body, lines, variance, figures = report(admin)
check('Labour is 200 over its budget, 108% used; revenue is 75 above its budget',
      lines['labour'] == (2700, 2500) and variance.get('labour') == '-200.00' and variance.get('revenue') == '75.00' and '108.0%' in body, (lines, variance))
check('The same figure again is refused', budget(admin, 'labour', '2500', 'Again')[0] == 422)
check('Labour raised to 3,000 after the overtime', budget(admin, 'labour', '3000', 'Overtime approved by the client')[0] == 200)
changes = [c for c in probe()['changes'] if c['category'] == 'labour']
check('Both labour changes are kept, the second with what it replaced', [c['amount'] for c in changes] == [2500, 3000]
      and changes[1]['previous_amount'] == 2500 and changes[1]['reason'] == 'Overtime approved by the client', changes)
status, body, lines, variance, figures = report(admin)
check('The current labour budget is the latest change, now 300 under', lines['labour'][1] == 3000 and variance.get('labour') == '300.00', (lines, variance))
check('The budget history shows the change from 2,500 to 3,000', 'Overtime approved by the client' in body and '$2,500.00' in body)

# ── reading writes nothing ───────────────────────────────────────────────
for _ in range(2):
    report(admin); report(payroll)
check('Opening the report creates no cost, invoice, sheet or claim', probe()['counts'] == before, (before, probe()['counts']))

status, body = admin.get('/project-costs?lang=fr')
check('Project costs is translated into French', 'Coûts du projet' in body and 'Budget comparé au réel' in body)
status, body = payroll.get('/project-costs?lang=es')
check('Project costs is translated into Spanish', 'Costos del proyecto' in body)
for c in (admin, payroll):
    c.get('/project-costs?lang=en')

json.dump(results, open('work/cost-http-results.json', 'w'), indent=1)
