"""P5-M06b through the endpoints: trusting a phone (the cookie, its hash,
14 days, who may), what the phone may keep (only its owner's live
assignments; a supervisor's crew for a day; words in the owner's language),
the cross-site defences of /sync, every rule for hours and gate marks typed
offline (saved, replaced, approved, rejected since, frozen, ended, another
person's, future, out of range, out of date), an entry sent twice written
once, the log of when it was typed and when it arrived, and the end of a
grant (removed by its owner or an administrator, signing out, a password
change, expiry).
"""
import datetime, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request, uuid

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/offline_sync.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS, PLACES = fx['ids'], fx['places']
TODAY = datetime.date.today()
day = lambda n: (TODAY + datetime.timedelta(days=n)).isoformat()
now = lambda minutes=0: (datetime.datetime.now() + datetime.timedelta(minutes=minutes)).strftime('%Y-%m-%d %H:%M:%S')
results = []


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
        data = urllib.parse.urlencode({'_csrf': self.token, **values}).encode()
        return self.open(urllib.request.Request(base + path, data), raw)

    def sync(self, path, body=None, headers=None):
        h = {'X-Crewvia-Sync': '1'}
        if body is not None:
            h['Content-Type'] = 'application/json'
        h.update(headers or {})
        data = json.dumps(body).encode() if body is not None else None
        status, _, text = self.open(urllib.request.Request(base + path, data, h))
        try:
            return status, json.loads(text)
        except ValueError:
            return status, text

    def device(self):
        return next((c for c in self.jar if c.name == 'crewvia_device'), None)

    def push(self, *entries):
        status, out = self.sync('/sync/push', {'entries': list(entries)})
        assert status == 200, (status, out)
        return out['results']


