"""P5-M14 (T19) through the screens: payroll sets a project's shift start,
grace and rounding; a supervisor records late arrivals for their own crew
with the reason (refused for someone else's crew, a day in the future or
in a frozen week, an arrival that is not late, twice the same day, or no
reason); an assignment's own shift start, past midnight; the worker reads
it on their Lateness page and may dispute it, which docks nothing until
payroll settles it (upheld, reduced, cancelled), and the week cannot be
approved while a dispute is open; the import takes each day's docked time
off that day, pay follows, and the bill too unless the project bills the
docked time; who may do what; notifications; translations.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/lateness.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS, P, DAY, WEEK, JOB = fx['ids'], fx['places'], fx['day'], fx['week'], fx['job']
results = []
L = '/lateness'


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                return r.status, r.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode()

    def get(self, path):
        status, body = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body

    def post(self, values, path=L):
        self.get(path if path != '/select-project' else L)
        data = urllib.parse.urlencode({'_csrf': self.token, **values}).encode()
        return self.open(urllib.request.Request(base + path, data))


def user(key):
    c = Client()
    c.get('/login')
    data = urllib.parse.urlencode({'_csrf': c.token, 'email': 'lt-%s@test.invalid' % key, 'password': 'TestPassword123!'}).encode()
    status, body = c.open(urllib.request.Request(base + '/login', data))
    assert '<title>Sign in' not in body, key
    return c


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe():
    return json.loads(subprocess.run([php, 'work/lateness_probe.php'], check=True, capture_output=True, text=True).stdout)


def late(placement, day):
    return next((x for x in probe()['lateness'] if int(x['placement_id']) == placement and x['work_date'] == day), None)


def sheet(placement):
    return next((s for s in probe()['sheets'] if s['placement_id'] == placement and s['week_ending'] == WEEK), None)


pay, sup, hotels, w, w2 = user('pay'), user('sup'), user('hotels'), user('w'), user('w2')
pay.post({'job_id': JOB}, '/select-project')

rec = lambda c, who, day, at, why='Arrived after the toolbox talk': c.post({'do': 'record', 'placement_id': P[who], 'work_date': DAY[day] if day in DAY else day, 'arrived_at': at, 'reason': why})

# ── the rules ──────────────────────────────────────────────────────────
check('Without a shift start, nothing can be recorded', rec(sup, 'w', 'mon', '07:07')[0] == 422 and late(P['w'], DAY['mon']) is None)
check('Rules need a real time and sensible numbers', pay.post({'do': 'policy', 'shift_start': '7am', 'grace_minutes': 5, 'round_to_minutes': 15})[0] == 422
      and pay.post({'do': 'policy', 'shift_start': '07:00', 'grace_minutes': 45, 'round_to_minutes': 15})[0] == 422)
pay.post({'do': 'policy', 'shift_start': '07:00', 'grace_minutes': 5, 'round_to_minutes': 15})
check('Payroll sets the project\'s shift start, grace and rounding; the docked time is not billed by default', probe()['policy']['shift_start'] == '07:00:00' and int(probe()['policy']['grace_minutes']) == 5 and int(probe()['policy']['bill_docked']) == 0)
check('The page shows what the rules dock', 'With these rules: 5 minutes late docks 0 minutes; 7 minutes late docks 15.' in pay.get(L)[1])

# ── recording ──────────────────────────────────────────────────────────
rec(sup, 'w', 'mon', '07:07')
m = late(P['w'], DAY['mon'])
check('The supervisor records a late arrival: 7 minutes late docks 15', m is not None and int(m['minutes_late']) == 7 and int(m['docked_minutes']) == 15 and m['status'] == 'recorded' and m['reason'] == 'Arrived after the toolbox talk')
check('The worker is told at once', any(int(n['user_id']) == IDS['w'] and '7 minutes late, 15 minutes unpaid' in n['message'] for n in probe()['notifications']))
rec(sup, 'w', 'tue', '07:04')
check('Within the grace it is recorded and nothing is docked', int(late(P['w'], DAY['tue'])['docked_minutes']) == 0)
rec(sup, 'w', 'wed', '07:31')
check('31 minutes late docks 45', int(late(P['w'], DAY['wed'])['docked_minutes']) == 45)
check('Not twice the same day', rec(sup, 'w', 'mon', '07:20')[0] == 422)
check('Not an arrival before the shift start', rec(sup, 'w', 'thu', '06:55')[0] == 422)
check('Not without a reason', rec(sup, 'w', 'thu', '07:20', '')[0] == 422)
check('Not a day in the future', rec(sup, 'w', '2099-01-05', '07:20')[0] == 422)
check('Not a day in a week frozen for pay', rec(sup, 'w', fx['frozen_day'], '07:20')[0] == 422)
check('Not someone outside the supervisor\'s crew', rec(sup, 'w2', 'mon', '07:20')[0] == 422 and late(P['w2'], DAY['mon']) is None)
check('A worker records nothing, Logistics cannot open it', rec(w, 'w', 'thu', '07:20')[0] == 403 and hotels.get(L)[0] == 403)
pay.post({'do': 'assignment_shift', 'placement_id': P['w2'], 'shift_start': '22:00'})
rec(pay, 'w2', 'thu', '00:20', 'Gate log shows 00:20')
n = late(P['w2'], DAY['thu'])
check('An assignment\'s own night shift: arriving at 00:20 for 22:00 is 140 minutes late, 150 docked', n is not None and n['shift_start'] == '22:00:00' and int(n['minutes_late']) == 140 and int(n['docked_minutes']) == 150)

# ── the worker reads and disputes ──────────────────────────────────────
status, body = w.get(L)
check('The worker sees their late arrivals with the reason and what was unpaid', status == 200 and 'Arrived after the toolbox talk' in body and re.search(r'data-docked="%d">15 min' % int(m['id']), body))
check('and only theirs', 'Gate log shows 00:20' not in body and 'Arrived after the toolbox talk' not in w2.get(L)[1])
check('The menu leads there', 'href="/lateness"' in body)
WED = int(late(P['w'], DAY['wed'])['id'])
check('A dispute needs a reason', w.post({'do': 'dispute', 'lateness_id': WED, 'note': ''})[0] == 422)
w.post({'do': 'dispute', 'lateness_id': WED, 'note': 'The bus from the hotel was late, the whole crew arrived at 07:31'})
check('Disputed, it is not docked until settled', late(P['w'], DAY['wed'])['status'] == 'disputed')
check('Payroll is told', any(int(x['user_id']) == IDS['pay'] and 'disputes a late arrival' in x['message'] for x in probe()['notifications']))
check('Not disputed twice, nor by someone else', w.post({'do': 'dispute', 'lateness_id': WED, 'note': 'again please'})[0] == 422
      and w2.post({'do': 'dispute', 'lateness_id': int(m['id']), 'note': 'not mine but still'})[0] == 422)
check('Only payroll settles', sup.post({'do': 'decide', 'lateness_id': WED, 'decision': 'cancelled', 'note': 'ok'})[0] == 403)

# ── into pay ───────────────────────────────────────────────────────────
pay.post({'do': 'import_attendance', 'week_ending': WEEK}, '/hours')
s = sheet(P['w'])
check('The import takes only the docked time off: 50 hours less 15 minutes, the disputed day untouched', s is not None and s['hours_worked'] == 49.75 and s['late_docked_hours'] == 0.25 and s['late_billed_hours'] == 0, s)
check('Pay and bill follow the hours', s['pay'] == 1492.5 and s['bill'] == 2487.5, s)
status, body = pay.post({'do': 'approve_week', 'week_ending': WEEK}, '/hours')
check('The week is not approved while a dispute is open', 'lateness dispute(s) in this week' in body and sheet(P['w'])['status'] != 'approved')
check('Reducing needs fewer minutes than were docked', pay.post({'do': 'decide', 'lateness_id': WED, 'decision': 'reduced', 'minutes': 60, 'note': 'Partly the bus'})[0] == 422)
pay.post({'do': 'decide', 'lateness_id': WED, 'decision': 'reduced', 'minutes': 30, 'note': 'The bus was 15 minutes late; the rest stands'})
d = late(P['w'], DAY['wed'])
check('Payroll reduces it, and the worker reads the decision', d['status'] == 'reduced' and int(d['docked_minutes']) == 30 and 'The bus was 15 minutes late' in w.get(L)[1]
      and any(int(x['user_id']) == IDS['w'] and 'was settled' in x['message'] for x in probe()['notifications']))
pay.post({'do': 'decide', 'lateness_id': int(n['id']), 'decision': 'cancelled', 'note': 'Shift moved, nobody told him'})
check('A cancelled one docks nothing', late(P['w2'], DAY['thu'])['status'] == 'cancelled')
pay.post({'do': 'import_attendance', 'week_ending': WEEK}, '/hours')
s = sheet(P['w'])
check('Imported again: 50 hours less 15 and 30 minutes', s['hours_worked'] == 49.25 and s['late_docked_hours'] == 0.75, s)
check('and the cancelled night docks nothing for the other worker', sheet(P['w2'])['hours_worked'] == 50.0)
pay.post({'do': 'policy', 'shift_start': '07:00', 'grace_minutes': 5, 'round_to_minutes': 15, 'bill_docked': '1'})
pay.post({'do': 'import_attendance', 'week_ending': WEEK}, '/hours')
s = sheet(P['w'])
check('Where the project still bills docked time, the bill keeps it and pay does not', s['late_billed_hours'] == 0.75 and s['pay'] == 1477.5 and s['bill'] == 2500.0, s)
pay.post({'do': 'approve_week', 'week_ending': WEEK}, '/hours')
check('Settled, the week is approved', sheet(P['w'])['status'] == 'approved')
check('Approved for pay, a lateness is no longer disputed or settled here', w.post({'do': 'dispute', 'lateness_id': int(m['id']), 'note': 'too late now?'})[0] == 422
      and pay.post({'do': 'decide', 'lateness_id': int(m['id']), 'decision': 'cancelled', 'note': 'after pay'})[0] == 422)
check('and the worker\'s page says the week is approved for pay', 'week approved for pay' in w.get(L)[1])

# ── translations ───────────────────────────────────────────────────────
w.post({'locale': 'es', 'back': L}, '/language')
check('Lateness in Spanish', 'Retrasos' in w.get(L)[1])
w.post({'locale': 'en', 'back': L}, '/language')

json.dump(results, open('work/lt-http-results.json', 'w'), indent=1)
