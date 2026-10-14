"""P5-M34 through the screens: absences and the guarantee, guaranteed overtime, taxable per diem.
  - Hours: the project's policy: what an unexcused absence does, and 50 = 40 regular + 10 overtime;
  - each rule against the same week (41 hours, an unexcused Tuesday, a Wednesday on approved time off);
  - payroll marks a local worker's per diem taxable; ADP RUN takes it under its own code, or refuses without one.
Runs after identity_docs_http.py, on the walkthrough fixture and guarantee_policy_db.php's people.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
wk = json.load(open('work/walkthrough.json'))
fx = json.load(open('work/guarantee_policy.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                return r.status, r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace')

    def get(self, path):
        status, body = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body

    def login(self, email, password='TestPassword123!'):
        self.get('/login')
        self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': password}).encode()))

    def post(self, path, values, page=None):
        self.get(page or path)
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    return json.loads(subprocess.run([php, 'work/guarantee_policy_probe.php', *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


def money():
    return probe('money', fx['timesheet'])


payroll = Client(); payroll.login('gp-payroll@test.invalid')
payroll.post('/select-project', {'job_id': wk['job']}, '/hours')


def policy(rule, overtime):
    values = {'do': 'policy', 'weekly_overtime_after': '40', 'overtime_multiplier': '1.5', 'guarantee_absence_rule': rule, 'guarantee_day_hours': '10'}
    if overtime:
        values['guarantee_overtime'] = '1'
    return payroll.post('/hours', values, '/hours')


body = payroll.get('/hours')[1]
check('Hours offers the absence rule and guaranteed overtime with the overtime threshold', 'name="guarantee_absence_rule"' in body and 'name="guarantee_overtime"' in body and 'name="guarantee_day_hours"' in body)

policy('keep', False)
m = money()
check('As before: 41 hours worked are paid 50, 49 regular and 1 overtime ($2,525); absences change nothing',
      m['guarantee'] == 50 and m['paid_hours'] == 50 and m['regular_hours'] == 49 and m['overtime_hours'] == 1 and m['labour_cost'] == 2525 and m['unexcused_absences'] == 0, m)
policy('keep', True)
m = money()
check('With guaranteed overtime: 50 = 40 regular + 10 overtime ($2,750)', m['regular_hours'] == 40 and m['overtime_hours'] == 10 and m['labour_cost'] == 2750, m)
policy('reduce_day', True)
m = money()
check('Each unexcused absence takes a day off: the Tuesday counts, the Wednesday on approved time off does not; 40 left, paid as worked ($2,075)',
      m['unexcused_absences'] == 1 and m['guarantee'] == 40 and m['paid_hours'] == 41 and m['regular_hours'] == 40 and m['overtime_hours'] == 1 and m['labour_cost'] == 2075, m)
body = payroll.get('/hours?week=' + fx['week'])[1]
check('Hours shows the unexcused absence on the week', 'data-absences="1"' in body, re.findall(r'data-absences="\d+"', body))
policy('forfeit', True)
m = money()
check('Under forfeit, one unexcused absence loses the week\'s guarantee: paid as worked', m['guarantee'] == 0 and m['paid_hours'] == 41 and m['labour_cost'] == 2075, m)
status, body = payroll.post('/hours', {'do': 'policy', 'weekly_overtime_after': '40', 'overtime_multiplier': '1.5', 'guarantee_absence_rule': 'everything', 'guarantee_day_hours': '10'}, '/hours')
check('An unknown rule is refused', status == 422 or 'Invalid weekly payroll policy' in body, status)
check('The per diem is days present times the rate, whatever the rule', m['per_diem'] == 150)

# ── per diem, tax-free or taxable ───────────────────────────────────────
PAGE = '/payroll-export?week=' + fx['week']
body = payroll.get(PAGE)[1]
check('Payroll sees each worker\'s per diem tax-free by default', 'data-per-diem-tax="free"' in body)
payroll.post(PAGE, {'do': 'per_diem_tax', 'placement_id': fx['placement'], 'taxable': '1'}, PAGE)
check('and marks a local worker\'s per diem taxable', probe('taxable', fx['placement']) == 1 and money()['per_diem_taxable'] == 1 and 'data-per-diem-tax="taxable"' in payroll.get(PAGE)[1])
status, body = payroll.get(PAGE + '&export=adp_run')
check('ADP RUN refuses a taxable per diem without its own earnings code', 'configure the ADP earnings code for taxable per diem' in body, (status, body[:300]))
probe('taxcode', 'PDT')
status, csv = payroll.get(PAGE + '&export=adp_run')
lines = [l for l in csv.splitlines() if ',GP001,' in l]
check('With it, the taxable per diem goes under that code, the regular and overtime hours under theirs',
      any(',GP001,PDT,,150' in l for l in lines) and not any(',GP001,PD,' in l for l in lines) and any(',GP001,REG,40' in l for l in lines) and any(',GP001,OT,1,' in l for l in lines), lines)
policy('keep', False)

json.dump(results, open('work/gp-http-results.json', 'w'), indent=1)
