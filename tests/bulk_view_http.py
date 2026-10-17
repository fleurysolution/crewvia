"""P5-M50 through the screens: documents read in the browser; applicants invited or moved together.
  - a PDF opens in the browser (View, inline, its own file name); Download, or a Word file, saves it;
  - Hiring lets the office pick applicants (one by one or all shown) and invite them to their portal, or move them
    to Screening (which invites them too); somebody already invited is counted, not invited twice;
  - nothing selected is refused.
Runs after mail_routing_http.py, on the walkthrough fixture.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
wk = json.load(open('work/walkthrough.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def raw(self, req):
        try:
            with self.opener.open(req) as r:
                return r.status, r.read(), dict(r.headers)
        except urllib.error.HTTPError as e:
            return e.code, e.read(), dict(e.headers)

    def get(self, path):
        status, body, _ = self.raw(base + path)
        body = body.decode('utf-8', 'replace')
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body

    def login(self, email, password='TestPassword123!'):
        self.get('/login')
        self.raw(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': password}).encode()))

    def post(self, path, values, page):
        self.get(page)
        status, body, _ = self.raw(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}, doseq=True).encode(), {'Accept': 'text/html'}))
        return status, body.decode('utf-8', 'replace')


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS ' + label)


def probe(script, *args):
    return json.loads(subprocess.run([php, 'work/%s_probe.php' % script, *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


rec = Client(); rec.login('wk-rec@test.invalid')

# ── reading a document without downloading it ──
doc = probe('reviews', 'doc', 'in-one@test.invalid')
status, raw, h = rec.raw(base + '/resumes?download=%d&view=1' % doc)
check('A PDF opens in the browser, under its own name', status == 200 and raw.startswith(b'%PDF') and h.get('Content-Type', '').startswith('application/pdf')
      and h.get('Content-Disposition', '').startswith('inline') and 'Indeed Test One CV.pdf' in h.get('Content-Disposition', ''), h.get('Content-Disposition'))
status, raw, h = rec.raw(base + '/resumes?download=%d' % doc)
check('Download saves it', status == 200 and h.get('Content-Disposition', '').startswith('attachment'), h.get('Content-Disposition'))
one = probe('reviews', 'app', 'in-one@test.invalid')
status, body = rec.get('/hiring?v=%d&app=%d' % (one['vacancy_id'], one['id']))
check('Hiring links each PDF to read it, and keeps a download beside it', ('/resumes?download=%d&amp;view=1' % doc) in body and 'target="_blank"' in body)
status, body = rec.get('/resumes')
check('So does Candidate documents (View and Download)', '&amp;view=1' in body and '>View<' in body)

# ── several applicants at once ──
status, body = rec.get('/hiring?v=%d&stage=all' % wk['vA'])
_emma = probe('reviews', 'app', 'add-emma@test.invalid')
check('Hiring lists nobody a manager has not approved (P5-M93)', status == 200 and ('data-app="%d"' % _emma['id']) not in body)
page = '/hiring?v=%d&stage=all' % wk['vA']
status, body = rec.post('/hiring', {'do': 'bulk', 'vacancy_id': wk['vA'], 'bulk_action': 'invite', 'back': page}, page)
check('Nothing selected is refused', 'Select at least one applicant' in body)

emma = probe('reviews', 'app', 'add-emma@test.invalid')
two = probe('reviews', 'app', 'in-two@test.invalid')
before = probe('availability', 'outbox', 'add-emma@test.invalid')
status, body = rec.post('/hiring', {'do': 'bulk', 'vacancy_id': wk['vA'], 'bulk_action': 'invite', 'apps[]': [emma['id'], two['id']], 'back': page}, page)
check('Invited together: each gets their portal invitation, the stage untouched',
      probe('availability', 'outbox', 'add-emma@test.invalid') == before + 1 and 'invited to their portal' in body
      and probe('reviews', 'app', 'add-emma@test.invalid')['stage'] == emma['stage'], re.findall(r'class="flash[^"]*"[^>]*>(.*?)</', body, re.S)[:1])
status, body = rec.post('/hiring', {'do': 'bulk', 'vacancy_id': wk['vA'], 'bulk_action': 'invite', 'apps[]': [emma['id']], 'back': page}, page)
check('Invited again, nobody gets a second email: counted as already invited', probe('availability', 'outbox', 'add-emma@test.invalid') == before + 1 and 'already had it' in body)

status, body = rec.post('/hiring', {'do': 'bulk', 'vacancy_id': wk['vA'], 'bulk_action': 'screening', 'apps[]': [emma['id']], 'back': page}, page)
check('Moved to Screening together', probe('reviews', 'app', 'add-emma@test.invalid')['stage'] == 'screening' and 'moved to Screening' in body, body[:200])
status, body = rec.post('/hiring', {'do': 'bulk', 'vacancy_id': wk['vB'], 'bulk_action': 'screening', 'apps[]': [two['id']], 'back': page}, page)
check('An applicant of another requisition is not touched', probe('reviews', 'app', 'in-two@test.invalid')['stage'] == two['stage'])

json.dump(results, open('work/bv-http-results.json', 'w'), indent=1)
