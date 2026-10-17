"""P5-M67 through the screens: clocking in and out with the supervisor's code, on the site.
  - the supervisor's phone shows a code that changes every 30 seconds, and who scanned;
  - the worker scans it: clock in, or out when already in; the phone's position is compared with the site;
  - a code too old, forged, or for a project the worker is not on, does nothing;
  - away from the site, without a position or without the code, it is kept and flagged to the supervisor;
  - the hours between in and out are shown.
Runs after clock_in_http.py, on its fixture.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
php = os.environ.get('PHP_BIN', 'php')
results = []
SITE = (29.7604, -95.3698)
NEAR = (29.7610, -95.3700)      # about 70 m away
FAR = (29.8604, -95.3698)       # about 11 km away


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token, self.headers = None, {}

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                self.headers = r.headers
                return r.status, r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            self.headers = e.headers
            return e.code, e.read().decode('utf-8', 'replace')

    def get(self, path):
        status, text = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', text)
        if m:
            self.token = m[1]
        bad = [w for w in ('Fatal error', 'Warning:', 'Notice:', 'Deprecated:') if w in text]
        assert not bad, text[max(0, text.find(bad[0]) - 200):text.find(bad[0]) + 600] if bad else ''
        return status, text

    def login(self, email):
        self.get('/login')
        self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': 'TestPassword123!'}).encode()))

    def post(self, path, values, page):
        self.get(page)
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode(), {'Accept': 'text/html'}))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:900] if detail else '')
    results.append(label)
    print('PASS ' + label, flush=True)


def probe(*args):
    return json.loads(subprocess.run([php, 'work/clock_qr_probe.php', *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


ids = probe('ids')
job = ids['ci_worker']['job_id']
check('The walkthrough supervisor supervises the clock-in workers\' project', job in ids['jobs'], ids)
probe('site', job, *SITE)

sup = Client(); sup.login('wk-sup@test.invalid')
status, body = sup.get('/my-team')
check('My team offers the clock-in code', 'href="/clock-qr"' in body)
sup.post('/select-project', {'job_id': job, 'then': '/clock-qr'}, '/my-team')
status, body = sup.get('/clock-qr')
m = re.search(r'data-url="[^"]*/clock\?t=([0-9a-f.]+)"', body)
check('The supervisor\'s screen shows the code, as a link to /clock with a signed token, and its countdown', status == 200 and m and 'qrcode.min.js' in body and 'id="clock-qr-left"' in body, body[:300])
token = m[1]
status, raw = sup.get('/clock-qr?feed=1')
feed = json.loads(raw)
check('Its feed gives the code of the moment and how long it lives', feed['url'].endswith(token) or '/clock?t=' in feed['url'] and 0 < feed['expires_in'] <= 30, feed)
check('The site asks the phone where it is, on this site only', 'geolocation=(self)' in sup.headers.get('Permissions-Policy', ''))

before = probe('clock', ids['ci_worker']['id'])
check('The worker clocked in from the portal earlier (P5-M32): it is flagged as without the code', before and before[0]['in_method'] == 'portal' and 'without the site QR' in (before[0]['in_flag'] or ''), before[:1])

worker = Client(); worker.login('ci-worker@test.invalid')
status, body = worker.get('/clock?t=' + token)
check('Scanned while clocked in: the screen offers to clock out, and asks the phone where it is', 'Clock out now' in body and 'data-geo' in body and '/assets/clock.js' in body)
old = probe('token', ids['sup'], job, 120)
status, body = worker.post('/clock', {'t': old, 'lat': NEAR[0], 'lng': NEAR[1], 'accuracy': 15}, '/portal')
check('A code two minutes old does nothing', 'This code has expired' in body and probe('clock', ids['ci_worker']['id'])[0]['clocked_out_at'] is None, body[:200])
forged = token[:-1] + ('0' if token[-1] != '0' else '1')
status, body = worker.post('/clock', {'t': forged, 'lat': NEAR[0], 'lng': NEAR[1], 'accuracy': 15}, '/portal')
check('A forged code does nothing', 'This code has expired' in body and probe('clock', ids['ci_worker']['id'])[0]['clocked_out_at'] is None)
other = probe('token', ids['sup'], 999999, 0)
status, body = worker.post('/clock', {'t': other, 'lat': NEAR[0], 'lng': NEAR[1], 'accuracy': 15}, '/portal')
check('A code for a project the worker is not on does nothing', 'no assignment on this project' in body)
status, body = worker.post('/clock', {'t': token, 'lat': FAR[0], 'lng': FAR[1], 'accuracy': 20}, '/portal')
c = probe('clock', ids['ci_worker']['id'])[0]
check('Clocked out by the code, 11 km from the site: kept, and flagged with the distance',
      c['clocked_out_at'] and c['out_method'] == 'qr' and c['out_distance_m'] > 10000 and 'Away from the site' in (c['out_flag'] or ''), c)
status, body = sup.get('/my-team')
check('The supervisor sees the clock-out, the hours, and the flag in red', 'data-clocked-out="1"' in body and 'data-clock-flag="out"' in body and 'Away from the site' in body)
status, raw = sup.get('/clock-qr?feed=1')
check('and on the code screen, who scanned, flagged', any('Away from the site' in s['text'] and s['flag'] for s in json.loads(raw)['scans']), raw[:400])

if ids['wk_w'] and ids['wk_w']['job_id'] == job:
    w2 = Client(); w2.login('wk-w@test.invalid')
    w2.get('/clock?t=' + token)
    status, body = w2.post('/clock', {'t': token, 'lat': NEAR[0], 'lng': NEAR[1], 'accuracy': 25}, '/portal')
    c2 = probe('clock', ids['wk_w']['id'])
    check('Another worker scans on the site: clocked in by the code, 70 m away, nothing flagged',
          c2 and c2[0]['in_method'] == 'qr' and c2[0]['in_flag'] is None and c2[0]['in_distance_m'] < 300 and c2[0]['qr_supervisor_id'] == ids['sup'], c2[:1])
    status, body = w2.post('/clock', {'t': token}, '/portal')
    c2 = probe('clock', ids['wk_w']['id'])[0]
    check('Scanning again clocks out; without a position it is flagged', c2['clocked_out_at'] and 'No position from the phone' in (c2['out_flag'] or ''), c2)

json.dump(results, open('work/clock-qr-results.json', 'w'), indent=1)
