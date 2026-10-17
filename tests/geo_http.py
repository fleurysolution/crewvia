"""P5-M12 (T8) through the screens: on Candidates, the state filter (codes
and names typed either way), the city filter, and "within N miles" of a
project's site or of a ZIP code, nearest first with the distance shown; a
project whose site cannot be placed says so. In Hiring, applicants and
talent pool matches within N miles of the requisition's site, "nearest the
site" as a sort, the distance on each row, and a site with no location
saying why there is no distance. ZIP codes on a candidate and on a new
project; a candidate whose address changes is placed again. Translations.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/geo.json'))
php = os.environ.get('PHP_BIN', 'php')
C, APPS = fx['cands'], fx['apps']
MINE = set(C.values())
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

    def post(self, path, values):
        self.get(path)
        data = urllib.parse.urlencode({'_csrf': self.token, **values}).encode()
        return self.open(urllib.request.Request(base + path, data))


def user(key):
    c = Client()
    c.get('/login')
    data = urllib.parse.urlencode({'_csrf': c.token, 'email': 'geo-%s@test.invalid' % key, 'password': 'TestPassword123!'}).encode()
    status, body = c.open(urllib.request.Request(base + '/login', data))
    assert '<title>Sign in' not in body, key
    return c


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe():
    return json.loads(subprocess.run([php, 'work/geo_probe.php'], check=True, capture_output=True, text=True).stdout)


def people(body):
    """The fixture's people on a Candidates page, in the order shown."""
    seen = []
    for x in re.findall(r'href="/candidates/(\d+)"', body):
        if int(x) in MINE and int(x) not in seen:
            seen.append(int(x))
    return seen


def apps(body):
    return [int(x) for x in re.findall(r'data-app="(\d+)"', body)]


def miles(body, i):
    m = re.search(r'data-miles="%d">≈ (\d+) mi' % i, body)
    return int(m[1]) if m else None


rec, admin = user('rec'), user('admin')

# ── Candidates ─────────────────────────────────────────────────────────
status, body = rec.get('/candidates?in_state=TX')
check('The state filter finds Texas whether typed TX, tx or Texas', set(people(body)) == {C['houston'], C['austin'], C['dallas'], C['zip'], C['unknown']}, people(body))
check('and a state name works in the address bar too', set(people(rec.get('/candidates?in_state=Missouri')[1])) == {C['stlouis']})
cand = {int(c['id']): c for c in probe()['candidates']}
check('Opening the list placed the new people: Austin from "Texas", the ZIP code before the city, the unknown city left unplaced',
      cand[C['austin']]['geo_state'] == 'TX' and cand[C['austin']]['geo_from'] == 'city' and cand[C['zip']]['geo_from'] == 'zip' and cand[C['unknown']]['geo_lat'] is None and cand[C['stlouis']]['geo_from'] == 'city')
check('The city filter', set(people(rec.get('/candidates?city=Houston')[1])) == {C['houston']})
status, body = rec.get('/candidates?miles=50&near=%d' % fx['katy'])
check('Within 50 miles of the Katy site: the Katy ZIP code and Houston, nearest first', people(body) == [C['zip'], C['houston']], people(body))
check('with the distance shown', miles(body, C['zip']) is not None and miles(body, C['zip']) < 5 and 20 <= miles(body, C['houston']) <= 35, (miles(body, C['zip']), miles(body, C['houston'])))
status, body = rec.get('/candidates?miles=250&near_zip=77494')
check('Within 250 miles of a ZIP code reaches Austin and Dallas too, but not St. Louis', people(body)[:2] == [C['zip'], C['houston']] and {C['austin'], C['dallas']} <= set(people(body)) and C['stlouis'] not in people(body), people(body))
check('Without a radius, the distance still sorts and shows', people(rec.get('/candidates?near=%d&in_state=TX' % fx['katy'])[1])[:2] == [C['zip'], C['houston']])
status, body = rec.get('/candidates?miles=50&near=%d' % fx['nowhere'])
check('A project whose site cannot be placed says so', 'id="location-missing"' in body)
check('The location filters are on the page', 'id="location-filter"' in rec.get('/candidates')[1])

# ── Hiring ─────────────────────────────────────────────────────────────
H = '/hiring?v=%d' % fx['v_katy']
status, body = rec.get(H)
check('Hiring offers a distance from the requisition\'s site', 'id="miles-filter"' in body and 'value="near"' in body)
check('Applicants not approved yet are not in Hiring (P5-M93)', not apps(rec.get(H + '&stage=all')[1]))
tab = re.search(r'href="([^"]*)"[^>]*data-stage-tab="accepted"', rec.get(H + '&miles=50')[1])
check('The stage tabs keep the distance', tab is not None and 'miles=50' in tab[1])
body = rec.get(H + '&tab=matches&miles=250')[1]
found = [int(x) for x in re.findall(r'data-match="(\d+)"', body)]
check('Talent pool matches within 250 miles: Austin, not St. Louis', C['austin'] in found and C['stlouis'] not in found and miles(body, C['austin']) is not None, found)
body = rec.get(H + '&tab=matches')[1]
check('Without a radius they include St. Louis, further down', C['stlouis'] in [int(x) for x in re.findall(r'data-match="(\d+)"', body)])
check('A site with no location says why there is no distance', 'id="no-site-location"' in rec.get('/hiring?v=%d' % fx['v_nowhere'])[1])

# ── ZIP codes on the forms ─────────────────────────────────────────────
D = C['dallas']
check('A ZIP code that is not one is refused', rec.post('/candidates/%d' % D, {'do': 'save', 'city': 'Dallas', 'state': 'TX', 'zip': '7700'})[0] == 422)
rec.post('/candidates/%d' % D, {'do': 'save', 'city': 'Dallas', 'state': 'TX', 'zip': '77002', 'discipline': 'chemical'})
check('A ZIP code is kept on the person', next(c for c in probe()['candidates'] if int(c['id']) == D)['zip'] == '77002')
check('and the next search places them by it: now within 50 miles of Katy', D in people(rec.get('/candidates?miles=50&near=%d' % fx['katy'])[1]) and next(c for c in probe()['candidates'] if int(c['id']) == D)['geo_from'] == 'zip')
check('The profile shows the ZIP code field', 'name="zip"' in rec.get('/candidates/%d' % D)[1])
admin.post('/projects', {'title': 'P5-M12 new site', 'client': 'Geo client', 'site_city': 'Baytown', 'site_state': 'TX', 'site_zip': '77520'})
job = next((j for j in probe()['jobs'] if j['title'] == 'P5-M12 new site'), None)
check('A new project keeps its site\'s ZIP code', job is not None and job['site_zip'] == '77520' and job['site_city'] == 'Baytown', job)
admin.post('/projects', {'title': 'P5-M12 bad zip', 'client': 'Geo client', 'site_zip': 'abc'})
check('and a ZIP code that is not one is left empty', next(j for j in probe()['jobs'] if j['title'] == 'P5-M12 bad zip')['site_zip'] is None)

# ── translations ───────────────────────────────────────────────────────
rec.post('/language', {'locale': 'es', 'back': '/candidates'})
check('Distance in Spanish', 'Cualquier distancia' in rec.get('/candidates')[1] and 'A menos de 50 millas del sitio' in rec.get(H)[1])
rec.post('/language', {'locale': 'en', 'back': '/candidates'})

json.dump(results, open('work/geo-http-results.json', 'w'), indent=1)
