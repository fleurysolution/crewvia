"""The workforce overview on Activity: who sees it, whether its numbers are
the database's, and whether every tile opens for the desk that sees it.

The figures are compared with workforce_overview_probe.php, which counts
with its own SQL rather than the module under test.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fixture = json.load(open('work/fixture.json'))
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


def tiles(body):
    return [int(n) for n in re.findall(r'<div class="wf-n">(\d+)</div>', body)]


def links(body):
    return re.findall(r'<a class="wf-tile [a-z-]+" href="([^"]+)"', body)


expected = json.loads(subprocess.run([php, 'work/workforce_overview_probe.php', str(fixture['a'])],
                                     check=True, capture_output=True, text=True).stdout)

for email, desk in [('admin@test.invalid', 'admin'), ('m01-recruiter@test.invalid', 'recruiter'),
                    ('m01-payroll@test.invalid', 'payroll'), ('m01-hotels@test.invalid', 'hotels')]:
    c = Client()
    check('Overview: %s signs in' % desk, c.login(email)[0] == 200)
    c.post('/select-project', {'job_id': fixture['a']})
    status, body = c.get('/activity')
    check('Overview: %s sees the four tiles' % desk, status == 200 and len(tiles(body)) == 4, tiles(body))
    check('Overview: %s sees the database\'s figures' % desk,
          tiles(body) == [expected['on_assignment'], expected['present'], expected['absent'], expected['on_leave']],
          (tiles(body), expected))
    check('Overview: %s sees both charts' % desk, body.count('<svg class="wf-chart"') == 2)
    for href in links(body):
        code = c.get(href)[0]
        check('Overview: %s can open the tile link %s' % (desk, href), code == 200, code)

for email, who in [('worker@test.invalid', 'a worker'), ('supervisor@test.invalid', 'a supervisor'), ('client@test.invalid', 'a client')]:
    c = Client()
    check('Overview: %s signs in' % who, c.login(email)[0] == 200)
    status, body = c.get('/activity')
    check('Overview: %s does not see the workforce figures' % who, 'wf-tiles' not in body and 'Present today' not in body)

c = Client(); c.login('admin@test.invalid'); c.post('/select-project', {'job_id': fixture['a']})
status, body = c.get('/activity?lang=fr')
check('Overview: translated into French', 'Présents aujourd’hui' in body and 'Affectés récemment' in body)
status, body = c.get('/activity?lang=es')
check('Overview: translated into Spanish', 'Presentes hoy' in body)
c.get('/activity?lang=en')

json.dump(results, open('work/overview-http-results.json', 'w'), indent=1)
