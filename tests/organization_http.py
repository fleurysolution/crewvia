"""P5-M17 through the screen: the organization chart draws each person
with a link to reassign them, and the supervisor list only for the one
person being reassigned, so the page grows with the people on the job and
not with people x staff (the cause of the load test's dropped /organization
requests); assigning saves through Operations and comes back to the chart;
Logistics reads the chart without the links; nothing else changed.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/organization.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS, JOB, P = fx['ids'], fx['job'], fx['places']
results = []
G = '/organization'


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
        self.get(G)
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()))


def user(key):
    c = Client()
    c.get('/login')
    c.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': c.token, 'email': 'org-%s@test.invalid' % key, 'password': 'TestPassword123!'}).encode()))
    c.post('/select-project', {'job_id': JOB})
    return c


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    out = subprocess.run([php, 'work/organization_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


rec, hotels = user('rec'), user('hotels')
status, body = rec.get(G)
check('The chart lists all 30 people, each with a link to assign or reassign them', status == 200 and len(re.findall(r'data-assign="\d+"', body)) == 30
      and body.count('>Assign<') == 10 and body.count('>Reassign<') == 20, (len(re.findall(r'data-assign="\d+"', body)), body.count('>Assign<'), body.count('>Reassign<')))
check('and draws no supervisor list until one is asked for', 'name="supervisor_id"' not in body)

# ── the page grows with people, not with people x staff ─────────────────
size = len(body)
probe('staff', 20)
grown = len(rec.get(G)[1]) - size
check('Twenty more staff add only their names to the office list, not 30 x 20 options (%d bytes)' % grown, grown < 3000, grown)

# ── reassigning one person ─────────────────────────────────────────────
target = P[25]                                     # one of the ten with no supervisor
status, body = rec.get(G + '?assign=%d' % target)
check('Asked for, exactly one form opens, for that person', body.count('name="supervisor_id"') == 1 and 'id="assign-form"' in body
      and 'name="placement_id" value="%d"' % target in body and 'data-assign="%d"' % target not in body)
options = re.search(r'<select[^>]*name="supervisor_id">(.*?)</select>', body, re.S)[1].count('<option')
check('Its list offers every active staff member who could carry a crew, and "No supervisor"', options >= 2 + 20 + 1, options)
status, body = rec.post('/operations', {'do': 'assignment', 'back': '/organization', 'placement_id': target, 'supervisor_id': IDS['sup1'], 'trade': 'Rigger', 'shift_label': 'Nights'})
saved = next(a for a in probe()['assignments'] if int(a['placement_id']) == target)
check('Saving goes through Operations and comes back to the chart', rec.url.split('#')[0].endswith(G) and int(saved['supervisor_id']) == IDS['sup1'] and saved['trade'] == 'Rigger' and saved['shift_label'] == 'Nights', (rec.url, saved))
check('Now on that crew, nine people left with no supervisor', rec.get(G)[1].count('>Assign<') == 9)
status, body = rec.post('/operations', {'do': 'assignment', 'placement_id': P[26], 'supervisor_id': IDS['sup2'], 'trade': 'Fitter'})
check('Saved from Operations itself, it still returns to Operations', rec.url.endswith('/operations'))
check('A person who is not on this job opens no form', rec.get(G + '?assign=999999')[1].count('name="supervisor_id"') == 0)

# ── who sees what ──────────────────────────────────────────────────────
status, body = hotels.get(G)
check('Logistics reads the chart without the reassign links', status == 200 and 'Org person 01' in body and 'data-assign=' not in body and 'name="supervisor_id"' not in hotels.get(G + '?assign=%d' % P[0])[1])

json.dump(results, open('work/org-http-results.json', 'w'), indent=1)
