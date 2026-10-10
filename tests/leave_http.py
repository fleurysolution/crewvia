"""P1-M04 through the screens: leave types, eligibility, accrual and carryover,
approval against the balance, paid leave on the weekly sheet.

Runs on :8097 after leave_db.php. Expected figures are worked out by hand
in the comments.
"""
import datetime, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fixture = json.load(open('work/fixture.json'))
m04 = json.load(open('work/m04.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []
year = m04['year']


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


def probe():
    return json.loads(subprocess.run([php, 'work/leave_probe.php', str(m04['job_d'])], check=True, capture_output=True, text=True).stdout)


def kind(slug):
    return next((t for t in probe()['types'] if t['slug'] == slug), None)


def requests_of(email):
    return [r for r in probe()['requests'] if r['email'] == email]


def admin_post(values):
    admin.get('/leave-types')
    return admin.post('/leave-types', values)


def ask(client, placement, slug, starts, ends):
    client.get('/timeoff')
    return client.post('/timeoff', {'do': 'request', 'placement_id': placement, 'leave_type': slug, 'starts_on': starts, 'ends_on': ends, 'reason': 'Test'})


admin = Client(); check('M04 admin login', admin.login('admin@test.invalid')[0] == 200)
payroll = Client(); check('M04 payroll login', payroll.login('m01-payroll@test.invalid')[0] == 200)
recruiter = Client(); check('M04 recruiter login', recruiter.login('m01-recruiter@test.invalid')[0] == 200)
recruiter.post('/select-project', {'job_id': fixture['a']})

# ── who manages the kinds of leave ───────────────────────────────────────
status, body = admin.get('/leave-types')
check('An administrator opens Leave types', status == 200 and 'data-slug="sick"' in body and 'data-slug="unpaid"' in body)
check('Payroll cannot manage leave types', payroll.get('/leave-types')[0] == 403)
check('A recruiter cannot manage leave types', recruiter.get('/leave-types')[0] == 403)

accrual = {'do': 'create', 'label': 'M04 Sick accrual', 'accrual_method': 'hours_worked', 'accrual_hours_per_day': '240',
           'accrual_cap_days': '5', 'carryover_max_days': '2', 'eligible_after_days': '0', 'hours_per_day': '8', 'is_paid': '1', 'sort_order': '10'}
for label, change in [('A one-letter name is refused', {'label': 'X'}),
                      ('Accrual with no hours per day is refused', {'accrual_hours_per_day': ''}),
                      ('A paid day of 0 hours is refused', {'hours_per_day': '0'}),
                      ('A carryover over 366 days is refused', {'carryover_max_days': '400'}),
                      ('An unknown kind of employment is refused', {'eligible_employment_types[]': ['boss']}),
                      ('A name already taken is refused', {'label': 'Sick'})]:
    status, body = admin_post({**accrual, **change})
    check(label, status == 422, (status, body[:200]))
check('Refused types wrote nothing', kind('x') is None and len([t for t in probe()['types'] if t['label'] == 'Sick']) == 1)

check('An administrator creates leave earned from hours worked', admin_post(accrual)[0] == 200)
k = kind('m04_sick_accrual')
check('It is stored as accrual: 1 day per 240 hours, cap 5, 2 carry over',
      k and k['accrual_method'] == 'hours_worked' and float(k['accrual_hours_per_day']) == 240 and int(k['accrual_cap_days']) == 5 and int(k['carryover_max_days']) == 2, k)
check('An administrator creates vacation for employees after 90 days',
      admin_post({'do': 'create', 'label': 'M04 Vacation', 'accrual_method': 'annual', 'days_allowed': '5', 'eligible_after_days': '90',
                  'hours_per_day': '8', 'is_paid': '1', 'eligible_employment_types[]': ['hourly', 'salaried']})[0] == 200
      and kind('m04_vacation')['eligible_employment_types'] == 'hourly,salaried')

# ── who may take it ──────────────────────────────────────────────────────
tomorrow = (datetime.date.today() + datetime.timedelta(days=1)).isoformat()
newcomer = Client(); check('M04 newcomer login', newcomer.login('m04-newcomer@test.invalid')[0] == 200)
contractor = Client(); check('M04 contractor login', contractor.login('m04-contractor@test.invalid')[0] == 200)
veteran = Client(); check('M04 long-serving worker login', veteran.login('m04-accrual@test.invalid')[0] == 200)

status, body = ask(newcomer, m04['p_new'], 'm04_vacation', tomorrow, tomorrow)
check('Ten days in, vacation waiting 90 days is refused', status == 422 and 'after 90 days' in body, (status, body[:200]))
status, body = ask(contractor, m04['p_contractor'], 'm04_vacation', tomorrow, tomorrow)
check('A contractor is refused leave meant for employees', status == 422 and 'not available to this kind of employment' in body, (status, body[:200]))
check('Refused requests wrote nothing', not requests_of('m04-newcomer@test.invalid') and not requests_of('m04-contractor@test.invalid'))
check('Somebody past the waiting period may ask', ask(veteran, m04['p_accrual'], 'm04_vacation', tomorrow, tomorrow)[0] == 200)

# ── accrual and carryover ────────────────────────────────────────────────
# This year 600 approved hours at 1 day per 240 = 2.5 days. Last year 960
# hours = 4 days, 1 taken, 3 unused, of which 2 may carry. 2.5 + 2 = 4.5,
# under the cap of 5.
status, body = veteran.get('/timeoff')
check('The worker sees 4.5 days earned and carried', re.search(r'<div class="n">4\.5</div>', body) is not None)
status, body = ask(veteran, m04['p_accrual'], 'm04_sick_accrual', '%d-11-02' % year, '%d-11-06' % year)
check('Asking for 5 days of 4.5 is refused', status == 422 and 'only 4.5' in body, (status, body[:200]))
check('Asking for 4 days is accepted', ask(veteran, m04['p_accrual'], 'm04_sick_accrual', '%d-11-02' % year, '%d-11-05' % year)[0] == 200)
check('Asking for 1 more day is accepted while the 4 are pending', ask(veteran, m04['p_accrual'], 'm04_sick_accrual', '%d-11-10' % year, '%d-11-10' % year)[0] == 200)

pending = [r for r in requests_of('m04-accrual@test.invalid') if r['leave_type'] == 'm04_sick_accrual' and r['status'] == 'pending']
first, second = sorted(pending, key=lambda r: int(r['id']))


def review(rid, status_value):
    recruiter.get('/timeoff')
    return recruiter.post('/timeoff', {'do': 'review', 'request_id': rid, 'status': status_value, 'review_note': 'Test'})


check('The recruiter approves the 4 days', review(first['id'], 'approved')[0] == 200
      and next(r for r in requests_of('m04-accrual@test.invalid') if r['id'] == first['id'])['status'] == 'approved')
status, body = review(second['id'], 'approved')
check('Approving 1 more day when 0.5 is left is refused', status == 422 and '0.5 left' in body, (status, body[:200]))
check('The refused request stays pending', next(r for r in requests_of('m04-accrual@test.invalid') if r['id'] == second['id'])['status'] == 'pending')
check('It can still be rejected', review(second['id'], 'rejected')[0] == 200
      and next(r for r in requests_of('m04-accrual@test.invalid') if r['id'] == second['id'])['status'] == 'rejected')

check('An administrator retires vacation', admin_post({'do': 'retire', 'slug': 'm04_vacation'})[0] == 200 and int(kind('m04_vacation')['is_active']) == 0)
before = len(requests_of('m04-accrual@test.invalid'))
ask(veteran, m04['p_accrual'], 'm04_vacation', tomorrow, tomorrow)
check('A retired kind of leave can no longer be asked for', len(requests_of('m04-accrual@test.invalid')) == before)

# ── paid leave on the weekly sheet ───────────────────────────────────────
# 24 hours worked and 2 days of paid sick leave at 8 hours: 16 leave hours.
# The 50-hour guarantee counts the leave, so the floor is 34 worked hours:
# 34 x 20 = 680, plus 16 x 20 = 320 of leave pay: 1000, which is the
# guarantee and no more.
week = m04['week']
payroll.post('/select-project', {'job_id': m04['job_d']})
payroll.get('/hours?week=' + week)
check('Payroll imports the week', payroll.post('/hours', {'do': 'import_attendance', 'week_ending': week})[0] == 200)
sheet = {int(s['placement_id']): s for s in probe()['sheets']}
check('The sheet holds 24 hours worked and 16 hours of paid leave',
      float(sheet[m04['p_paid']]['hours_worked']) == 24 and float(sheet[m04['p_paid']]['paid_leave_hours']) == 16, sheet.get(m04['p_paid']))
status, body = payroll.get('/hours?week=' + week)
check('Hours lists the paid leave', 'Paid leave this week' in body and 'data-leave="%d"' % m04['p_paid'] in body)
status, body = payroll.get('/attendance-week?week=' + week)
check('A day worked while on leave is flagged', 'data-kind="hours_on_leave"' in body)

payroll.get('/hours?week=' + week)
check('Payroll approves the week', payroll.post('/hours', {'do': 'approve_week', 'week_ending': week})[0] == 200)
snap = {int(s['placement_id']): s for s in probe()['sheets']}[m04['p_paid']]['snapshot']
check('Frozen: 680 for the guarantee, 320 of leave, 1000 in all',
      snap and snap['labour_cost'] == 680 and snap['leave_pay'] == 320 and snap['pay_total'] == 1000, snap)
check('Leave hours never become overtime', snap['overtime_hours'] == 0)
status, body = payroll.get('/payroll-export?week=%s&export=adp_run' % week)
check('ADP export refuses paid leave it has no code for', status == 422 and 'Paid leave hours have no ADP earnings code' in body, (status, body[:200]))

status, body = admin.get('/leave-types?lang=fr')
check('Leave types is translated into French', 'Types de congé' in body)
status, body = admin.get('/leave-types?lang=es')
check('Leave types is translated into Spanish', 'Tipos de permiso' in body)
admin.get('/leave-types?lang=en')

json.dump(results, open('work/m04-http-results.json', 'w'), indent=1)
