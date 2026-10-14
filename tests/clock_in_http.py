"""P5-M32 through the screens: the room number, the clock-in, and the supervisor's validation.
  - the coordinator books the hotel without a room number; the worker is told, and has 4 hours from check-in to enter it;
  - without it they cannot clock in; with it they clock in, and their supervisor is told the time to validate by;
  - the supervisor validates their own crew (not another's) by 30 minutes after the shift starts; later is kept, marked late;
  - a room number overdue shows to the worker and to the supervisor.
Runs after adp_registration_http.py, on the walkthrough fixture and clock_in_db.php's people.
"""
import datetime, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
wk = json.load(open('work/walkthrough.json'))
fx = json.load(open('work/clock_in.json'))
P = fx['people']
php = os.environ.get('PHP_BIN', 'php')
results = []


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
    return json.loads(subprocess.run([php, 'work/clock_in_probe.php', *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


def flash(body):
    return ' '.join(re.sub(r'<[^>]+>|\s+', ' ', f).strip() for f in re.findall(r'class="flash[^"]*"[^>]*>(.*?)</p>', body, re.S))


def said(body):
    """A refusal: flashed on the page it redirects to, or the plain message of a 4xx answer."""
    return flash(body) if '<html' in body.lower() else body.strip()


def dt(v):
    return datetime.datetime.strptime(v, '%Y-%m-%d %H:%M:%S')


worker = Client(); worker.login('ci-worker@test.invalid')
hotels = Client(); hotels.login('ci-hotels@test.invalid')
sup = Client(); sup.login('wk-sup@test.invalid')
sup2 = Client(); sup2.login('ci-sup2@test.invalid')
hotels.post('/select-project', {'job_id': wk['job']}, '/hotels')
me = P['clock']
today = datetime.date.today()

# ── the hotel, booked without a room number ─────────────────────────────
w0 = probe('notices', me['user'])['count']
status, body = hotels.post('/hotels', {'do': 'book', 'placement_id': me['placement'], 'hotel_id': fx['hotel'], 'room_number': '', 'check_in': today.isoformat(),
                                       'check_out': (today + datetime.timedelta(days=14)).isoformat(), 'private_room': '1'}, '/hotels')
lodging = probe('lodging', me['placement'])
check('The coordinator books the hotel without a room number: the hotel gives it at check-in', lodging and lodging['room_number'] is None and lodging['room_told_at'] and 'booked into Walk Inn' in said(body), (lodging, said(body)))
n = probe('notices', me['user'])
check('The worker is told their hotel, and to enter the room number within 4 hours', n['count'] == w0 + 1 and 'Your hotel: Walk Inn' in n['last'] and 'within 4 hours' in n['last'] and n['target'] == '/portal#clock', n)

# ── no room number, no clock-in ─────────────────────────────────────────
body = worker.get('/portal')[1]
check('The portal asks for the room number before anything else today, with its deadline',
      'id="clock" data-clock="room"' in body and 'name="room_number"' in body and 'Enter your room number at Walk Inn by' in body and 'value="clock_in"' not in body)
check('and lists it as waiting on them', 'Enter your room number at Walk Inn' in body and 'href="#clock"' in body)
status, body = worker.post('/portal', {'do': 'clock_in', 'placement_id': me['placement']}, '/portal')
check('Clocking in without a room number is refused', 'Enter your room number at Walk Inn first' in said(body) and probe('clock', me['placement'])['clock'] is None, said(body))
status, body = worker.post('/portal', {'do': 'room_number', 'placement_id': me['placement'], 'room_number': '<b>'}, '/portal')
check('A room number that is not one is refused', 'Enter the room number on your key card' in said(body), said(body))
h0 = probe('notices', fx['hotels'])['count']
status, body = worker.post('/portal', {'do': 'room_number', 'placement_id': me['placement'], 'room_number': '214'}, '/portal')
lodging = probe('lodging', me['placement'])
check('The room number saved: the stay is checked in, and the coordinator is told',
      lodging['room_number'] == '214' and lodging['status'] == 'checked_in' and probe('notices', fx['hotels'])['count'] == h0 + 1 and 'is in room 214 at Walk Inn' in probe('notices', fx['hotels'])['last'], lodging)

# ── the clock-in ────────────────────────────────────────────────────────
body = worker.get('/portal')[1]
check('Now they can clock in, and see their shift', 'data-clock="ready"' in body and 'value="clock_in"' in body and 'Day · starts' in body)
s0 = probe('notices', wk['ids']['sup'])['count']
status, body = worker.post('/portal', {'do': 'clock_in', 'placement_id': me['placement']}, '/portal')
c = probe('clock', me['placement'])['clock']
check('Clocked in: the time, the shift start, and the deadline to validate (30 minutes after the shift starts, or after a late clock-in)',
      c and c['status'] == 'waiting' and c['shift_start'].endswith(fx['shiftStart'])
      and dt(c['validate_by']) == max(dt(c['shift_start']), dt(c['clocked_in_at'])) + datetime.timedelta(minutes=30), c)
n = probe('notices', wk['ids']['sup'])
check('The supervisor is told who clocked in and the time to validate by', n['count'] == s0 + 1 and 'Aaa Clock clock clocked in at' in n['last'] and 'Validate by' in n['last'] and n['target'] == '/my-team#clock-ins', n)
status, body = worker.post('/portal', {'do': 'clock_in', 'placement_id': me['placement']}, '/portal')
check('A second clock-in for the same shift is refused', 'already clocked in' in said(body), said(body))
check('The portal shows them waiting for their supervisor', 'data-clock="waiting"' in worker.get('/portal')[1])

# ── the supervisor ──────────────────────────────────────────────────────
body = sup.get('/my-team')[1]
row_ = re.search(r'data-clock-in="%d" data-status="waiting">(.*?)</div>' % c['id'], body, re.S)
check('My team lists the clock-in to validate, with its deadline', 'id="clock-ins"' in body and row_ and 'validate by' in row_[1] and 'value="clock"' in row_[1], body[:300])
late = re.search(r'data-clock-in="%d" data-status="waiting" data-overdue="1">(.*?)</div>' % fx['lateClockIn'], body, re.S)
check('A clock-in past its deadline shows as overdue', late and 'was due by' in late[1], body[:300])
check('A crew member whose room number is overdue shows: they cannot clock in', 'data-room-overdue="%d"' % P['room']['placement'] in body and 'Aaa Clock room has not entered their room number at Walk Inn' in body)
status, body = sup2.post('/my-team', {'do': 'clock', 'clock_in_id': c['id'], 'present': '1'}, '/my-team')
check('Another supervisor cannot validate it', 'Validate only your own crew' in said(body) and probe('clock', me['placement'])['clock']['status'] == 'waiting', said(body))
w1 = probe('notices', me['user'])['count']
status, body = sup.post('/my-team', {'do': 'clock', 'clock_in_id': c['id'], 'present': '1'}, '/my-team')
st = probe('clock', me['placement'])
check('Validated in time: kept, not late, and present at roll call', st['clock']['status'] == 'validated' and st['clock']['decided_late'] == 0 and st['present'] == 1, st)
check('The worker is told', probe('notices', me['user'])['count'] == w1 + 1 and 'validated your clock-in' in probe('notices', me['user'])['last'])
check('The portal shows it validated', 'data-clock="validated"' in worker.get('/portal')[1])
status, body = sup.post('/my-team', {'do': 'clock', 'clock_in_id': c['id'], 'present': '0'}, '/my-team')
check('A decided clock-in cannot be decided again', 'already decided' in said(body), said(body))
sup.post('/my-team', {'do': 'clock', 'clock_in_id': fx['lateClockIn'], 'present': '1'}, '/my-team')
st = probe('clock', P['late']['placement'])
check('A validation after the deadline is kept, and marked late', st['clock']['status'] == 'validated' and st['clock']['decided_late'] == 1, st)
check('and My team says so', 'validated late' in sup.get('/my-team')[1])

json.dump(results, open('work/ci-http-results.json', 'w'), indent=1)
