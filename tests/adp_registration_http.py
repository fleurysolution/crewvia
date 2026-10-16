"""P5-M31 through the screens: the worker's registration with ADP.
  - payroll enters the registration code ADP gave, and only ADP's own https site as the link;
  - the worker sees the steps, the code and their employee ID once payroll records it; never a field for an SSN or ITIN;
  - the worker says they are done; payroll is told and sees who is still missing.
Runs after travel_details_http.py, on the walkthrough fixture and adp_registration_db.php's people.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
wk = json.load(open('work/walkthrough.json'))
fx = json.load(open('work/adp_registration.json'))
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
    return json.loads(subprocess.run([php, 'work/adp_registration_probe.php', *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


def flash(body):
    return ' '.join(re.sub(r'<[^>]+>|\s+', ' ', f).strip() for f in re.findall(r'class="flash[^"]*"[^>]*>(.*?)</p>', body, re.S))


def said(body):
    """A refusal: flashed on the page it redirects to, or the plain message of a 4xx answer."""
    return flash(body) if '<html' in body.lower() else body.strip()


def no_identity_fields(body):
    return not re.search(r'name="[^"]*(ssn|itin|social|birth|dob)[^"]*"', body, re.I)


worker = Client(); worker.login('adp-worker@test.invalid')
payroll = Client(); payroll.login('adp-payroll@test.invalid')
payroll.post('/select-project', {'job_id': wk['job']}, '/payroll-export')
PAGE = '/payroll-export'

# ── before payroll sets it up ───────────────────────────────────────────
check('Until payroll sets it up, the worker is not asked to register with ADP', 'id="adp"' not in worker.get('/portal')[1])
body = payroll.get(PAGE)[1]
check('Payroll export lists the project\'s workers with nothing recorded for ADP',
      'id="adp-registration" data-code="none"' in body and 'data-adp-person="%d" data-adp-state="missing"' % fx['worker'] in body)

# ── the code, and only ADP's own site ───────────────────────────────────
for url in ('https://adp.example.com', 'http://my.adp.com', 'https://my.adp.com.evil.example'):
    status, body = payroll.post(PAGE, {'do': 'adp_registration', 'code': 'RSSINC-2026', 'url': url}, PAGE)
    check('The link workers follow must be ADP\'s own site over https: %s is refused' % url, 'ADP\'s own site over https' in said(body), said(body))
status, body = payroll.post(PAGE, {'do': 'adp_registration', 'code': 'bad code!', 'url': 'https://my.adp.com'}, PAGE)
check('A registration code with spaces or symbols is refused', 'The registration code is 4 to 40' in said(body), said(body))
status, body = payroll.post(PAGE, {'do': 'adp_registration', 'code': 'RSSINC-2026', 'url': 'https://my.adp.com', 'note': 'Register before your first Friday.'}, PAGE)
check('The code saved', 'ADP registration saved' in said(body) and 'data-code="set"' in body, said(body))

# ── the worker's steps ──────────────────────────────────────────────────
body = worker.get('/portal')[1]
card = re.search(r'id="adp" data-registered="0">(.*?)</div>', body, re.S)
check('The worker sees the steps: ADP\'s site, the code, and that payroll gives the employee ID',
      card and 'href="https://my.adp.com" target="_blank" rel="noopener noreferrer"' in card[1] and 'id="adp-code">RSSINC-2026<' in card[1]
      and 'Payroll gives you your employee ID' in card[1] and 'Register before your first Friday.' in card[1], card[1][:500] if card else body[:300])
check('Their home lists it as waiting on them', 'Register with ADP to be paid' in body and 'href="#adp"' in body)
# P5-M64: the SSN is asked only in the W-4 and the I-9 the worker fills in the portal (/tax-forms), never on this page.
check('Crewvia tells them where it asks for the SSN, and this page has no field for one', 'Crewvia asks for your Social Security number only in your W-4 and I-9' in body and no_identity_fields(body))

# ── payroll records the employee ID ─────────────────────────────────────
status, body = payroll.post(PAGE, {'do': 'adp_employee_id', 'candidate_id': fx['worker'], 'adp_employee_id': 'x y'}, PAGE)
check('An ADP employee ID with a space is refused', 'An ADP employee ID is letters' in said(body), said(body))
w0 = probe('notices', fx['workerUser'])['count']
payroll.post(PAGE, {'do': 'adp_employee_id', 'candidate_id': fx['worker'], 'adp_employee_id': 'RSS00417'}, PAGE)
n = probe('notices', fx['workerUser'])
check('Recorded, the worker is told they can register with it', probe('status', fx['worker'])['adp_employee_id'] == 'RSS00417' and n['count'] == w0 + 1 and 'Your ADP employee ID is ready' in n['last'] and n['target'] == '/portal#adp', n)
body = worker.get('/portal')[1]
check('and their portal shows it for ADP\'s check', 'id="adp-employee-id">RSS00417<' in body)
check('Payroll\'s list shows the ID, not yet registered', 'data-adp-person="%d" data-adp-state="id"' % fx['worker'] in payroll.get(PAGE)[1])

# ── the worker is done ──────────────────────────────────────────────────
p0 = probe('notices', fx['payroll'])['count']
status, body = worker.post('/portal', {'do': 'adp_registered'}, '/portal')
st = probe('status', fx['worker'])
check('The worker says they registered: the date is kept', st['adp_registered_at'] is not None and 'Payroll can see you registered with ADP' in said(body), (st, said(body)))
n = probe('notices', fx['payroll'])
check('Payroll is told', n['count'] == p0 + 1 and 'Aaa Adp Worker registered with ADP.' in n['last'] and n['target'] == '/payroll-export#adp-registration', n)
body = worker.get('/portal')[1]
check('The portal says it is done and no longer asks', 'data-registered="1"' in body and 'value="adp_registered"' not in body and 'Register with ADP to be paid</a>' not in body)
body = payroll.get(PAGE)[1]
check('Payroll\'s list shows them registered, and the count', 'data-adp-person="%d" data-adp-state="registered"' % fx['worker'] in body and re.search(r'data-adp-registered="\d+"', body))

# ── the code cleared ────────────────────────────────────────────────────
payroll.post(PAGE, {'do': 'adp_registration', 'code': '', 'url': 'https://my.adp.com'}, PAGE)
check('With the code cleared, workers are no longer shown the steps', 'id="adp"' not in worker.get('/portal')[1])
status, body = worker.post('/portal', {'do': 'adp_registered'}, '/portal')
check('and cannot mark it done', 'Payroll has not set up ADP registration yet.' in said(body), said(body))

json.dump(results, open('work/adp-http-results.json', 'w'), indent=1)
