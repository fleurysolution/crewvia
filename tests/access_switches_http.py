"""P5-M11 through the screens: the sign-in lock counts failed attempts only
(a success clears its email's count and gives back its address's), locks an
email after ten failures in 15 minutes with the same words whether or not
an account exists, opens again by itself after 15 minutes or at once when
an administrator unlocks it on People; the client portal switch (off: a
client cannot sign in, a signed-in client is put out, no client account
is created, granted or made from People; on: they sign in); a worker's
hours in a week frozen for pay refused on the Attendance screen as /sync
refuses them; who may do what; translations.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/access_switches.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS = fx['ids']
results = []
LOCKED = 'Too many failed attempts. Try again in 15 minutes'


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None
        self.url = None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                self.url = r.geturl()
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

    def post(self, path, values):
        self.get(path)
        data = urllib.parse.urlencode({'_csrf': self.token, **values}).encode()
        return self.open(urllib.request.Request(base + path, data))

    def login(self, email, password='TestPassword123!'):
        self.get('/login')
        data = urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': password}).encode()
        status, body = self.open(urllib.request.Request(base + '/login', data))
        return '<title>Sign in' not in body, body


def user(key):
    c = Client()
    ok, body = c.login('ax-%s@test.invalid' % key)
    assert ok, key
    return c


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    out = subprocess.run([php, 'work/access_switches_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


REC = 'ax-rec@test.invalid'
admin = user('admin')

# ── the sign-in lock ───────────────────────────────────────────────────
for _ in range(9):
    Client().login(REC, 'Wrong password 1')
check('Nine failures are counted', probe()['counts'][REC] == 9)
ok, _ = Client().login(REC)
check('The right password still opens, and a success clears the count', ok and probe()['counts'][REC] == 0)
before = probe()['counts']['ip']
for _ in range(4):
    assert user('admin')
check('Successful sign-ins do not count against the address', probe()['counts']['ip'] == before, (before, probe()['counts']['ip']))
for _ in range(10):
    ok, body = Client().login(REC, 'Wrong password 2')
check('Ten failures are each told the email and password do not match', not ok and 'That email and password do not match an account.' in body)
ok, body = Client().login(REC)
check('After ten failures even the right password is refused, saying why and what to do', not ok and LOCKED in body and 'ask an administrator to unlock' in body)
for _ in range(11):
    ok, other = Client().login('nobody-ax@test.invalid', 'Wrong')
check('An email with no account is locked with the same words', LOCKED in other)
status, body = admin.get('/people')
check('People shows the locked sign-in and an Unlock button', 'data-locked="%d"' % IDS['rec'] in body and 'value="unlock"' in body)
rec_client = Client()
check('Only an administrator unlocks', rec_client.login('ax-pay@test.invalid')[0] and rec_client.post('/people', {'do': 'unlock', 'user_id': IDS['rec']})[0] == 403 and probe()['counts'][REC] >= 10)
admin.post('/people', {'do': 'unlock', 'user_id': IDS['rec']})
check('Unlocking clears it at once, and is kept in the activity log', probe()['counts'][REC] == 0 and any(a['action'] == 'unlocked a sign-in' and int(a['entity_id']) == IDS['rec'] for a in probe()['activity']))
check('and the person signs in', Client().login(REC)[0])
check('The People page no longer shows the lock', 'data-locked="%d"' % IDS['rec'] not in admin.get('/people')[1])
for _ in range(10):
    Client().login(REC, 'Wrong password 3')
check('Locked again', not Client().login(REC)[0])
probe('age', REC)
check('Fifteen minutes later it opens by itself', Client().login(REC)[0])

# ── the client portal switch ───────────────────────────────────────────
CLIENT = 'ax-client@test.invalid'
check('The switch starts off here', probe()['switch'] == '0')
ok, body = Client().login(CLIENT)
check('With the portal off, a client cannot sign in, even with the right password', not ok and 'Client access is switched off' in body)
check('and the refusal is in the activity log', any(a['action'] == 'client sign-in refused' and int(a['entity_id']) == IDS['client'] for a in probe()['activity']))
check('A refused client sign-in does not count as a failure', probe()['counts'][CLIENT] == 0)
status, body = admin.get('/client-access')
check('Client access shows the switch, off', 'id="client-portal-switch" data-on="0"' in body)
users_before = len(probe()['client_users'])
check('No client account is created while it is off', admin.post('/client-access', {'do': 'create', 'client_id': fx['client'], 'name': 'New contact', 'email': 'ax-new@test.invalid'})[0] == 422 and len(probe()['client_users']) == users_before)
check('nor is a client granted to one', admin.post('/client-access', {'do': 'grant', 'client_id': fx['client'], 'user_id': IDS['client']})[0] == 422)
check('nor is anyone given the client desk on People', admin.post('/people', {'do': 'role', 'user_id': IDS['pay'], 'role': 'client'})[0] == 422 and all(int(u['id']) != IDS['pay'] for u in probe()['client_users']))
check('Only an administrator switches it', rec_client.post('/client-access', {'do': 'portal', 'enabled': '1'})[0] == 403 and probe()['switch'] == '0')
admin.post('/client-access', {'do': 'portal', 'enabled': '1'})
check('Switched on, it is recorded', probe()['switch'] == '1' and any(a['action'] == 'switched the client portal on' for a in probe()['activity']))
client = Client()
ok, body = client.login(CLIENT)
check('and the client signs in to their portal', ok and client.url.endswith('/client-portal'), client.url)
admin.post('/client-access', {'do': 'portal', 'enabled': '0'})
status, body = client.get('/client-portal')
check('Switched off again, a client already signed in is put out at their next page', '<title>Sign in' in body and probe()['switch'] == '0')

# ── a frozen week on the Attendance screen ─────────────────────────────
worker = user('w')
status, body = worker.post('/attendance', {'do': 'submit', 'placement_id': fx['placement'], 'work_date': fx['frozen_day'], 'hours': '10'})
check('A worker\'s hours in a week frozen for pay are refused, saying to ask the office', status == 422 and 'That week is already approved for pay. Ask the office for a correction.' in body)
check('and nothing is written', not [a for a in probe()['attendance'] if a['work_date'] == fx['frozen_day']])
worker.post('/attendance', {'do': 'submit', 'placement_id': fx['placement'], 'work_date': fx['open_day'], 'hours': '9.5'})
check('A day in an open week is still taken', any(a['work_date'] == fx['open_day'] and float(a['hours']) == 9.5 and a['status'] == 'submitted' for a in probe()['attendance']))

# ── translations ───────────────────────────────────────────────────────
admin.post('/language', {'locale': 'fr', 'back': '/client-access'})
check('Client access in French', 'Portail client' in admin.get('/client-access')[1])
admin.post('/language', {'locale': 'en', 'back': '/client-access'})

# The main fixture's client signs in in the suites that follow: the portal is left on.
admin.post('/client-access', {'do': 'portal', 'enabled': '1'})
check('Left switched on for the suites after this one', probe()['switch'] == '1')

json.dump(results, open('work/ax-http-results.json', 'w'), indent=1)
