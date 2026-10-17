"""P5-M10 through the screens: the requisitions list and its counts; one
requisition's workspace with its stage tabs, filters and search; the
interest shown on an applicant (yes / maybe / no, a second click clears
it); moving an applicant with the recruitment page's rules (settled
applications stay settled, hiring happens in the person's portal, an offer
needs the checks passed, the required questions answered, the documents
verified and its terms written); rejecting with a reason; notes on the
timeline; the split review; talent pool matches and adding one (never
twice, never someone on the do-not-rehire register, never to a closed
requisition); redirects that stay inside /hiring; who may open it; the
header's bell and account menu; translations.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/hiring.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS, CANDS, APPS, OPEN, CLOSED, JOB = fx['ids'], fx['cands'], fx['apps'], fx['open'], fx['closed'], fx['job']
results = []
H = '/hiring'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Client:
    def __init__(self):
        jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
        self.raw = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)
        self.token = None

    def open(self, req, opener=None):
        try:
            with (opener or self.opener).open(req) as r:
                return r.status, r.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode() if e.code != 302 else e.headers.get('Location', '')

    def get(self, path):
        status, body = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body

    def post(self, values, follow=True):
        self.get(H)
        data = urllib.parse.urlencode({'_csrf': self.token, **values}).encode()
        return self.open(urllib.request.Request(base + H, data), None if follow else self.raw)


def user(key):
    c = Client()
    c.get('/login')
    data = urllib.parse.urlencode({'_csrf': c.token, 'email': 'hw-%s@test.invalid' % key, 'password': 'TestPassword123!'}).encode()
    status, body = c.open(urllib.request.Request(base + '/login', data))
    assert '<title>Sign in' not in body, key
    return c


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    out = subprocess.run([php, 'work/hiring_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def app(key_or_id):
    i = APPS[key_or_id] if isinstance(key_or_id, str) else key_or_id
    return next(a for a in probe()['applications'] if int(a['id']) == i)


def listed(body):
    return {int(x) for x in re.findall(r'data-app="(\d+)"', body)}


def tab_count(body, tab):
    m = re.search(r'data-stage-tab="%s">[^<]*<b>(\d+)</b>' % tab, body)
    return int(m[1]) if m else None


rec, admin, hotels = user('rec'), user('admin'), user('hotels')
ANN, BEN, CARA, DEE = APPS['ann'], APPS['ben'], APPS['cara'], APPS['dee']
W = H + '?v=%d' % OPEN

# ── the requisitions list ──────────────────────────────────────────────
status, body = rec.get(H)
check('A recruiter opens Hiring from the menu', status == 200 and 'href="/hiring"' in body and 'id="jobs"' in body)
check('The open requisition is listed: its approved people, and its applicants a link to Candidates (P5-M93)', 'data-vacancy="%d"' % OPEN in body and 'data-to-onboard="0"' in body and ('href="/candidates?vacancy=%d"' % OPEN) in body and 'data-applicants="4"' in body)
check('The closed one is not, until asked for', 'data-vacancy="%d"' % CLOSED not in body and 'data-vacancy="%d"' % CLOSED in rec.get(H + '?status=closed')[1])

# ── one requisition ────────────────────────────────────────────────────
status, body = rec.get(W)
check('The workspace holds no applicant a manager has not approved', status == 200 and listed(body) == set(), listed(body))
check('Its tabs count the approved people only', (tab_count(body, 'active'), tab_count(body, 'accepted'), tab_count(body, 'all')) == (0, 1, 1))
check('Hired shows the hired person only', listed(rec.get(W + '&stage=accepted')[1]) == {CARA})
check('All shows the hired one, not the applicants', listed(rec.get(W + '&stage=all')[1]) == {CARA})
check('Search narrows by name', listed(rec.get(W + '&stage=all&q=Cara')[1]) == {CARA} and listed(rec.get(W + '&stage=all&q=Ann')[1]) == set())
check('A requisition that does not exist is refused', rec.get(H + '?v=999999')[0] == 404)

# ── interest ───────────────────────────────────────────────────────────
rec.post({'do': 'interest', 'application_id': ANN, 'interest': 'yes', 'back': W})
a = app('ann')
check('Marking an applicant as interesting records who and when', a['interest'] == 'yes' and int(a['interest_by']) == IDS['rec'] and a['interest_at'])
check('An applicant marked interesting stays out of Hiring until approved', ANN not in listed(rec.get(W + '&stage=all&interest=yes')[1]))
rec.post({'do': 'interest', 'application_id': ANN, 'interest': 'yes', 'back': W})
check('A second click clears it', app('ann')['interest'] is None and app('ann')['interest_by'] is None)
rec.post({'do': 'interest', 'application_id': DEE, 'interest': 'maybe', 'back': W})
rec.post({'do': 'interest', 'application_id': DEE, 'interest': 'no', 'back': W})
check('Another choice replaces the first', app('dee')['interest'] == 'no')
check('An unknown interest is refused', rec.post({'do': 'interest', 'application_id': ANN, 'interest': 'perhaps'})[0] == 422 and app('ann')['interest'] is None)

# ── moving applicants ──────────────────────────────────────────────────
check('An offer is refused while a required question is unanswered', rec.post({'do': 'move', 'application_id': ANN, 'stage': 'offered', 'note': '40/h'})[0] == 422 and app('ann')['stage'] == 'new')
check('Nobody is hired from here: that is the person\'s own acceptance', rec.post({'do': 'move', 'application_id': ANN, 'stage': 'accepted'})[0] == 422 and app('ann')['stage'] == 'new')
check('Moving to the same stage is refused', rec.post({'do': 'move', 'application_id': ANN, 'stage': 'new'})[0] == 422)
rec.post({'do': 'move', 'application_id': ANN, 'stage': 'screening', 'note': 'Phone screen booked', 'back': W})
ev = [e for e in probe()['events'] if int(e['application_id']) == ANN]
check('A move is recorded with its note', app('ann')['stage'] == 'screening' and ev and ev[-1]['stage'] == 'screening' and ev[-1]['note'] == 'Phone screen booked' and int(ev[-1]['user_id']) == IDS['rec'])
check('An offer is refused while a check is pending', rec.post({'do': 'move', 'application_id': BEN, 'stage': 'offered', 'note': '40/h'})[0] == 422 and app('ben')['stage'] == 'screening')
probe('check', BEN, 'passed')
check('and without its terms', rec.post({'do': 'move', 'application_id': BEN, 'stage': 'offered', 'note': ''})[0] == 422)
probe('require', JOB, 'on')
check('and while a document the project requires is not verified', rec.post({'do': 'move', 'application_id': BEN, 'stage': 'offered', 'note': '40/h, 12 weeks'})[0] == 422 and app('ben')['stage'] == 'screening')
probe('require', JOB, 'off')
rec.post({'do': 'move', 'application_id': BEN, 'stage': 'offered', 'note': '40/h, 12 weeks', 'back': W})
check('Ready, the offer goes through with its terms', app('ben')['stage'] == 'offered' and [e for e in probe()['events'] if int(e['application_id']) == BEN][-1]['note'] == '40/h, 12 weeks')
check('Offered, they come into Hiring, to onboard', listed(rec.get(W)[1]) == {BEN})
check('A hired application is settled', rec.post({'do': 'move', 'application_id': CARA, 'stage': 'new'})[0] == 422 and app('cara')['stage'] == 'accepted')
rec.post({'do': 'reject', 'application_id': DEE, 'note': 'No travel', 'back': W})
check('Rejecting keeps the reason', app('dee')['stage'] == 'rejected' and [e for e in probe()['events'] if int(e['application_id']) == DEE][-1]['note'] == 'No travel')
check('and settles it', rec.post({'do': 'move', 'application_id': DEE, 'stage': 'screening'})[0] == 422)
check('Rejected before any approval, they never come into Hiring', listed(rec.get(W + '&stage=closed')[1]) == set())

# ── notes, the split review ────────────────────────────────────────────
check('An empty note is refused', rec.post({'do': 'note', 'application_id': ANN, 'note': ' '})[0] == 422)
rec.post({'do': 'note', 'application_id': ANN, 'note': 'Prefers night shift', 'back': W})
check('A note joins the application\'s events', [e for e in probe()['events'] if int(e['application_id']) == ANN][-1]['note'] == 'Prefers night shift')
status, body = rec.get(W + '&stage=all&app=%d' % BEN)
check('The split review shows the person beside the list', status == 200 and 'id="review"' in body and 'data-application="%d"' % BEN in body and 'data-list-app="%d"' % CARA in body)
check('with the screener questions, the move and reject forms', 'id="screener"' in body and 'id="move-form"' in body and 'id="reject-form"' in body)
check('and the next or previous person', 'id="prev-app"' in body or 'id="next-app"' in body)
body = rec.get(W + '&stage=all&app=%d&detail=activity' % ANN)[1]
check('Its activity shows the notes and moves', 'id="activity"' in body and 'Prefers night shift' in body and 'Phone screen booked' in body)
check('An application of another requisition is not opened here', rec.get(H + '?v=%d&app=%d' % (CLOSED, ANN))[0] == 404)

# ── talent pool matches ────────────────────────────────────────────────
body = rec.get(W + '&tab=matches')[1]
found = [int(x) for x in re.findall(r'data-match="(\d+)"', body)]
check('Matches show the pool in this discipline, past assignments first', found[:1] == [CANDS['veteran']] and CANDS['pool'] in found, found)
check('but nobody on the do-not-rehire register, in another discipline or already applying', not {CANDS['banned'], CANDS['mech'], CANDS['ann'], CANDS['ben']} & set(found), found)
rec.post({'do': 'add', 'vacancy_id': OPEN, 'candidate_id': CANDS['pool'], 'back': W + '&tab=matches'})
added = [a for a in probe()['applications'] if int(a['candidate_id']) == CANDS['pool']]
check('Adding one makes a new application from the talent pool', len(added) == 1 and added[0]['stage'] == 'new' and added[0]['source'] == 'Talent pool' and [e for e in probe()['events'] if int(e['application_id']) == int(added[0]['id'])][-1]['note'] == 'Added from the talent pool')
check('They leave the matches and join the applicants, on Candidates', 'data-match="%d"' % CANDS['pool'] not in rec.get(W + '&tab=matches')[1] and int(added[0]['id']) not in listed(rec.get(W + '&stage=all')[1])
      and ('/candidates/%d"' % CANDS['pool']) in rec.get('/candidates?vacancy=%d' % OPEN)[1])
check('Never twice', rec.post({'do': 'add', 'vacancy_id': OPEN, 'candidate_id': CANDS['pool']})[0] == 422)
check('Never someone on the do-not-rehire register', rec.post({'do': 'add', 'vacancy_id': OPEN, 'candidate_id': CANDS['banned']})[0] == 422)
check('Never to a closed requisition', rec.post({'do': 'add', 'vacancy_id': CLOSED, 'candidate_id': CANDS['veteran']})[0] == 422)
check('No add button on a closed requisition', 'name="do" value="add"' not in rec.get(H + '?v=%d&tab=matches' % CLOSED)[1])

# ── redirects, access, the header, translations ────────────────────────
status, where = rec.post({'do': 'interest', 'application_id': ANN, 'interest': 'maybe', 'back': 'https://elsewhere.example/'}, follow=False)
check('A return address outside Hiring is ignored', status == 302 and where.endswith('/hiring'), where)
check('An unknown action is refused', rec.post({'do': 'hire_everyone'})[0] == 422)
check('Logistics does not open Hiring', hotels.get(H)[0] == 403 and hotels.get(W)[0] == 403)
check('nor changes an application through it', hotels.post({'do': 'interest', 'application_id': BEN, 'interest': 'no'})[0] == 403 and app('ben')['interest'] is None)
check('An administrator does', admin.get(W)[0] == 200)
body = rec.get(H)[1]
check('The header has the bell and the account menu', 'id="bell"' in body and 'id="account-menu"' in body and 'action="/logout"' in body)
check('with the language picker kept inside it', 'id="lang-pick"' in body)
body = rec.get(W + '&lang=fr')[1]
check('Hiring in French', 'À intégrer' in body and 'Toutes les demandes' in body)
check('and in Spanish', 'Por incorporar' in rec.get(W + '&lang=es')[1])
rec.get(H + '?lang=en')

json.dump(results, open('work/hw-http-results.json', 'w'), indent=1)
