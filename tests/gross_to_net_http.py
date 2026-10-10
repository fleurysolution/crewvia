"""P1-M05 through the screens: pay items, a person's deductions and
contributions, gross to net frozen at approval, advances repaid, export.

Runs on :8097 after gross_to_net_db.php. Expected figures are worked out by
hand in the comments.
"""
import csv, http.cookiejar, io, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
m05 = json.load(open('work/m05.json'))
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
    return json.loads(subprocess.run([php, 'work/gross_to_net_probe.php'], check=True, capture_output=True, text=True).stdout)


def item(code):
    return next((i for i in probe()['items'] if i['code'] == code), None)


def items_post(values):
    payroll.get('/pay-items')
    return payroll.post('/pay-items', values)


def folder(client, cid, values):
    client.get('/employee-folder?id=%d' % cid)
    return client.post('/employee-folder', {'candidate_id': cid, **values})


payroll = Client(); check('M05 payroll login', payroll.login('m01-payroll@test.invalid')[0] == 200)
recruiter = Client(); check('M05 recruiter login', recruiter.login('m01-recruiter@test.invalid')[0] == 200)
hotels = Client(); check('M05 hotels login', hotels.login('m01-hotels@test.invalid')[0] == 200)

# ── pay items ────────────────────────────────────────────────────────────
status, body = payroll.get('/pay-items')
check('Payroll opens Pay items, with advance repayment already there', status == 200 and 'data-code="advance_repayment"' in body)
check('A recruiter cannot open Pay items', recruiter.get('/pay-items')[0] == 403)
check('Hotels cannot open Pay items', hotels.get('/pay-items')[0] == 403)

union = {'do': 'create', 'label': 'M05 Union dues', 'side': 'deduction', 'method': 'percent_of_gross', 'provider_code': 'UNION'}
for label, change in [('A one-letter name is refused', {'label': 'X'}),
                      ('A provider code with a space is refused', {'provider_code': 'UN ION'}),
                      ('An unknown side is refused', {'side': 'tax'}),
                      ('Advance repayment cannot be created by hand', {'method': 'advance_repayment'}),
                      ('A name already taken is refused', {'label': 'Advance repayment'})]:
    status, body = items_post({**union, **change})
    check(label, status == 422, (status, body[:200]))
check('Refused items wrote nothing', len(probe()['items']) == 1)
check('Payroll creates union dues as 2% of gross', items_post(union)[0] == 200 and item('m05_union_dues')['method'] == 'percent_of_gross')
check('Payroll creates workers comp as an employer contribution',
      items_post({'do': 'create', 'label': 'M05 Workers comp', 'side': 'employer_contribution', 'method': 'percent_of_gross'})[0] == 200
      and item('m05_workers_comp')['side'] == 'employer_contribution')
check('Payroll creates a uniform as a fixed deduction',
      items_post({'do': 'create', 'label': 'M05 Uniform', 'side': 'deduction', 'method': 'fixed', 'provider_code': 'UNI'})[0] == 200)

# ── on people ────────────────────────────────────────────────────────────
uid = lambda code: item(code)['id']
start = m05['start']
check('Union dues 2% for the full-week worker', folder(payroll, m05['c_full'], {'do': 'pay_item_add', 'pay_item_id': uid('m05_union_dues'), 'amount': '2', 'starts_on': start})[0] == 200)
check('Workers comp 3.5% for the full-week worker', folder(payroll, m05['c_full'], {'do': 'pay_item_add', 'pay_item_id': uid('m05_workers_comp'), 'amount': '3.5', 'starts_on': start})[0] == 200)
check('A 25 uniform for the worker on two projects', folder(payroll, m05['c_split'], {'do': 'pay_item_add', 'pay_item_id': uid('m05_uniform'), 'amount': '25', 'starts_on': start})[0] == 200)
check('A 50 uniform for the short-week worker', folder(payroll, m05['c_short'], {'do': 'pay_item_add', 'pay_item_id': uid('m05_uniform'), 'amount': '50', 'starts_on': start})[0] == 200)
for label, client, values, code in [
        ('A percentage over 100 is refused', payroll, {'do': 'pay_item_add', 'pay_item_id': uid('m05_union_dues'), 'amount': '150', 'starts_on': start}, 422),
        ('A zero amount is refused', payroll, {'do': 'pay_item_add', 'pay_item_id': uid('m05_uniform'), 'amount': '0', 'starts_on': start}, 422),
        ('The same item twice while it runs is refused', payroll, {'do': 'pay_item_add', 'pay_item_id': uid('m05_union_dues'), 'amount': '3', 'starts_on': start}, 422),
        ('Advance repayment is never set by hand', payroll, {'do': 'pay_item_add', 'pay_item_id': uid('advance_repayment'), 'amount': '10', 'starts_on': start}, 422),
        ('A recruiter cannot set what is taken from pay', recruiter, {'do': 'pay_item_add', 'pay_item_id': uid('m05_uniform'), 'amount': '10', 'starts_on': start}, 403)]:
    status, body = folder(client, m05['c_full'], values)
    check(label, status == code, (status, body[:200]))
