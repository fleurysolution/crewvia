"""P5-M27 through the screens: a plant's shifts and buses, and the markup that prices a line.
Runs after walkthrough_http.py, on its fixture (work/walkthrough.json).
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/walkthrough.json'))
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

    def login(self, email):
        self.get('/login')
        self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': 'TestPassword123!'}).encode()))

    def post(self, path, values, page=None):
        self.get(page or path)
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    return json.loads(subprocess.run([php, 'work/plant_setup_probe.php', *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


admin = Client(); admin.login('wk-admin@test.invalid')
rec = Client(); rec.login('wk-rec@test.invalid')

# ── a new agreement starts with the usual day and night shifts ─────────
admin.post('/projects', {'client': 'Plant setup client', 'title': 'P5-M27 new plant', 'site_city': 'Baton Rouge', 'site_state': 'LA'}, '/projects')
new = probe('job', 'P5-M27 new plant')
check('A new agreement starts with Day 06:00-18:00 (bus 05:00) and Night 18:00-06:00 (bus 17:00)',
      [(s['name'], s['starts_at'], s['ends_at'], s['bus_departs']) for s in new['shifts']] == [('Day', '06:00:00', '18:00:00', '05:00:00'), ('Night', '18:00:00', '06:00:00', '17:00:00')], new['shifts'])
body = admin.get('/job')[1]
check('and the agreement shows them, the night one ending the next day', 'id="shifts"' in body and '18:00–06:00 (next day)' in body and 'bus 05:00' in body)

# ── changed, added, refused when not hours ─────────────────────────────
day = new['shifts'][0]['id']
admin.post('/job', {'do': 'shift_save', 'shift_id': day, 'name': 'Day', 'starts_at': '06:30', 'ends_at': '18:30', 'bus_departs': '05:15'})
admin.post('/job', {'do': 'shift_save', 'name': 'Swing', 'starts_at': '14:00', 'ends_at': '22:00', 'bus_departs': ''})
status, body = admin.post('/job', {'do': 'shift_save', 'name': 'Broken', 'starts_at': '25:00', 'ends_at': '06:00'})
shifts = {s['name']: s for s in probe('job', 'P5-M27 new plant')['shifts']}
check('A shift\'s hours and bus change, another is added', shifts['Day']['starts_at'] == '06:30:00' and shifts['Day']['bus_departs'] == '05:15:00' and 'Swing' in shifts, shifts)
check('and a time that is not an hour is refused', 'Broken' not in shifts)

# ── an older project gets them from one button ─────────────────────────
admin.post('/select-project', {'job_id': fx['job']}, '/job')
check('A project made before shifts has none, and is offered the usual two', probe('job', 'P5-M26 plant')['shifts'] == [] and 'value="shift_defaults"' in admin.get('/job')[1])
admin.post('/job', {'do': 'shift_defaults'})
check('which one press adds', [s['name'] for s in probe('job', 'P5-M26 plant')['shifts']] == ['Day', 'Night'])

# ── a markup prices a line ─────────────────────────────────────────────
admin.post('/job', {'do': 'add_line', 'role_title': 'Walk painter', 'discipline': 'other', 'quantity': '3', 'pay_rate': '50', 'per_diem_rate': '30', 'markup': '35'})
line = probe('line', 'Walk painter')
check('A 35% markup on $50 bills $67.50 when no bill rate is typed', line and float(line['bill_rate']) == 67.5 and float(line['per_diem_rate']) == 30, line)
body = admin.get('/job')[1]
check('and the scope shows the margin it makes, 25.9%', ('data-margin="%s">25.9%% margin' % line['id']) in body)
admin.post('/job', {'do': 'add_line', 'role_title': 'Walk inspector', 'discipline': 'other', 'quantity': '1', 'pay_rate': '40', 'bill_rate': '70', 'markup': '35'})
check('A bill rate typed wins over the markup', float(probe('line', 'Walk inspector')['bill_rate']) == 70)

# ── a worker given a shift is measured against its start ───────────────
night = next(s['id'] for s in probe('job', 'P5-M26 plant')['shifts'] if s['name'] == 'Night')
rec.post('/select-project', {'job_id': fx['job']}, '/organization')
body = rec.get('/organization?assign=%d' % fx['pw'])[1]
check('The organization chart picks the shift from the plant\'s list', 'name="shift_id"' in body and 'Night · 18:00–06:00' in body)
rec.post('/operations', {'do': 'assignment', 'placement_id': fx['pw'], 'supervisor_id': fx['ids']['sup'], 'trade': 'Walk machinist', 'shift_id': night, 'back': '/organization'})
a = probe('assignment', fx['pw'])
check('and the assignment takes its name and start, which lateness and roll call read', a['shift_id'] == night and a['shift_label'] == 'Night' and a['shift_start'] == '18:00:00', a)
status, body = rec.post('/operations', {'do': 'assignment', 'placement_id': fx['pw'], 'supervisor_id': fx['ids']['sup'], 'trade': 'Walk machinist', 'shift_id': new['shifts'][1]['id']})
check('but not another plant\'s shift', status == 422 and probe('assignment', fx['pw'])['shift_id'] == night, status)

json.dump(results, open('work/ps-http-results.json', 'w'), indent=1)
