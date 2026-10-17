"""P5-M28 through the screens: the notices along the flow, and the VP's validation of an offer.
  - an order line tells recruiting and the plant's project manager; a requisition tells every recruiter;
  - with an "Offer before it reaches the candidate" chain, a recruiter's offer waits for the VP;
    approved, it goes out and the recruiter and the candidate are told; turned down, the recruiter is told why.
Runs after plant_setup_http.py, on the walkthrough fixture (work/walkthrough.json).
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/walkthrough.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS = fx['ids']
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
    return json.loads(subprocess.run([php, 'work/notices_probe.php', *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


def flash(body):
    return ' '.join(re.sub(r'<[^>]+>|\s+', ' ', f).strip() for f in re.findall(r'class="flash[^"]*"[^>]*>(.*?)</p>', body, re.S))


admin = Client(); admin.login('wk-admin@test.invalid')
rec = Client(); rec.login('wk-rec@test.invalid')
for c in (admin, rec):
    c.post('/select-project', {'job_id': fx['job']}, '/job' if c is admin else '/hiring')

# ── the plant's project manager ─────────────────────────────────────────
body = admin.get('/job')[1]
check('The agreement names its project manager, from the staff', 'name="project_manager_id"' in body)
before = probe('notices', IDS['sup'])
admin.post('/job', {'title': 'P5-M26 plant', 'project_manager_id': IDS['sup']})
check('and the person named is told they manage it', probe('notices', IDS['sup'])['count'] == before['count'] + 1 and 'You manage' in probe('notices', IDS['sup'])['last'], probe('notices', IDS['sup']))

# ── an order line tells recruiting and the project manager ─────────────
r0, s0 = probe('notices', IDS['rec'])['count'], probe('notices', IDS['sup'])['count']
admin.post('/job', {'do': 'add_line', 'role_title': 'Walk welder', 'discipline': 'mechanical', 'quantity': '200', 'pay_rate': '38', 'markup': '35'})
n = probe('notices', IDS['rec'])
check('A new order line tells the recruiters to raise the requisition', n['count'] == r0 + 1 and 'New order on P5-M26 plant: 200 x Walk welder' in n['last'] and n['target'] == '/requisitions', n)
check('and the plant\'s project manager', probe('notices', IDS['sup'])['count'] == s0 + 1)

# ── a requisition tells every recruiter (not the one who raised it) ────
t0 = probe('notices', IDS['temp'])['count']; r1 = probe('notices', IDS['rec'])['count']
line = probe('line', 'Walk welder')['id']
rec.post('/requisitions', {'do': 'create', 'order_line_id': line, 'title': 'Walk welder req', 'discipline': 'mechanical', 'openings': '50', 'starts_on': '2026-11-09', 'description': 'Synthetic.'}, '/requisitions')
n = probe('notices', IDS['temp'])
check('A requisition tells the other recruiters to find the candidates, linking to its applicants', n['count'] == t0 + 1 and 'New requisition on P5-M26 plant: 50 x Walk welder req starting' in n['last'] and n['target'].startswith('/candidates?vacancy='), n)
check('but not the recruiter who raised it', probe('notices', IDS['rec'])['count'] == r1)

# ── the VP validates an offer before it reaches the candidate ──────────
admin.post('/approval-chains', {'do': 'create_chain', 'name': 'Walk offer validation', 'applies_to': 'offer'}, '/approval-chains')
chain = probe('chain', 'Walk offer validation')
admin.post('/approval-chains', {'do': 'add_step', 'chain_id': chain['id'], 'label': 'VP validation', 'role_slug': 'admin', 'gate_type': 'sequential', 'is_required': '1'}, '/approval-chains')
a0 = probe('notices', IDS['admin'])['count']
status, body = rec.post('/hiring', {'do': 'move', 'application_id': fx['app2'], 'stage': 'offered', 'note': 'Report at 6:30 with your boots.'}, '/hiring?v=%d&app=%d' % (fx['vA'], fx['app2']))
st = probe('app', fx['app2'])
check('With the chain, the recruiter\'s offer waits for validation: the candidate is not offered yet', st['stage'] != 'offered' and st['validation'] == 'pending' and 'sent for validation' in flash(body), (st, flash(body)))
check('and the VP is told there is one to decide', probe('notices', IDS['admin'])['count'] > a0)
check('Hiring shows it waiting', 'data-validation="pending"' in rec.get('/hiring?v=%d&app=%d' % (fx['vA'], fx['app2']))[1])
status, body = rec.post('/hiring', {'do': 'move', 'application_id': fx['app2'], 'stage': 'offered', 'note': 'Again.'}, '/hiring?v=%d&app=%d' % (fx['vA'], fx['app2']))
check('It cannot be sent twice', 'already waiting for validation' in flash(body) or 'already waiting' in body)
r2 = probe('notices', IDS['rec'])['count']
admin.post('/approvals', {'request_id': st['request'], 'decision': 'approved', 'comments': 'Good profile.'}, '/approvals')
st = probe('app', fx['app2'])
check('Approved, the offer goes out with the recruiter\'s note', st['stage'] == 'offered' and st['offer_note'] == 'Report at 6:30 with your boots.', st)
n = probe('notices', IDS['rec'])
check('and the recruiter is told the candidate was validated and has the offer', n['count'] == r2 + 1 and 'was validated' in n['last'], n)
check('and the candidate is invited to their portal to read it', st['invited'] >= 1, st)

# Turned down: the recruiter hears why; the candidate hears nothing.
c3 = probe('newapp', fx['vA'])
rec.post('/hiring', {'do': 'move', 'application_id': c3, 'stage': 'offered', 'note': 'Report Monday.'}, '/hiring?v=%d&app=%d' % (fx['vA'], c3))
st = probe('app', c3)
r3 = probe('notices', IDS['rec'])['count']
admin.post('/approvals', {'request_id': st['request'], 'decision': 'rejected', 'comments': 'Not enough setup experience.'}, '/approvals')
st = probe('app', c3)
n = probe('notices', IDS['rec'])
check('Turned down, the offer does not go out', st['stage'] != 'offered' and st['validation'] == 'rejected', st)
check('and the recruiter is told why', n['count'] == r3 + 1 and 'Not enough setup experience.' in n['last'], n)
admin.post('/approval-chains', {'do': 'toggle_chain', 'chain_id': chain['id']}, '/approval-chains')

json.dump(results, open('work/nt-http-results.json', 'w'), indent=1)