check('Refused items were not given to anybody', len([a for a in probe()['assigned'] if a['candidate_id'] == m05['c_full']]) == 2)
check('A recruiter does not see the deductions card', 'Deductions and contributions' not in recruiter.get('/employee-folder?id=%d' % m05['c_full'])[1])

# ── the week ─────────────────────────────────────────────────────────────
# Full week: 40 h x 20 = 800 gross. Union 2% = 16, advance 100 of 300:
# 116 taken, 684 net before taxes; workers comp 3.5% = 28 on top.
week = m05['week']
payroll.post('/select-project', {'job_id': m05['job_e']})
payroll.get('/hours?week=' + week)
check('Payroll imports the week', payroll.post('/hours', {'do': 'import_attendance', 'week_ending': week})[0] == 200)
status, body = payroll.get('/hours?week=' + week)
row = re.search(r'<tr data-net="%d">(.*?)</tr>' % m05['p_full'], body, re.S)
check('Hours previews 684.00 net before taxes', row and '$684.00' in row[1] and 'Preview' in row[1], row[1] if row else body[:300])

payroll.get('/hours?week=' + week)
check('Payroll approves the week', payroll.post('/hours', {'do': 'approve_week', 'week_ending': week})[0] == 200)
p = probe()
net = p['sheets'][str(m05['p_full'])]['net']
check('Frozen: 800 gross, 116 deducted, 684 net, 28 employer',
      net and net['gross_wages'] == 800 and net['total_deductions'] == 116 and net['net_before_tax'] == 684 and net['total_employer'] == 28, net)
adv = p['advances'][str(m05['a_full'])]
check('The advance repayment is recorded against the sheet: 200 left',
      adv['balance'] == 200 and len(adv['payments']) == 1 and float(adv['payments'][0]['amount']) == 100
      and int(adv['payments'][0]['timesheet_id']) == int(p['sheets'][str(m05['p_full'])]['id']), adv)

# Short week: 5 h x 20 = 100. Uniform 50, then the advance wants 70 and only
# 50 is left: 50 taken, 20 short, nothing below zero.
short = p['sheets'][str(m05['p_short'])]['net']
check('A short week never goes below zero, and reports the shortfall',
      short['net_before_tax'] == 0 and short['total_deductions'] == 100 and short['shortfalls'][0]['amount'] == 20, short)
check('The partial repayment is recorded: 20 still owed', p['advances'][str(m05['a_short'])]['balance'] == 20)

# Two projects, one week: the uniform is taken on the first approved, once.
check('On the first project, the uniform is taken', [d['amount'] for d in p['sheets'][str(m05['p_split_e'])]['net']['deductions']] == [25])
payroll.post('/select-project', {'job_id': m05['job_f']})
payroll.get('/hours?week=' + week)
payroll.post('/hours', {'do': 'import_attendance', 'week_ending': week})
payroll.get('/hours?week=' + week)
payroll.post('/hours', {'do': 'approve_week', 'week_ending': week})
other = probe()['sheets'][str(m05['p_split_f'])]
check('On the second project the same week, it is not taken again',
      other['status'] == 'approved' and other['net']['taken_on_other_sheet'] and other['net']['total_deductions'] == 0, other)

# ── export ───────────────────────────────────────────────────────────────
payroll.post('/select-project', {'job_id': m05['job_e']})
status, body = payroll.get('/payroll-export?week=%s&export=review' % week)
rows = list(csv.reader(io.StringIO(body)))
head = rows[0]
line = next(r for r in rows[1:] if r[0] == 'M05 full')
check('The review export carries gross, deductions, net and employer',
      head[-4:] == ['Gross wages', 'Deductions', 'Net before taxes', 'Employer contributions']
      and [float(x) for x in line[-4:]] == [800, 116, 684, 28], (head, line))
status, body = payroll.get('/payroll-export?week=%s&export=adp_run' % week)
check('ADP export refuses a deduction with no provider code', status == 422 and 'no ADP deduction code: Advance repayment' in body, (status, body[:200]))
check('Payroll gives advance repayment its code', items_post({'do': 'code', 'pay_item_id': uid('advance_repayment'), 'provider_code': 'ADV'})[0] == 200)
status, body = payroll.get('/payroll-export?week=%s&export=adp_run' % week)
check('ADP export then writes each deduction as a negative line with its code',
      status == 200 and ',UNION,,-16.00,' in body and ',ADV,,-100.00,' in body, (status, body[:400]))

status, body = payroll.get('/pay-items?lang=fr')
check('Pay items is translated into French', 'Éléments de paie' in body)
status, body = payroll.get('/pay-items?lang=es')
check('Pay items is translated into Spanish', 'Conceptos de pago' in body)
payroll.get('/pay-items?lang=en')

json.dump(results, open('work/m05-http-results.json', 'w'), indent=1)
