"""P2-M04 through the screens and into payroll: plans and their pay items,
who is eligible and from when, enrollment refused before eligibility or
into an approved week, a fixed plan and a percent plan taken by gross to
net, a change of coverage, ending, a waiver, the folder unable to change a
plan's pay items by hand, the worker's own view, roles and translations.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
bj = json.load(open('work/ben.json'))
php = os.environ.get('PHP_BIN', 'php')
A, B, C = bj['a'], bj['b'], bj['c']
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
        return self._send(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}, doseq=True).encode()))

    def login(self, email):
        self.get('/login')
        status, body = self.post('/login', {'email': email, 'password': 'TestPassword123!'})
        return (status if '<title>Sign in' not in body else 401), body


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:600] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    return json.loads(subprocess.run([php, 'work/benefits_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout)


def g2n(person, week):
    return probe('g2n', person['cid'], person['pid'], week)


def act(client, values, page='/benefits'):
    client.get(page)
    return client.post('/benefits', values)


payroll = Client(); check('P2-M04 payroll login', payroll.login('ben-payroll@test.invalid')[0] == 200)
recruiter = Client(); check('P2-M04 recruiter login', recruiter.login('ben-recruiter@test.invalid')[0] == 200)
worker = Client(); check('P2-M04 worker login', worker.login('ben-worker@test.invalid')[0] == 200)
check('A recruiter cannot see benefits', recruiter.get('/benefits')[0] == 403)

# ── plans ────────────────────────────────────────────────────────────────
medical = {'do': 'plan', 'name': 'Core medical', 'kind': 'medical', 'provider': 'Synthetic Health', 'method': 'fixed', 'waiting_days': '30', 'pre_tax': '1',
           'eligible_types[]': ['hourly', 'salaried'],
           'employee_amount[employee]': '25', 'employer_amount[employee]': '40',
           'employee_amount[employee_spouse]': '60', 'employer_amount[employee_spouse]': '80',
           'employee_amount[family]': '90', 'employer_amount[family]': '120'}
check('A plan covering nobody is refused', act(payroll, {**medical, 'eligible_types[]': []})[0] == 422)
check('A fixed plan without the employee-only cost is refused', act(payroll, {**medical, 'employee_amount[employee]': '', 'employer_amount[employee]': ''})[0] == 422)
check('Payroll creates Core medical: 25 + 40 a week for one, 30 days\' wait', act(payroll, medical)[0] == 200)
plan = probe()['plans'][-1]
check('The plan owns its two pay items: a deduction before tax and an employer contribution',
      plan['deduction_code'] == 'ben_core_medical_ee' and plan['pre_tax'] == 1 and plan['employer_code'] == 'ben_core_medical_er' and plan['employer_side'] == 'employer_contribution', plan)
med = plan['id']
check('A percent plan over 25 % from the agency is refused', act(payroll, {'do': 'plan', 'name': 'Savings plan', 'kind': 'retirement', 'method': 'percent', 'waiting_days': '0', 'pre_tax': '1',
      'eligible_types[]': ['hourly'], 'max_employee_percent': '10', 'employer_percent': '30'})[0] == 422)
act(payroll, {'do': 'plan', 'name': 'Savings plan', 'kind': 'retirement', 'method': 'percent', 'waiting_days': '0', 'pre_tax': '1', 'eligible_types[]': ['hourly'], 'max_employee_percent': '10', 'employer_percent': '3'})
sav = probe()['plans'][-1]['id']
check('The savings plan takes a share of gross', probe()['plans'][-1]['deduction_method'] == 'percent_of_gross')

# ── who to offer it to ───────────────────────────────────────────────────
body = payroll.get('/benefits')[1]
check('Alpha is to be offered medical, eligible since day 30 of the first assignment', 'data-offer="%d-%d"' % (A['cid'], med) in body)
check('Charlie, hired 10 days ago, is listed for later; the contractor not at all', 'data-offer="%d-%d"' % (C['cid'], med) in body and 'data-offer="%d-%d"' % (B['cid'], med) not in body)

enroll = lambda person, plan_id, start, extra=None, do='enroll': act(payroll, {'do': do, 'candidate_id': person['cid'], 'plan_id': plan_id, 'starts_on': start, **(extra or {})}, '/benefits?candidate=%d' % person['cid'])
status, body = enroll(B, med, bj['today'], {'tier': 'employee'})
check('A contractor is refused: the plan does not cover them', status == 422 and 'does not cover' in body, body[:200])
status, body = enroll(C, med, bj['today'], {'tier': 'employee'})
check('Charlie is refused until the waiting period ends', status == 422 and 'eligible from' in body, body[:200])
status, body = enroll(A, med, bj['frozen'], {'tier': 'employee'})
check('Coverage starting in a week already approved for pay is refused', status == 422 and 'already approved' in body, body[:200])
check('Alpha is enrolled, employee only, from today', enroll(A, med, bj['today'], {'tier': 'employee'})[0] == 200)
e1 = probe()['enrollments'][-1]
items = [p for p in probe()['pay_items'] if p['benefit_enrollment_id'] == e1['id']]
check('The enrollment put both pay items on Alpha from today: 25 and 40', sorted((p['code'], p['amount'], p['starts_on']) for p in items)
      == [('ben_core_medical_ee', 25, bj['today']), ('ben_core_medical_er', 40, bj['today'])], items)
check('A second enrollment in the same plan from the same date is refused', enroll(A, med, bj['today'], {'tier': 'family'})[0] == 422)
week = g2n(A, bj['week_next'])
check('Payroll takes it next week: 25 before tax from the person, 40 from the agency',
      ['ben_core_medical_ee', 25.0, True] in week['deductions'] and ['ben_core_medical_er', 40.0] in week['employer'], week)

check('Electing 12 % of a plan capped at 10 % is refused', enroll(A, sav, bj['today'], {'employee_percent': '12'})[0] == 422)
check('Alpha elects 5 %', enroll(A, sav, bj['today'], {'employee_percent': '5'})[0] == 200)
week = g2n(A, bj['week_next'])
check('On 1,000 gross: 50 from the person, 30 from the agency', ['ben_savings_plan_ee', 50.0, True] in week['deductions'] and ['ben_savings_plan_er', 30.0] in week['employer'], week)

# ── changes, ends, waivers ───────────────────────────────────────────────
status, _ = act(payroll, {'do': 'change', 'enrollment_id': e1['id'], 'candidate_id': A['cid'], 'tier': 'family', 'starts_on': bj['in14'], 'reason': ''})
check('A change without a reason is refused', status == 422)
check('Alpha marries and has a child: family cover from two weeks on', act(payroll, {'do': 'change', 'enrollment_id': e1['id'], 'candidate_id': A['cid'], 'tier': 'family', 'starts_on': bj['in14'], 'reason': 'Marriage and a birth'})[0] == 200)
mine = [e for e in probe()['enrollments'] if e['candidate_id'] == A['cid'] and e['plan_id'] == med]
check('The old cover ends the day before; the family cover starts, 90 + 120', mine[0]['status'] == 'ended' and mine[0]['ends_on'] is not None
      and mine[1]['status'] == 'enrolled' and mine[1]['tier'] == 'family' and mine[1]['employee_amount'] == 90 and mine[1]['starts_on'] == bj['in14'], mine)
check('Next week still pays 25; a month on pays 90', ['ben_core_medical_ee', 25.0, True] in g2n(A, bj['week_next'])['deductions']
      and ['ben_core_medical_ee', 90.0, True] in g2n(A, bj['week_later'])['deductions'] and ['ben_core_medical_ee', 25.0, True] not in g2n(A, bj['week_later'])['deductions'])
saving = next(e for e in probe()['enrollments'] if e['plan_id'] == sav)
check('An end before coverage started is refused', act(payroll, {'do': 'end', 'enrollment_id': saving['id'], 'candidate_id': A['cid'], 'ends_on': bj['ago10'], 'reason': 'Left'})[0] == 422)
check('Alpha stops saving in two weeks', act(payroll, {'do': 'end', 'enrollment_id': saving['id'], 'candidate_id': A['cid'], 'ends_on': bj['in14'], 'reason': 'Asked to stop'})[0] == 200
      and all(p['ends_on'] == bj['in14'] for p in probe()['pay_items'] if p['benefit_enrollment_id'] == saving['id'])
      and not any(d[0] == 'ben_savings_plan_ee' for d in g2n(A, bj['week_later'])['deductions']))
check('A waiver without a reason is refused', enroll(C, med, bj['c_eligible'], {'reason': ''}, 'waive')[0] == 422)
check('Charlie declines medical from the day they become eligible: covered by a spouse', enroll(C, med, bj['c_eligible'], {'reason': 'Covered by a spouse\'s plan'}, 'waive')[0] == 200
      and probe()['enrollments'][-1]['status'] == 'waived' and not any(p['candidate_id'] == C['cid'] for p in probe()['pay_items']))
check('and is no longer listed to be offered medical', 'data-offer="%d-%d"' % (C['cid'], med) not in payroll.get('/benefits')[1])

# ── the folder cannot change it by hand ──────────────────────────────────
ded = next(p for p in probe()['pay_items'] if p['benefit_enrollment_id'] == mine[1]['id'] and p['code'] == 'ben_core_medical_ee')
payroll.get('/employee-folder?id=%d' % A['cid'])
status, body = payroll.post('/employee-folder', {'do': 'pay_item_end', 'candidate_id': A['cid'], 'employee_pay_item_id': ded['id'], 'ends_on': bj['in30']})
check('Ending a benefit\'s deduction on the employee folder is refused', status == 422 and 'Benefits' in body, body[:200])
payroll.get('/employee-folder?id=%d' % C['cid'])
status, body = payroll.post('/employee-folder', {'do': 'pay_item_add', 'candidate_id': C['cid'], 'pay_item_id': plan['deduction_id'], 'amount': '25', 'starts_on': bj['in30']})
check("Putting a plan's pay item on someone by hand is refused", status == 422 and 'belongs to a benefit plan' in body, body[:200])
check('The folder links to the person\'s benefits', '/benefits?candidate=%d' % A['cid'] in payroll.get('/employee-folder?id=%d' % A['cid'])[1])

# ── the worker ───────────────────────────────────────────────────────────
status, body = worker.get('/benefits')
check('Alpha sees their own coverage, and no form', status == 200 and 'My benefits' in body and 'data-enrollment="%d"' % mine[1]['id'] in body and 'id="enroll-form"' not in body and 'id="plan-form"' not in body)
check('and cannot change it', act(worker, {'do': 'end', 'enrollment_id': mine[1]['id'], 'candidate_id': A['cid'], 'ends_on': bj['in30'], 'reason': 'Mine'})[0] == 403)

status, body = payroll.get('/benefits?lang=fr')
check('Benefits is translated into French', 'Avantages sociaux' in body and 'Nouveau régime' in body)
status, body = payroll.get('/benefits?lang=es')
check('and into Spanish', 'Beneficios' in body)
payroll.get('/benefits?lang=en')

json.dump(results, open('work/ben-http-results.json', 'w'), indent=1)
