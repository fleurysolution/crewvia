"""P5-M35: the demonstration, loaded, used and flushed.
  - the load refuses without --confirm-demo, without DEMO_PASSWORD, and a second time;
  - it creates the three plants (500, 300, 200 people), their trades, sectors, shifts, the office, 65 supervisors, vans and hotels;
  - every account signs in with its plus-address and the one password; people work: a room number, a clock-in, its validation;
  - the flush shows what it would remove and removes nothing; with --execute, every table is back to its count before the load.
Runs last of the walkthrough chain, on the test database.
"""
import http.cookiejar, json, os, re, secrets, subprocess, time, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
php = os.environ.get('PHP_BIN', 'php')
results = []
MAILBOX = 'demo-cycle@test.invalid'
PASSWORD = 'Demo-' + secrets.token_hex(8)


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                return r.status, r.read().decode('utf-8', 'replace'), r.geturl()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), e.geturl()

    def get(self, path):
        status, body, _ = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body

    def login(self, label, password=PASSWORD):
        self.get('/login')
        status, body, url = self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': 'demo-cycle+%s@test.invalid' % label, 'password': password}).encode()))
        return '/login' not in url

    def post(self, path, values, page=None):
        self.get(page or path)
        status, body, _ = self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()))
        return status, body


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:900] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    return json.loads(subprocess.run([php, 'work/demo_cycle_probe.php', *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


def tool(script, *args, password=None):
    env = dict(os.environ)
    env.pop('DEMO_PASSWORD', None)
    if password is not None:
        env['DEMO_PASSWORD'] = password
    r = subprocess.run([php, 'work/test-app/install/' + script, *args], capture_output=True, text=True, encoding='utf-8', env=env)
    return r.returncode, r.stdout + r.stderr


before = probe('counts')

# ── the load refuses what it must ───────────────────────────────────────
code, out = tool('demo-load.php', '--mailbox=' + MAILBOX, password=PASSWORD)
check('The load refuses without --confirm-demo', code == 2 and 'confirm-demo' in out and probe('counts') == before, out)
code, out = tool('demo-load.php', '--confirm-demo', '--mailbox=' + MAILBOX, password='short')
check('and without a DEMO_PASSWORD of 12 characters or more', code == 2 and 'DEMO_PASSWORD' in out and probe('counts') == before, out)

# ── the load ────────────────────────────────────────────────────────────
t0 = time.time()
code, out = tool('demo-load.php', '--confirm-demo', '--mailbox=' + MAILBOX, password=PASSWORD)
seconds = time.time() - t0
check('The demonstration loads (%.0f s)' % seconds, code == 0 and 'Demonstration loaded' in out, out)
check('The password is never printed', PASSWORD not in out)
d = probe('loaded')
check('Three plants: 500, 300 and 200 people on site', d['plants'] == {'Plant 1': 500, 'Plant 2': 300, 'Plant 3': 200}, d['plants'])
check('Plant 1 has 100 chemical engineers, 200 welders, 50 painters, 10 quality inspectors, 10 mechanical engineers and 130 CNC machinists',
      all(d['trades'].get('Plant 1 ' + k) == v for k, v in {'Chemical engineer': 100, 'Welder': 200, 'Painter': 50, 'Quality inspector': 10, 'Mechanical engineer': 10, 'CNC machinist': 130}.items()), d['trades'])
check('The office: 3 owners and 3 VPs (administrators), 4 recruiters, 2 coordinators, 2 payroll, 10 managers and 65 supervisors, and 1,000 workers',
      d['roles'] == {'admin': 6, 'recruiter': 4, 'hotels': 2, 'payroll': 2, 'supervisor': 75, 'worker': 1000}, d['roles'])
check('Sectors 1 to 3 on each plant, a day and a night shift, 65 vans, 10 hotels', d['sectors'] == 9 and d['shifts'] == {'Day': 3, 'Night': 3} and d['vans'] == 65 and d['hotels'] == 10, d)
check('About 15 people per supervisor', d['max_crew'] <= 16, d['max_crew'])
check('Chemical engineers at $50 an hour, billed 40% above, $30 per diem; 40 hours then overtime, the guarantee as overtime, a day off per absence',
      float(d['pay']['pay_rate']) == 50 and float(d['pay']['bill_rate']) == 70 and float(d['pay']['per_diem_rate']) == 30
      and float(d['policy']['weekly_overtime_after']) == 40 and d['policy']['guarantee_absence_rule'] == 'reduce_day' and int(d['policy']['guarantee_overtime']) == 1, d)
check('Ten people just arrived without a room number, and a requisition is open on each line of Plant 1', d['rooms_missing'] == 10 and d['open_requisitions'] == 6, d)
code, out = tool('demo-load.php', '--confirm-demo', '--mailbox=' + MAILBOX, password=PASSWORD)
check('A second load is refused', code == 1 and 'already loaded' in out, out)

# ── people sign in and work ─────────────────────────────────────────────
for label, page, marker in (('owner1', '/home', 'href="/projects"'), ('vp2', '/approvals', 'Approvals'), ('recruiter1', '/hiring', 'Hiring'),
                            ('coordinator1', '/hotels', 'Hotels'), ('payroll1', '/payroll-export', 'Payroll review and export'), ('manager1', '/my-team', 'My projects and team')):
    c = Client()
    check('%s signs in with the shared password and opens %s' % (label, page), c.login(label) and marker in c.get(page)[1])
check('A wrong password is refused', not Client().login('owner1', password='wrong-' + PASSWORD))
newcomer = probe('person', 'employee491')
worker = Client()
check('Employee Number 491, just arrived, signs in', worker.login('employee491'))
body = worker.get('/portal')[1]
check('Their portal asks for the room number before the clock-in', 'data-clock="room"' in body and 'Today on Plant 1' in body)
worker.post('/portal', {'do': 'room_number', 'placement_id': newcomer['placement_id'], 'room_number': '512'}, '/portal')
worker.post('/portal', {'do': 'clock_in', 'placement_id': newcomer['placement_id']}, '/portal')
check('They enter their room and clock in', 'data-clock="waiting"' in worker.get('/portal')[1])
sup = Client()
sup_label = re.search(r'\+(supervisor\d+)@', newcomer['supervisor_email'])[1]
check('Their supervisor signs in and sees the clock-in to validate', sup.login(sup_label) and 'id="clock-ins"' in sup.get('/my-team')[1])
body = sup.get('/my-team')[1]
cid = re.search(r'data-clock-in="(\d+)" data-status="waiting"', body)
sup.post('/my-team', {'do': 'clock', 'clock_in_id': cid[1], 'present': '1'}, '/my-team')
check('and validates it', 'data-clock="validated"' in worker.get('/portal')[1])

# ── the flush ───────────────────────────────────────────────────────────
loaded = probe('counts')
code, out = tool('demo-flush.php')
check('The flush shows what it would remove, table by table, and removes nothing', code == 0 and 'Nothing was removed' in out and re.search(r'placements\s+1000', out) and probe('counts') == loaded, out[-1500:])
code, out = tool('demo-flush.php', '--execute')
after = probe('counts')
# Two tables are not demonstration data: the append-only audit log keeps what the demonstration did (the
# database refuses deletes there), and the sign-in counters count attempts by address, not by account.
KEPT = {'activity', 'auth_rate_limits'}
diff = {t: (before.get(t), after.get(t)) for t in set(before) | set(after) if before.get(t) != after.get(t) and t not in KEPT}
check('With --execute every table is back to its count before the load: nothing of the demonstration left, nothing else touched', code == 0 and 'Demonstration removed' in out and not diff, (diff, out[-600:]))
check('The audit log keeps what the demonstration did, and says so', after['activity'] >= loaded['activity'] and 'kept, append-only (activity)' in out, out[-600:])
check('The demonstration accounts no longer sign in', not Client().login('owner1') and not Client().login('employee491'))
code, out = tool('demo-flush.php')
check('A second flush finds nothing to remove', code == 0 and 'No demonstration is loaded' in out, out)

json.dump(results, open('work/demo-http-results.json', 'w'), indent=1)