def user(key):
    c = Client()
    c.get('/login')
    status, headers, body = c.post('/login', {'email': 'os-%s@test.invalid' % key, 'password': 'TestPassword123!'})
    assert '<title>Sign in' not in body, key
    return c


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    out = subprocess.run([php, 'work/offline_sync_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def trust(c):
    c.get('/devices')
    return c.post('/devices', {'do': 'trust'}, raw=True)


def hours(pid, date, h, captured=None, eid=None):
    return {'id': eid or str(uuid.uuid4()), 'kind': 'hours', 'placement_id': pid, 'work_date': date, 'hours': h, 'captured_at': captured or now()}


def gate(pid, date, present, captured=None, eid=None):
    return {'id': eid or str(uuid.uuid4()), 'kind': 'gate', 'placement_id': pid, 'work_date': date, 'present': present, 'captured_at': captured or now()}


def record(pid, date):
    return next((a for a in probe()['attendance'] if int(a['placement_id']) == pid and a['work_date'] == date), None)


w, w2, sup, sup2, rec, admin = user('w'), user('w2'), user('sup'), user('sup2'), user('rec'), user('admin')
PW, PW2 = PLACES['w'], PLACES['w2']

# ── trusting a phone ───────────────────────────────────────────────────
status, body = w.get('/devices')
check('A worker sees the trust button, with the warning that it is for their own phone', 'id="trust-form"' in body and 'Only on your own phone' in body)
check('Before trusting, /sync refuses the phone', w.sync('/sync/bootstrap')[0] == 401)
status, headers, _ = trust(w)
cookie_header = [v for k, v in headers.items() if k.lower() == 'set-cookie' and v.startswith('crewvia_device=') and 'expires=Thu, 01 Jan 1970' not in v and 'Max-Age=0' not in v]
g = probe()['grants'][-1]
check('Trusting sets the device cookie: HttpOnly, SameSite=Strict, for the whole site', status == 302 and cookie_header
      and 'httponly' in cookie_header[-1].lower() and 'samesite=strict' in cookie_header[-1].lower() and 'path=/' in cookie_header[-1].lower(), headers)
token = w.device().value
check('Only the hash of the token is stored', re.fullmatch(r'[a-f0-9]{64}', token) and g['token_hash'] != token and token not in json.dumps(probe()))
check('It is trusted for 14 days', int(g['user_id']) == IDS['w'] and abs(int(g['minutes']) - 14 * 24 * 60) <= 61, g)  # 61: a daylight-saving change inside the 14 days
status, body = w.get('/devices')
check('The page says this phone is trusted and lists it', 'id="this-phone" data-trusted="1"' in body and 'data-grant="%s" data-state="active"' % g['id'] in body)
check('A recruiter cannot trust a phone: offline attendance is for workers and supervisors', trust(rec)[0] == 403 and rec.device() is None)
trust(sup)
trust(sup2)
trust(w2)

# ── what the phone keeps ───────────────────────────────────────────────
status, b = w.sync('/sync/bootstrap')
check('A worker\'s phone gets only their own live assignments, and no crew', status == 200 and [int(a['placement_id']) for a in b['assignments']] == [PW] and 'crew' not in b, b)
check('The screen\'s words come with it', b['labels']['title'] == 'Attendance without signal')
status, b2 = w2.sync('/sync/bootstrap')
check('in the owner\'s language: French for a French worker', b2['labels']['title'] == 'Présence sans réseau' and [int(a['placement_id']) for a in b2['assignments']] == [PW2], b2)
status, bs = sup.sync('/sync/bootstrap')
crew = {int(c['placement_id']) for c in bs['crew']}
check('A supervisor\'s phone gets their crew, names included, for 24 hours', crew == {PW, PW2} and 'assignments' not in bs
      and abs((datetime.datetime.strptime(bs['crew_until'], '%Y-%m-%d %H:%M:%S') - datetime.datetime.now()).total_seconds() - 86400) < 120, bs)
check('A supervisor with no crew gets none', sup2.sync('/sync/bootstrap')[1]['crew'] == [])

# ── the cross-site defences of /sync ───────────────────────────────────
status, _, _ = w.open(urllib.request.Request(base + '/sync/bootstrap'))
check('Without its header, /sync refuses', status == 403)
check('A push that is not JSON is refused', w.open(urllib.request.Request(base + '/sync/push', b'entries=1', {'X-Crewvia-Sync': '1', 'Content-Type': 'application/x-www-form-urlencoded'}))[0] == 415)
check('A call from another site is refused', w.sync('/sync/bootstrap', headers={'Origin': 'https://evil.example'})[0] == 403)
check('A push of more than 200 entries is refused', w.sync('/sync/push', {'entries': [{}] * 201})[0] == 413)
check('A page signed in does not open /sync: the session is not the way in', rec.sync('/sync/bootstrap')[0] == 401)
stranger = Client()
stranger.jar.set_cookie(w.device())
check('The device cookie opens no page: /attendance still needs a sign-in', '<title>Sign in' in stranger.get('/attendance')[1])

# ── hours ─────────────────────────────────────────────────────────────
d1 = day(-1)
first = hours(PW, d1, 8, now(-60))
r = w.push(first)[0]
a = record(PW, d1)
check('Hours typed offline are saved for approval, as if typed on the screen', r['outcome'] == 'saved' and a and float(a['hours']) == 8.0 and a['status'] == 'submitted'
      and a['source'] == 'worker' and int(a['submitted_by']) == IDS['w'], (r, a))
r = w.push(first)[0]
check('The same entry sent again gets the same answer and is written once', r['outcome'] == 'saved' and r['repeat'] is True
      and len([x for x in probe()['log'] if x['entry_id'] == first['id']]) == 1 and len([x for x in probe()['attendance'] if int(x['placement_id']) == PW and x['work_date'] == d1]) == 1)
log = next(x for x in probe()['log'] if x['entry_id'] == first['id'])
check('The log keeps when it was typed and when it arrived', log['captured_at'] < log['received_at'] and int(log['device_id']) == int(g['id']) and int(log['attendance_id']) == int(a['id']), log)
r = w.push(hours(PW, d1, 9.5))[0]
check('A day still waiting is replaced, as on the screen', r['outcome'] == 'replaced' and float(record(PW, d1)['hours']) == 9.5)
probe('review', PW, d1, 'approved', now())
r = w.push(hours(PW, d1, 12))[0]
check('An approved day is refused: ask for a correction', r['outcome'] == 'refused' and r['reason'] == 'approved' and float(record(PW, d1)['hours']) == 9.5 and 'correction' in r['message'])
d2 = day(-2)
w.push(hours(PW, d2, 8, now(-300)))
typed_before = now(-120)
probe('review', PW, d2, 'rejected', now(-60))
r = w.push(hours(PW, d2, 7, typed_before))[0]
check('A day rejected after the phone entry was typed is refused as out of date', r['outcome'] == 'refused' and r['reason'] == 'stale' and record(PW, d2)['status'] == 'rejected')
r = w.push(hours(PW, d2, 7, now()))[0]
check('Typed after the rejection, it is sent again for approval', r['outcome'] == 'replaced' and record(PW, d2)['status'] == 'submitted' and float(record(PW, d2)['hours']) == 7.0)
r = w.push(hours(PW, fx['frozen_day'], 8))[0]
check('A day in a week frozen for pay is refused', r['outcome'] == 'refused' and r['reason'] == 'frozen' and record(PW, fx['frozen_day']) is None)
refusals = [('an ended assignment', hours(PLACES['w_old'], d1, 8), 'assignment'), ('another worker\'s assignment', hours(PW2, d1, 8), 'assignment'),
            ('a day in the future', hours(PW, day(1), 8), 'date'), ('25 hours', hours(PW, day(-3), 25), 'hours'),
            ('a day before the assignment', hours(PW, day(-60), 8), 'date'), ('a gate mark from a worker', gate(PW2, day(0), 1), 'not_supervisor'),
            ('an entry with no usable id', {**hours(PW, day(-3), 8), 'id': 'x'}, 'id'), ('an unknown kind', {**hours(PW, day(-3), 8), 'kind': 'leave'}, 'kind')]
for label, entry, reason in refusals:
    r = w.push(entry)[0]
    check('Refused: %s' % label, r['outcome'] == 'refused' and r['reason'] == reason, r)
check('Nothing refused was written', record(PLACES['w_old'], d1) is None and record(PW2, d1) is None and record(PW, day(1)) is None and record(PW, day(-3)) is None)
ahead = hours(PW, day(-4), 6, now(600))
w.push(ahead)
check('A clock ahead of the server is held to the time it arrived', next(x for x in probe()['log'] if x['entry_id'] == ahead['id'])['captured_at'] <= now(1))
r = w2.push(hours(PW2, d1, 8))[0]
check('A refusal speaks the owner\'s language', w2.push(hours(PW2, day(1), 8))[0]['message'] == 'Indiquez un jour travaillé, aujourd\'hui ou avant.' and r['outcome'] == 'saved')

# ── gate marks ─────────────────────────────────────────────────────────
r = sup.push(gate(PW2, day(0), 1, now(-30)))[0]
c = next(x for x in probe()['checkins'] if int(x['placement_id']) == PW2 and x['work_date'] == day(0))
check('A supervisor marks one of their crew present offline; the mark keeps when it was made', r['outcome'] == 'saved' and int(c['present']) == 1
      and int(c['marked_by']) == IDS['sup'] and abs((datetime.datetime.strptime(c['marked_at'], '%Y-%m-%d %H:%M:%S') - datetime.datetime.now()).total_seconds() + 1800) < 120, (r, c))
check('and the person is on site, as the gate screen does', next(p for p in probe()['placements'] if int(p['id']) == PW2)['status'] == 'on_site')
check('A supervisor\'s mark for someone outside their crew is refused', sup2.push(gate(PW, day(0), 1))[0]['reason'] == 'assignment')
check('and one 8 days back', sup.push(gate(PW, day(-8), 1))[0]['reason'] == 'date')
check('and hours sent by a supervisor', sup.push(hours(PW, day(-1), 8))[0]['reason'] == 'not_worker')
probe('checkin', PW, day(0), now(-5))
r = sup.push(gate(PW, day(0), 0, now(-20)))[0]
check('A mark made on the screens after the phone\'s stands; the phone\'s is refused as out of date', r['outcome'] == 'refused' and r['reason'] == 'stale'
      and int(next(x for x in probe()['checkins'] if int(x['placement_id']) == PW and x['work_date'] == day(0))['present']) == 1)
r = sup.push(gate(PW, day(0), 0, now()))[0]
check('A later mark from the phone replaces it', r['outcome'] == 'replaced' and int(next(x for x in probe()['checkins'] if int(x['placement_id']) == PW and x['work_date'] == day(0))['present']) == 0)
check('What was saved from a phone is in the activity log, by its owner', {int(a['user_id']) for a in probe()['activity']} >= {IDS['w'], IDS['sup']})

# ── the end of a grant ─────────────────────────────────────────────────
status, body = w.get('/devices')
w.post('/devices', {'do': 'revoke', 'grant_id': g['id']})
check('A worker removes their phone: it is refused at once', w.sync('/sync/bootstrap')[0] == 401 and probe()['grants'][0]['revoke_reason'] == 'removed')
trust(w)
check('Trusted again, it works again', w.sync('/sync/bootstrap')[0] == 200)
gw = probe()['grants'][-1]
other = next(x for x in probe()['grants'] if int(x['user_id']) == IDS['w2'] and x['revoked_at'] is None)
w.get('/devices')
check('A worker cannot remove someone else\'s phone', w.post('/devices', {'do': 'revoke', 'grant_id': other['id']})[0] == 404 and w2.sync('/sync/bootstrap')[0] == 200)
status, body = admin.get('/devices')
check('An administrator sees every trusted phone', 'data-any-grant="%s"' % other['id'] in body and 'data-any-grant="%s"' % gw['id'] in body)
admin.post('/devices', {'do': 'revoke', 'grant_id': other['id']})
check('and removes one', w2.sync('/sync/bootstrap')[0] == 401 and next(x for x in probe()['grants'] if x['id'] == other['id'])['revoke_reason'] == 'removed_by_admin')
probe('bump', IDS['sup'])
check('A password change (the sign-in version moving) ends the supervisor\'s grant', sup.sync('/sync/bootstrap')[0] == 401)
probe('expire', next(x for x in probe()['grants'] if int(x['user_id']) == IDS['sup2'] and x['revoked_at'] is None)['id'])
check('An expired grant is refused', sup2.sync('/sync/bootstrap')[0] == 401)
w.get('/devices')
status, headers, _ = w.post('/logout', {}, raw=True)
check('Signing out on a trusted phone ends its grant and clears what the phone kept', w.sync('/sync/bootstrap')[0] == 401
      and next(x for x in probe()['grants'] if x['id'] == gw['id'])['revoke_reason'] == 'signed_out' and headers.get('Clear-Site-Data') == '"storage"', headers)
plain = user('rec')
plain.get('/attendance')
status, headers, _ = plain.post('/logout', {}, raw=True)
check('Signing out elsewhere clears nothing on the device', headers.get('Clear-Site-Data') is None)

# ── the screens ────────────────────────────────────────────────────────
w = user('w')
status, body = w.get('/attendance')
check('Attendance links a worker to trusting their phone', 'id="offline-attendance"' in body)
status, _, page = Client().open(base + '/offline-entry.html')
check('The offline screen is a static page with no data in it', status == 200 and 'id="offline-entry"' in page and '/assets/offline-sync.js?v=2' in page and 'Offline w' not in page)
status, body = user('w2').get('/devices')
check('Trusted phones in French', 'Téléphones de confiance' in body)

json.dump(results, open('work/os-http-results.json', 'w'), indent=1)
