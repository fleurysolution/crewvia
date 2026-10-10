"""P1-M03 through the screens: rule sets, their confirmation, and a week paid under them.

Runs on :8097 after pay_rules_db.php built the project. Expected figures are
worked out by hand in the comments, not read back from the code under test.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fixture = json.load(open('work/fixture.json'))
m03 = json.load(open('work/m03.json'))
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
    out = subprocess.run([php, 'work/pay_rules_probe.php', str(m03['job'])], check=True, capture_output=True, text=True)
    return json.loads(out.stdout)


def set_id(name):
    return next((int(s['id']) for s in probe()['sets'] if s['name'] == name), None)


def act(client, values, page='/pay-rules'):
    client.get(page)
    return client.post('/pay-rules', values)


payroll = Client(); check('M03 payroll login', payroll.login('m01-payroll@test.invalid')[0] == 200)
payroll.post('/select-project', {'job_id': m03['job']})
admin = Client(); check('M03 admin login', admin.login('admin@test.invalid')[0] == 200)
admin.post('/select-project', {'job_id': m03['job']})
hotels = Client(); check('M03 hotels login', hotels.login('m01-hotels@test.invalid')[0] == 200)
recruiter = Client(); check('M03 recruiter login', recruiter.login('m01-recruiter@test.invalid')[0] == 200)

# ── who may see the rules ────────────────────────────────────────────────
status, body = payroll.get('/pay-rules')
check('Payroll opens Pay rules', status == 200 and 'US federal baseline' in body and 'data-status="draft"' in body)
check('The project starts with no rule set, paid as before', 'No rule set: overtime follows the weekly line' in body)
check('Hotels cannot open Pay rules', hotels.get('/pay-rules')[0] == 403)
check('A recruiter cannot open Pay rules', recruiter.get('/pay-rules')[0] == 403)

# ── a draft, and every refusal ───────────────────────────────────────────
rules = {'do': 'create', 'name': 'M03 Test State', 'jurisdiction': 'Test state',
         'weekly_overtime_after': '40', 'weekly_overtime_multiplier': '1.5',
         'daily_overtime_after': '8', 'daily_overtime_multiplier': '1.5',
         'daily_double_after': '12', 'daily_double_multiplier': '2',
         'holiday_multiplier': '1.5', 'notes': 'Synthetic rules for the test.'}
for label, change in [('A name under 3 characters is refused', {'name': 'X'}),
                      ('A rule set without a jurisdiction is refused', {'jurisdiction': ''}),
                      ('Double time before daily overtime is refused', {'daily_double_after': '6'}),
                      ('A multiplier below 1 is refused', {'weekly_overtime_multiplier': '0.9'}),
                      ('A weekly line over 168 hours is refused', {'weekly_overtime_after': '200'}),
                      ('A daily line over 24 hours is refused', {'daily_overtime_after': '30'}),
                      ('The seeded name cannot be taken twice', {'name': 'US federal baseline'})]:
    status, body = act(payroll, {**rules, **change})
    check(label, status == 422, (status, body[:200]))
check('Refused drafts wrote nothing', set_id('M03 Test State') is None and set_id('X') is None)

check('Payroll saves a draft', act(payroll, rules)[0] == 200)
sid = set_id('M03 Test State')
check('The new rule set is a draft', sid and next(s for s in probe()['sets'] if int(s['id']) == sid)['status'] == 'draft')
check('Payroll adds a holiday', act(payroll, {'do': 'holiday_add', 'rule_set_id': sid, 'holiday_date': m03['holiday'], 'holiday_name': 'Test holiday'})[0] == 200)
check('The same holiday twice is refused', act(payroll, {'do': 'holiday_add', 'rule_set_id': sid, 'holiday_date': m03['holiday'], 'holiday_name': 'Again'})[0] == 422)
check('Payroll adds a night shift premium', act(payroll, {'do': 'shift_add', 'rule_set_id': sid, 'shift_label': 'Night shift', 'amount_per_hour': '2'})[0] == 200)
check('A premium over 100 an hour is refused', act(payroll, {'do': 'shift_add', 'rule_set_id': sid, 'shift_label': 'Day', 'amount_per_hour': '150'})[0] == 422)

# ── confirmation before use ──────────────────────────────────────────────
check('A draft cannot be given to a project', act(payroll, {'do': 'assign', 'rule_set_id': sid})[0] == 422 and probe()['project'] is None)
check('Payroll cannot activate a rule set', act(payroll, {'do': 'activate', 'rule_set_id': sid, 'confirmed_by': 'Payroll itself'})[0] == 403)
check('Activation without saying who confirmed it is refused', act(admin, {'do': 'activate', 'rule_set_id': sid, 'confirmed_by': ''})[0] == 422)
check('An administrator activates it, naming who confirmed it',
      act(admin, {'do': 'activate', 'rule_set_id': sid, 'confirmed_by': 'Test payroll provider'})[0] == 200
      and next(s for s in probe()['sets'] if int(s['id']) == sid)['confirmed_by'] == 'Test payroll provider')
check('An active rule set cannot be edited', act(payroll, {**rules, 'do': 'update', 'rule_set_id': sid, 'weekly_overtime_after': '30'})[0] == 422)
check('An active rule set takes no new shift premium', act(payroll, {'do': 'shift_add', 'rule_set_id': sid, 'shift_label': 'Day', 'amount_per_hour': '1'})[0] == 422)
check('Payroll gives the project the active rule set', act(payroll, {'do': 'assign', 'rule_set_id': sid})[0] == 200 and int(probe()['project']) == sid)

# ── a week under the rules ───────────────────────────────────────────────
# 12 + 12 + 8 + 8 + 8 = 48 hours: each 12-hour day is 8 regular and 4 daily
# overtime (double time starts after 12, so none); the week is then 40
# regular, which is not over the weekly line. Wednesday is the holiday: its
# 8 regular hours are paid at 1.5. Night shift adds 2 an hour, so the rate
# is 22: 32 x 22 + 8 x 22 x 1.5 (holiday) + 8 x 22 x 1.5 (overtime) = 1232.
week = m03['week']
payroll.get('/hours?week=' + week)
check('Payroll imports the approved days', payroll.post('/hours', {'do': 'import_attendance', 'week_ending': week})[0] == 200)
status, body = payroll.get('/hours?week=' + week)
row = re.search(r'<tr data-placement="%d">(.*?)</tr>' % m03['p_long'], body, re.S)
cell = lambda k: float(re.search(r'data-k="%s">([0-9.]+)<' % k, row[1])[1])
check('Hours shows the rule set by name', 'Rule set: M03 Test State (Test state)' in body)
check('Regular hours: 40', row and cell('regular') == 40)
check('Daily overtime: 8', cell('daily') == 8)
check('No weekly overtime once the daily line has been paid', cell('weekly') == 0)
check('No double time under 12 hours a day', cell('double') == 0)
check('Holiday hours: 8', cell('holiday') == 8)
check('The night shift premium is shown', '$2.00/h' in row[1])

payroll.get('/hours?week=' + week)
check('Payroll approves the week', payroll.post('/hours', {'do': 'approve_week', 'week_ending': week})[0] == 200)
snap = next(s for s in probe()['sheets'] if int(s['placement_id']) == m03['p_long'] and s['week_ending'] == week)
check('The approved week is frozen with the rules applied: 1232',
      snap['status'] == 'approved' and snap['snapshot']['method'] == 'pay_rules' and snap['snapshot']['labour_cost'] == 1232, snap)

check('An administrator retires the rule set', act(admin, {'do': 'retire', 'rule_set_id': sid})[0] == 200)
snap2 = next(s for s in probe()['sheets'] if int(s['placement_id']) == m03['p_long'] and s['week_ending'] == week)
check('Retiring it does not move an approved week', snap2['snapshot']['labour_cost'] == 1232)
status, body = payroll.get('/hours?week=' + week)
check('A project on a retired rule set is told to move', 'Retired - choose a current rule set' in body)

# ── a week the provider must reconcile ───────────────────────────────────
week2 = m03['week2']
payroll.get('/hours?week=' + week2)
payroll.post('/hours', {'do': 'import_attendance', 'week_ending': week2})
payroll.get('/hours?week=' + week2)
status, body = payroll.post('/hours', {'do': 'approve_week', 'week_ending': week2})
check('A week with hours on another project is held for the provider',
      'the provider must reconcile this week' in body
      and next(s for s in probe()['sheets'] if int(s['placement_id']) == m03['p_split'] and s['week_ending'] == week2)['status'] == 'draft')

# ── the export does not invent codes ─────────────────────────────────────
status, body = payroll.get('/payroll-export?week=%s&export=adp_run' % week)
check('ADP export refuses holiday hours it has no code for', status == 422 and 'no ADP earnings code yet' in body, (status, body[:200]))
status, body = payroll.get('/payroll-export?week=%s&export=review' % week)
check('The review export still downloads', status == 200 and 'M03 long week' in body, (status, body[:200]))

status, body = payroll.get('/pay-rules?lang=fr')
check('Pay rules is translated into French', 'Règles de paie' in body and 'Jeux de règles' in body)
status, body = payroll.get('/pay-rules?lang=es')
check('Pay rules is translated into Spanish', 'Reglas de pago' in body)
payroll.get('/pay-rules?lang=en')

json.dump(results, open('work/m03-http-results.json', 'w'), indent=1)
