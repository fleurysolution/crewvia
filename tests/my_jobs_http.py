"""P5-M13 (T10) through the screens: a worker's "Jobs for you" lists the
open jobs their file fits (trade and years, when the requisition asks
them) and nothing closed, expired, on a closed project, or outside their
trade or experience; what the file leaves open is said, not held against
them; pay, per diem, lodging and distance are shown. "I'm interested"
needs the required questions answered, makes an application from the
worker portal with its answers, and is refused twice, for a job that does
not fit, or one that is closed; it reaches the recruiter's Hiring
workspace. While still new it can be taken back, and given again. The
do-not-rehire register sees no job and is refused; an unlinked account and
a recruiter do not get the page; the portal's card counts the jobs; the
public application form still asks its questions. Translations.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/my_jobs.json'))
php = os.environ.get('PHP_BIN', 'php')
V, IDS, CANDS, Q, OPT = fx['v'], fx['ids'], fx['cands'], fx['question'], fx['optional']
results = []
M = '/my-jobs'


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

    def post(self, values, path=M):
        self.get(path)
        data = urllib.parse.urlencode({'_csrf': self.token, **values}).encode()
        return self.open(urllib.request.Request(base + path, data))


def user(key):
    c = Client()
    c.get('/login')
    data = urllib.parse.urlencode({'_csrf': c.token, 'email': 'mj-%s@test.invalid' % key, 'password': 'TestPassword123!'}).encode()
    status, body = c.open(urllib.request.Request(base + '/login', data))
    assert '<title>Sign in' not in body, key
    return c


# P5-M23: the count reads as a person says it (app/i18n.php tn()).
fits = lambda n: '1 open job fits your file.' if n == 1 else '%d open jobs fit your file.' % n


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    out = subprocess.run([php, 'work/my_jobs_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def every(body):
    return {int(x) for x in re.findall(r'data-job="(\d+)"', body)}


def offered(body):
    """This fixture's jobs on the page: other suites leave open jobs of their own."""
    return every(body) & set(V.values())


def mine(cand):
    return [a for a in probe()['applications'] if int(a['candidate_id']) == cand]


w, blank, banned, loose, rec = user('w'), user('blank'), user('banned'), user('loose'), user('rec')
W = CANDS['w']

# ── what is offered ────────────────────────────────────────────────────
status, body = w.get(M)
check('A worker opens Jobs for you from their menu', status == 200 and 'href="/my-jobs"' in body and 'id="jobs-for-me"' in body)
check('Only the open jobs that fit: their trade with the years it asks, and the one open to any trade', offered(body) == {V['chem'], V['any']}, offered(body))
check('Nothing closed, expired, on a closed project, in another trade, or asking more years', not offered(body) & {V['mech'], V['senior'], V['shut'], V['expired'], V['gone']})
card = re.search(r'data-job="%d".*?</section>' % V['chem'], body, re.S)[0]
check('A job shows its pay, per diem and lodging', '42.50' in card and '75.00' in card and 'Lodging provided' in card, card[:600])
m = re.search(r'data-miles="%d">≈ (\d+) mi from you' % V['chem'], body)
check('and how far it is from the worker', m is not None and 20 <= int(m[1]) <= 35, m and m[1])
check('and its questions, the required one marked', 'name="screening[%d]"' % Q in card and 'aria-labelledby="sq-v%d-%d-label"' % (V['chem'], Q) in card and 'required' in card)
body = blank.get(M)[1]
check('A file that says neither trade nor years is not held against the worker', offered(body) == {V['chem'], V['mech'], V['senior'], V['any']}, offered(body))
check('and says what is missing', 'Your trade is not on your file yet.' in body and 'Your years of experience are not on your file yet.' in body)
status, body = banned.get(M)
check('Someone on the do-not-rehire register is offered nothing, and the page does not say why', every(body) == set() and 'id="no-jobs-for-me"' in body and 'rehire' not in body.lower())
count = len(every(w.get(M)[1]))
check('The portal card counts the jobs', 'id="jobs-for-you-card"' in w.get('/portal')[1] and fits(count) in w.get('/portal')[1], count)

