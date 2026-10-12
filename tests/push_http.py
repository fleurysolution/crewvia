"""P5-M06c through the screens and the scripts, against a stand-in push
service on this machine: off by default (nothing sent, nothing callable),
the key pair made once on the server and sealed, an administrator turning
push on (the backlog not pushed), subscriptions limited to push services'
addresses, one empty, signed push per phone for what is new, of the kinds
chosen, never a name or message; the end of a subscription (gone, failing,
password change, sign-out, removal); a test push; the scheduled workflows;
off again; translations.
"""
import http.cookiejar, http.server, json, os, re, subprocess, threading, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
STUB = 'http://127.0.0.1:8094'
fx = json.load(open('work/push.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS = fx['ids']
results = []
received = []


class Stub(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        length = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(length) if length else b''
        received.append({'path': self.path, 'headers': dict(self.headers), 'body': body})
        self.send_response(410 if 'gone' in self.path else 500 if 'fail' in self.path else 201)
        self.send_header('Content-Length', '0')
        self.end_headers()

    def log_message(self, *a):
        pass


stub = http.server.ThreadingHTTPServer(('127.0.0.1', 8094), Stub)
threading.Thread(target=stub.serve_forever, daemon=True).start()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.raw = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
        self.token = None

    def open(self, req, raw=False):
        try:
            with (self.raw if raw else self.opener).open(req) as r:
                return r.status, r.headers, r.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.headers, e.read().decode()

    def get(self, path):
        status, headers, body = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body

    def post(self, path, values, raw=False):
        data = urllib.parse.urlencode([('_csrf', self.token)] + (values if isinstance(values, list) else list(values.items())), doseq=True).encode()
        return self.open(urllib.request.Request(base + path, data), raw)

    def act(self, values, raw=False):
        self.get('/devices')
        return self.post('/devices', values, raw)


def user(key):
    c = Client()
    c.get('/login')
    status, headers, body = c.post('/login', {'email': 'pu-%s@test.invalid' % key, 'password': 'TestPassword123!'})
    assert '<title>Sign in' not in body, key
    return c


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    out = subprocess.run([php, 'work/push_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def env():
    e = dict(os.environ)
    # Windows PHP needs an openssl.cnf to make an EC key; Linux does not.
    for cnf in (r'C:\xampp\php\extras\ssl\openssl.cnf', r'C:\xampp\apache\conf\openssl.cnf'):
        if os.path.exists(cnf) and not e.get('OPENSSL_CONF'):
            e['OPENSSL_CONF'] = cnf
    return e


def script(name):
    return subprocess.run([php, 'work/test-app/install/%s' % name], capture_output=True, text=True, env=env())


def dispatch():
    before = len(received)
    out = script('push-dispatch.php')
    assert out.returncode == 0, out.stdout + out.stderr
    return out.stdout, received[before:]


def subscribe(c, endpoint, kinds, raw=False):
    return c.act([('do', 'push_subscribe'), ('endpoint', endpoint)] + [('kinds[]', k) for k in kinds], raw)


def sub(endpoint):
    return next((s for s in probe()['subs'] if s['endpoint'] == endpoint), None)


admin, rec, w, w2 = user('admin'), user('rec'), user('w'), user('w2')
ALL = ['tasks', 'training', 'surveys', 'approvals', 'recognition', 'other']

# ── off by default ─────────────────────────────────────────────────────
status, body = w.get('/devices')
check('Push is off at first: a worker is told so, and offered nothing', 'id="push" data-on="0"' in body and 'id="push-off"' in body and 'id="push-form"' not in body)
check('A subscription while off is refused', subscribe(w, STUB + '/push/w-ok', ['training'])[0] == 422 and probe()['subs'] == [])
probe('notify', IDS['w'], '/my-training')
out, got = dispatch()
check('While off, the dispatcher sends nothing and calls nothing', 'Push: off; nothing sent.' in out and got == [], out)
check('Only an administrator turns push on', rec.act({'do': 'push_switch', 'state': 'on'})[0] == 403 and not probe()['enabled'])
status, body = admin.get('/devices')
check('Without the key pair, the administrator is told to make it on the server', 'id="push-no-key"' in body)
check('and turning push on is refused', admin.act({'do': 'push_switch', 'state': 'on'})[0] == 422 and not probe()['enabled'])

# ── the key pair ───────────────────────────────────────────────────────
out = script('push-keys.php')
d = probe()
raw = __import__('base64').urlsafe_b64decode(d['public_key'] + '=' * (-len(d['public_key']) % 4))
check('install/push-keys.php makes the key pair: a P-256 public key', out.returncode == 0 and 'Push keys: made' in out.stdout and len(raw) == 65 and raw[0] == 4, out.stdout + out.stderr)
check('The private key is sealed with the encryption key and never printed', d['private_sealed'] and 'PRIVATE' not in out.stdout + out.stderr)
again = script('push-keys.php')
check('Running it again changes nothing', 'already in place' in again.stdout and probe()['public_key'] == d['public_key'])
check('Making the keys does not turn push on', not probe()['enabled'])
admin.act({'do': 'push_switch', 'state': 'on'})
d = probe()
check('An administrator turns push on; what was already waiting will not be pushed', d['enabled'] and d['cursor'] == d['max_notification'] > 0)
out, got = dispatch()
check('So the first pass sends nothing', got == [] and '0 sent' in out, out)

# ── subscribing ────────────────────────────────────────────────────────
status, body = w.get('/devices')
check('A worker is offered push for this phone, with the agency\'s public key', 'id="push-form" data-key="%s"' % d['public_key'] in body and '/assets/push.js?v=1' in body)
for label, endpoint in [('an address that is not a push service', 'https://evil.example/push/x'), ('a push service over http', 'http://fcm.googleapis.com/fcm/send/x'),
                        ('a push service on another port', 'https://fcm.googleapis.com:8443/fcm/send/x'), ('a private address', 'http://10.0.0.5/push/x'),
                        ('a look-alike host', 'https://fcm.googleapis.com.evil.example/x')]:
    check('Refused: %s' % label, subscribe(w, endpoint, ['training'])[0] == 422)
check('Refused: no kind chosen', subscribe(w, STUB + '/push/w-ok', [])[0] == 422)
check('Nothing refused was stored', probe()['subs'] == [])
status, headers, _ = subscribe(w, STUB + '/push/w-ok', ['training', 'surveys'], raw=True)
cookie = [v for k, v in headers.items() if k.lower() == 'set-cookie' and v.startswith('crewvia_push=')]
s = sub(STUB + '/push/w-ok')
check('A worker turns push on for their phone, for training and surveys', s and s['kinds'] == 'training,surveys' and int(s['user_id']) == IDS['w'])
check('This phone is marked by an HttpOnly, SameSite=Strict cookie', cookie and 'httponly' in cookie[-1].lower() and 'samesite=strict' in cookie[-1].lower(), headers)
subscribe(w, STUB + '/push/w-ok', ['training'])
check('Subscribing the same phone again updates it, never twice', len([x for x in probe()['subs'] if x['endpoint'] == STUB + '/push/w-ok']) == 1 and sub(STUB + '/push/w-ok')['kinds'] == 'training')
status, body = w.get('/devices')
check('The page says it is on for this phone', 'id="push-here"' in body)
subscribe(w2, STUB + '/push/w2-gone', ALL)
subscribe(rec, STUB + '/push/rec-fail', ALL)

# ── sending ────────────────────────────────────────────────────────────
probe('notify', IDS['w'], '/my-training?take=1')
probe('notify', IDS['w'], '/my-training?take=2')
probe('notify', IDS['w'], '/surveys?take=9')
probe('notify', IDS['w2'], '/recognition')
probe('notify', IDS['rec'], '/approvals')
out, got = dispatch()
paths = sorted(r['path'] for r in got)
check('One push per phone with something new, not one per notification', paths == ['/push/rec-fail', '/push/w-ok', '/push/w2-gone'], (paths, out))
p = next(r for r in got if r['path'] == '/push/w-ok')
check('A push is empty: no body, no name, no message', p['body'] == b'' and p['headers'].get('Content-Length') == '0'
      and not any('Push w' in str(v) or 'named person' in str(v) for v in p['headers'].values()), p['headers'])
check('It says how long it may wait (TTL)', p['headers'].get('TTL') == '86400')
v = probe('verify', p['headers'].get('Authorization'))
check('It is signed with the agency\'s key (VAPID, ES256), for that push service only, for 12 hours',
      v['ok'] and v['k_matches'] and v['header'] == {'typ': 'JWT', 'alg': 'ES256'} and v['claims']['aud'] == STUB and 0 < v['claims']['exp'] - v['now'] <= 12 * 3600 + 5, v)
d = probe()
check('A push service that says the phone is gone ends its subscription', sub(STUB + '/push/w2-gone')['end_reason'] == 'gone')
check('A failure is counted and the subscription kept', int(sub(STUB + '/push/rec-fail')['failures']) == 1 and sub(STUB + '/push/rec-fail')['ended_at'] is None)
check('Every push is logged with the answer', sorted(int(x['status']) for x in d['deliveries']) == [201, 410, 500])
out, got = dispatch()
check('Nothing new, nothing sent', got == [])
probe('notify', IDS['w'], '/surveys?take=10')
out, got = dispatch()
check('Something new of a kind the person did not choose sends nothing to that phone', got == [])
for i in range(4):
    probe('notify', IDS['rec'], '/approvals')
    dispatch()
check('After 5 failures in a row the subscription ends', sub(STUB + '/push/rec-fail')['end_reason'] == 'failing')
probe('bump', IDS['w'])
probe('notify', IDS['w'], '/my-training')
out, got = dispatch()
check('A password change ends the phone\'s push before anything is sent', got == [] and sub(STUB + '/push/w-ok')['end_reason'] == 'signed_out')

# ── a test, removal, sign-out ──────────────────────────────────────────
w = user('w')
subscribe(w, STUB + '/push/w-ok', ['training'])
before = len(received)
w.act({'do': 'push_test'})
check('A test push goes to the person\'s own phone at once', [r['path'] for r in received[before:]] == ['/push/w-ok'] and probe()['deliveries'][-1]['reason'] == 'test')
own = sub(STUB + '/push/w-ok')
check('Someone else cannot remove that phone', w2.act({'do': 'push_remove', 'subscription_id': own['id']})[0] == 404 and sub(STUB + '/push/w-ok')['ended_at'] is None)
status, body = admin.get('/devices')
check('An administrator sees every phone with push on', 'data-any-subscription="%s"' % own['id'] in body)
admin.act({'do': 'push_remove', 'subscription_id': own['id']})
check('and removes one', sub(STUB + '/push/w-ok')['end_reason'] == 'removed_by_admin')
subscribe(w, STUB + '/push/w-ok', ['training'])
w.get('/devices')
status, headers, _ = w.post('/logout', {}, raw=True)
check('Signing out on the phone ends its push and clears what the phone kept', sub(STUB + '/push/w-ok')['end_reason'] == 'signed_out' and headers.get('Clear-Site-Data') == '"storage"', headers)

# ── the scheduled workflows, and off again ─────────────────────────────
w = user('w')
subscribe(w, STUB + '/push/w-ok', ['training'])
probe('notify', IDS['w'], '/my-training')
before = len(received)
out = subprocess.run([php, 'work/test-app/install/run-workflows.php'], capture_output=True, text=True)
check('The scheduled workflows send the pushes too', out.returncode == 0 and [r['path'] for r in received[before:]] == ['/push/w-ok'], out.stdout + out.stderr)
admin.act({'do': 'push_switch', 'state': 'off'})
probe('notify', IDS['w'], '/my-training')
out, got = dispatch()
check('Turned off, nothing is sent again', not probe()['enabled'] and got == [] and 'off' in out)
check('and no phone can subscribe', subscribe(w2, STUB + '/push/w2-again', ALL)[0] == 422)

# ── translations ───────────────────────────────────────────────────────
status, body = w2.get('/devices')
check('In French', 'Notifications sur le téléphone' in body and 'Les notifications sont désactivées' in body)

stub.shutdown()
json.dump(results, open('work/pu-http-results.json', 'w'), indent=1)
