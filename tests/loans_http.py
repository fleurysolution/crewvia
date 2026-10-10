"""P2-M05 through the screens and into payroll: an advance and a loan, the
approval controls (never by whoever recorded it, an administrator above
the limit), repayment starting on its first week, the schedule against
what was taken and an advance behind, a pause payroll respects, a
write-off, roles and translations.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
lj = json.load(open('work/loan.json'))
php = os.environ.get('PHP_BIN', 'php')
CID, PID = lj['cid'], lj['pid']
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


def probe(*args):
    out = subprocess.run([php, 'work/loans_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def advances():
    return probe('read', CID)['advances']


def g2n(week):
    return probe('g2n', CID, PID, week)


def act(client, values):
    client.get('/advances')
    return client.post('/advances', values)


people = {}
for key in ('pay1', 'pay2', 'admin', 'rec'):
    people[key] = Client()
    check('P2-M05 %s login' % key, people[key].login('loan-%s@test.invalid' % key)[0] == 200)
    people[key].post('/select-project', {'job_id': lj['job']})
pay1, pay2, admin, rec = (people[k] for k in ('pay1', 'pay2', 'admin', 'rec'))

# ── recording, and the approval controls ────────────────────────────────
check('A first week already past is refused', act(rec, {'do': 'request', 'candidate_id': CID, 'amount': '400', 'weekly_repayment': '100', 'kind': 'advance', 'first_week': lj['ago1']})[0] == 422)
check('A recruiter records a 400 advance, 100 a week from next week', act(rec, {'do': 'request', 'candidate_id': CID, 'amount': '400', 'weekly_repayment': '100', 'kind': 'advance', 'first_week': lj['next_week'], 'reason': 'Flew in Sunday'})[0] == 200
      and advances()[-1]['first_week'] == lj['next_week'] and advances()[-1]['kind'] == 'advance')
adv = advances()[-1]['id']
check('A recruiter cannot approve it', act(rec, {'do': 'approve', 'advance_id': adv})[0] == 403)
check('Payroll approves it: 400 is under the limit', act(pay1, {'do': 'approve', 'advance_id': adv})[0] == 200 and advances()[-1]['status'] == 'approved')
act(pay1, {'do': 'request', 'candidate_id': CID, 'amount': '1500', 'weekly_repayment': '150', 'kind': 'loan', 'reason': 'Tools for the job'})
loan = advances()[-1]['id']
status, body = act(pay1, {'do': 'approve', 'advance_id': loan})
check('Whoever recorded the loan cannot approve it', status == 403 and 'does not approve it' in body, body[:200])
status, body = act(pay2, {'do': 'approve', 'advance_id': loan})
check('Another payroll user cannot either: 1,500 is above the 1,000 limit', status == 403 and 'an administrator approves' in body, body[:200])
check('An administrator approves the loan', act(admin, {'do': 'approve', 'advance_id': loan})[0] == 200 and next(a for a in advances() if a['id'] == loan)['approved_by'] == lj['ids']['admin'])
for a in (adv, loan):
    act(pay1, {'do': 'pay_out', 'advance_id': a})
check('Both are paid out', all(a['status'] == 'paid_out' for a in advances()))

# ── payroll respects the first week ─────────────────────────────────────
check('This week payroll takes only the loan: the advance starts next week', g2n(lj['this_week']) == [[loan, 150.0]], g2n(lj['this_week']))
check('Next week it takes both, oldest first', g2n(lj['next_week']) == [[adv, 100.0], [loan, 150.0]], g2n(lj['next_week']))
body = pay1.get('/advances')[1]
check('The schedule plans four installments of 100 for the advance', re.search(r'data-schedule="%d"><summary>Schedule: 4 installment' % adv, body) is not None)
check('and ten of 150 for the loan', re.search(r'data-schedule="%d"><summary>Schedule: 10 installment' % loan, body) is not None)

# ── an advance behind its schedule ──────────────────────────────────────
probe('backdate', loan, lj['ago2'])
probe('taken', loan, lj['ago2'], 150)
body = pay1.get('/advances')[1]
check('The loan started two weeks ago and payroll took 150 once: 150 behind', re.search(r'data-outstanding="%d" data-behind="150.00"' % loan, body) is not None and '$150.00 behind' in body)
check('The advance, not yet started, is on schedule', re.search(r'data-outstanding="%d" data-behind="0.00"' % adv, body) is not None)

# ── a pause ──────────────────────────────────────────────────────────────
pause = lambda client, frm, until, why: act(client, {'do': 'pause', 'advance_id': loan, 'from_week': frm, 'until_week': until, 'reason': why})
check('A recruiter cannot pause repayment', pause(rec, lj['this_week'], lj['in2'], 'Plant shut')[0] == 403)
check('A pause starting in a past week is refused', pause(pay1, lj['ago1'], lj['in2'], 'Plant shut')[0] == 422)
check('A pause without a reason is refused', pause(pay1, lj['this_week'], lj['in2'], '')[0] == 422)
check('Payroll pauses the loan for three weeks: the plant is shut', pause(pay1, lj['this_week'], lj['in2'], 'Plant shut for repairs')[0] == 200
      and probe('read', CID)['pauses'][-1]['from_week'] == lj['this_week'])
check('An overlapping pause is refused', pause(pay1, lj['next_week'], lj['in3'], 'Again')[0] == 422)
check('Payroll takes nothing for the loan while it is paused, and the advance still runs', g2n(lj['next_week']) == [[adv, 100.0]], g2n(lj['next_week']))
check('and takes the loan again after the pause', [loan, 150.0] in g2n(lj['in3']))
body = pay1.get('/advances')[1]
check('It shows as paused in the outstanding list', re.search(r'data-outstanding="%d"[^>]*>.*?Paused until' % loan, body, re.S) is not None)

# ── a write-off ──────────────────────────────────────────────────────────
check('Payroll cannot write off', act(pay1, {'do': 'write_off', 'advance_id': loan, 'reason': 'Left the agency'})[0] == 403)
check('A write-off without a reason is refused', act(admin, {'do': 'write_off', 'advance_id': loan, 'reason': ''})[0] == 422)
check('An administrator writes off the 1,350 left: the person left owing', act(admin, {'do': 'write_off', 'advance_id': loan, 'reason': 'Left the agency, no forwarding address'})[0] == 200
      and next(a for a in advances() if a['id'] == loan)['status'] == 'written_off' and next(a for a in advances() if a['id'] == loan)['written_off_amount'] == 1350)
check('Payroll no longer takes it', [loan, 150.0] not in g2n(lj['in3']))
body = pay1.get('/advances')[1]
check('It is out of the outstanding list', 'data-outstanding="%d"' % loan not in body)
events = [e['event'] for e in probe('read', CID)['events'] if int(e['advance_id']) == loan]
check('The loan\'s history: recorded, approved, paid out, paused, written off', events == ['requested', 'approved', 'paid_out', 'paused', 'written_off'], events)

status, body = pay1.get('/advances?lang=fr')
check('Loans and advances are translated into French', 'Suspendre le remboursement' in body and 'Prêt' in body)
status, body = pay1.get('/advances?lang=es')
check('and into Spanish', 'Pausar el reembolso' in body)
pay1.get('/advances?lang=en')

json.dump(results, open('work/loan-http-results.json', 'w'), indent=1)