# ── I'm interested ─────────────────────────────────────────────────────
check('Interest without the required answer is refused, and nothing is made', w.post({'do': 'interest', 'vacancy_id': V['chem']})[0] == 422 and mine(W) == [])
w.post({'do': 'interest', 'vacancy_id': V['chem'], 'screening[%d]' % Q: 'Yes', 'screening[%d]' % OPT: 'Have my own PPE'})
apps = mine(W)
check('With it, an application from the worker portal, new', len(apps) == 1 and apps[0]['source'] == 'Worker portal' and apps[0]['stage'] == 'new' and int(apps[0]['vacancy_id']) == V['chem'], apps)
APP = int(apps[0]['id'])
check('with its answers kept and scored', {(int(a['question_id']), a['answer']) for a in probe()['answers'] if int(a['application_id']) == APP} == {(Q, 'Yes'), (OPT, 'Have my own PPE')} and apps[0]['screening_score'] is not None)
check('and an event saying where it came from, by the worker', [e for e in probe()['events'] if int(e['application_id']) == APP][-1] == {'application_id': APP, 'user_id': IDS['w'], 'stage': 'new', 'note': 'Interested, from the worker portal'})
body = w.get(M)[1]
check('It moves to "Jobs you are interested in", received, and leaves the list', 'data-interested="%d"' % V['chem'] in body and 'data-stage="new"' in body and V['chem'] not in offered(body))
check('The portal card counts one job fewer', (fits(count - 1) if count > 1 else 'No open job fits your file right now.') in w.get('/portal')[1])
check('Never twice', w.post({'do': 'interest', 'vacancy_id': V['chem'], 'screening[%d]' % Q: 'Yes'})[0] == 422 and len(mine(W)) == 1)
check('Never for a job that does not fit', w.post({'do': 'interest', 'vacancy_id': V['senior']})[0] == 422 and w.post({'do': 'interest', 'vacancy_id': V['mech']})[0] == 422)
check('nor a closed or expired one', w.post({'do': 'interest', 'vacancy_id': V['shut']})[0] == 422 and w.post({'do': 'interest', 'vacancy_id': V['expired']})[0] == 422 and len(mine(W)) == 1)
check('nor for someone on the register, even by a forged form', banned.post({'do': 'interest', 'vacancy_id': V['any']})[0] == 422 and mine(CANDS['banned']) == [])
status, body = rec.get('/hiring?v=%d' % V['chem'])
check('The recruiter sees them in Hiring as a new applicant from the worker portal', 'data-app="%d"' % APP in body and 'Worker portal' in body)

# ── taking it back ─────────────────────────────────────────────────────
w.post({'do': 'withdraw', 'application_id': APP})
check('While new, it can be taken back', mine(W)[0]['stage'] == 'withdrawn' and [e for e in probe()['events'] if int(e['application_id']) == APP][-1]['note'] == 'No longer interested, from the worker portal')
check('and the job is offered again', V['chem'] in offered(w.get(M)[1]))
w.post({'do': 'interest', 'vacancy_id': V['chem'], 'screening[%d]' % Q: 'No'})
check('Interested again: the same application, new again, with the new answer', len(mine(W)) == 1 and mine(W)[0]['stage'] == 'new' and next(a['answer'] for a in probe()['answers'] if int(a['application_id']) == APP and int(a['question_id']) == Q) == 'No')
probe('stage', APP, 'screening')
check('Once a recruiter is working on it, it is not taken back from here', w.post({'do': 'withdraw', 'application_id': APP})[0] == 422 and mine(W)[0]['stage'] == 'screening')
check('and the withdraw button is gone', 'value="withdraw"' not in w.get(M)[1])
check('Nobody takes back someone else\'s application', blank.post({'do': 'withdraw', 'application_id': APP})[0] == 422)

# ── who gets the page; the public form ─────────────────────────────────
status, body = loose.get(M)
check('An account with no crew record is told so', status == 200 and 'Your crew file is not linked yet.' in body and 'id="jobs-for-me"' not in body)
check('A recruiter does not get a worker\'s page', rec.get(M)[0] == 403)
status, body = Client().get('/apply?id=%d' % V['chem'])
check('The public application form still asks the job\'s questions', status == 200 and 'name="screening[%d]"' % Q in body and 'aria-labelledby="sq-%d-label"' % Q in body)

# ── translations ───────────────────────────────────────────────────────
w.post({'locale': 'es', 'back': M}, '/language')
check('Jobs for you in Spanish', 'Empleos para usted' in w.get(M)[1])
w.post({'locale': 'en', 'back': M}, '/language')

json.dump(results, open('work/mj-http-results.json', 'w'), indent=1)
