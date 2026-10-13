"""P5-M19, the three findings of the review, through the screens:
  1. a termination locks the worker's access at once (R31): decided on a
     case, the worker's open session ends, they cannot sign in again, and
     their trusted phones are revoked; a separation recorded as terminated
     does the same; a resignation does not; an administrator can switch the
     account back on;
  2. a supervisor grades their own crew's finished assignments on My team
     (only theirs, only finished in the last 60 days), and the Roster's
     grading stays with recruiters;
  3. a worker's and a supervisor's menu is in their own words, while the
     desks keep theirs.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/review_fixes.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS, C, P, JOB = fx['ids'], fx['cands'], fx['places'], fx['job']
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

    def post(self, path, values, page=None):
        self.get(page or path)
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()))

    def login(self, key):
        self.get('/login')
        status, body = self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': 'rf-%s@test.invalid' % key, 'password': 'TestPassword123!'}).encode()))
        return '<title>Sign in' not in body


def user(key):
    c = Client()
    assert c.login(key), key
    return c


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe():
    return json.loads(subprocess.run([php, 'work/review_fixes_probe.php'], check=True, capture_output=True, text=True).stdout)


def active(key):
    return int(probe()['active'][str(IDS[key])]) == 1


admin, rec, sup, sup2 = user('admin'), user('rec'), user('sup'), user('sup2')
worker = user('w')

# ── 1. a termination locks access at once ──────────────────────────────
status, body = worker.get('/portal')
check('The worker is signed in, with a trusted phone', status == 200 and '<title>Sign in' not in body and any(int(p['user_id']) == IDS['w'] and p['revoked_at'] is None for p in probe()['phones']))
admin.post('/hr-records', {'do': 'case_decide', 'case_id': fx['case'], 'outcome': 'termination', 'note': 'Left the unit unattended during a permit job; confirmed by two witnesses.'}, '/hr-records?case=%d' % fx['case'])
check('A case decided as a termination switches the account off', not active('w'))
status, body = worker.get('/portal')
check('and ends the session the worker had open', '<title>Sign in' in body)
check('They cannot sign in again', not Client().login('w'))
check('Their trusted phone is revoked, and it is on their history', all(p['revoked_at'] and p['revoke_reason'] == 'terminated' for p in probe()['phones'] if int(p['user_id']) == IDS['w'])
      and any(int(e['candidate_id']) == C['w'] and e['event_type'] == 'portal access locked' and 'P5M19-1' in e['detail'] for e in probe()['events']))
admin.post('/hr-records', {'do': 'separation', 'placement_id': P['w2'], 'reason': 'resigned', 'separated_on': '2026-10-10', 'rehire': 'eligible', 'detail': 'Took a job nearer home.'}, '/hr-records?candidate=%d' % C['w2'])
check('A resignation leaves the portal open: they still read their pay and answer job offers', active('w2') and Client().login('w2'))
check('A termination decided outside the screens (as before this change) is not locked by the screens', Client().login('w3'))
admin.post('/hr-records', {'do': 'separation', 'placement_id': P['w3'], 'reason': 'terminated', 'case_id': fx['case3'], 'separated_on': '2026-10-10', 'rehire': 'ineligible', 'detail': 'Repeated permit violations on the unit.'}, '/hr-records?candidate=%d' % C['w3'])
check('A separation recorded as terminated locks it too', not active('w3') and not Client().login('w3'))
status, body = admin.get('/people')
check('People shows the account off, and an administrator can switch it back on', status == 200 and 'value="toggle"' in body)
admin.post('/people', {'do': 'toggle', 'user_id': IDS['w']})
check('Switched back on, the worker signs in again', active('w') and Client().login('w'))

# ── 2. supervisors grade their own crew ────────────────────────────────
status, body = sup.get('/my-team')
listed = {int(x) for x in re.findall(r'name="placement_id" value="(\d+)"', body)}
check('My team asks the supervisor to grade their crew member who finished 10 days ago', 'id="grading"' in body and listed == {P['g1']}, listed)
check('not one finished 90 days ago, one still on site, or another supervisor\'s', not listed & {P['g90'], P['gon'], P['gother']})
check('Not having them back needs a reason', sup.post('/my-team', {'do': 'review', 'placement_id': P['g1'], 'grade': 'D', 'would_rehire': '0', 'note': ''})[0] == 422)
sup.post('/my-team', {'do': 'review', 'placement_id': P['g1'], 'grade': 'A', 'would_rehire': '1', 'note': 'Kept the crew safe on nights.'})
r = next((x for x in probe()['reviews'] if int(x['placement_id']) == P['g1']), None)
check('The grade is recorded by the supervisor, and goes on the person\'s history', r and r['grade'] == 'A' and int(r['reviewed_by']) == IDS['sup'] and any(int(e['candidate_id']) == C['g1'] and e['event_type'] == 'assignment review' for e in probe()['events']), r)
check('Graded, it leaves the list', 'id="grading"' not in sup.get('/my-team')[1])
check('A supervisor cannot grade someone else\'s crew', sup.post('/my-team', {'do': 'review', 'placement_id': P['gother'], 'grade': 'B', 'would_rehire': '1'})[0] == 404
      and not any(int(x['placement_id']) == P['gother'] for x in probe()['reviews']))
check('nor grade from the Roster, which stays the recruiter\'s', sup.post('/roster', {'do': 'review', 'placement_id': P['g90'], 'grade': 'C', 'would_rehire': '1'}, '/my-team')[0] in (403, 200)
      and not any(int(x['placement_id']) == P['g90'] for x in probe()['reviews']))
rec.post('/select-project', {'job_id': JOB}, '/roster')
rec.post('/roster', {'do': 'review', 'placement_id': P['g90'], 'grade': 'B', 'would_rehire': '1', 'note': 'Solid.'})
check('A recruiter still grades from the Roster', any(int(x['placement_id']) == P['g90'] and x['grade'] == 'B' and int(x['reviewed_by']) == IDS['rec'] for x in probe()['reviews']))

# ── 3. the menu in their own words ─────────────────────────────────────
w2 = user('w2')
body = w2.get('/portal')[1]
sections = dict(re.findall(r'<details class="nav-group" data-section="([^"]+)"[^>]*>(.*?)</details>', body, re.S))
check('A worker\'s contracts are under "My papers", not "Hiring"', 'My papers' in sections and 'href="/contracts"' in sections['My papers'] and 'Hiring' not in sections, list(sections))
check('their HR record under "HR", not "Administration"', 'href="/hr-records"' in sections.get('HR', '') and 'Administration' not in sections)
check('their pay statements under "My pay", and Jobs for you under "My work"', 'href="/my-payslips"' in sections.get('My pay', '') and 'href="/my-jobs"' in sections.get('My work', ''))
body = sup.get('/my-team')[1]
check('A supervisor\'s team and tasks are under "My work"', 'href="/my-team"' in dict(re.findall(r'data-section="([^"]+)"[^>]*>(.*?)</details>', body, re.S)).get('My work', ''))
check('The desks keep their own sections', 'data-section="Recruiting"' in rec.get('/roster')[1] and 'data-section="Deployment"' in rec.get('/roster')[1])
w2.post('/language', {'locale': 'es', 'back': '/portal'}, '/portal')
check('The worker\'s menu in Spanish', 'Mis documentos' in w2.get('/portal')[1])
w2.post('/language', {'locale': 'en', 'back': '/portal'}, '/portal')

json.dump(results, open('work/rf-http-results.json', 'w'), indent=1)
