"""P2-M06 through the screens: recognition by a supervisor for their crew,
HR access granted and revoked by an administrator and logged, a case
reported by a supervisor, decided by HR, answered and acknowledged by the
worker, closed; the rules on who decides what; separations, the register,
and who cannot see any of it.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
hj = json.load(open('work/hrr.json'))
php = os.environ.get('PHP_BIN', 'php')
ids = hj['ids']
A, B, C = hj['a'], hj['b'], hj['c']
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


def probe():
    return json.loads(subprocess.run([php, 'work/hr_records_probe.php', str(hj['job'])], check=True, capture_output=True, text=True).stdout)


def act(client, values, page='/hr-records'):
    client.get(page)
    return client.post('/hr-records', values)


people = {}
for key in ('admin', 'rec', 'rec2', 'payroll', 'sup', 'sup2', 'worker'):
    people[key] = Client()
    check('P2-M06 %s login' % key, people[key].login('hrr-%s@test.invalid' % key)[0] == 200)
admin, rec, rec2, payroll, sup, sup2, worker = (people[k] for k in ('admin', 'rec', 'rec2', 'payroll', 'sup', 'sup2', 'worker'))
check('Payroll without HR access cannot open HR records', payroll.get('/hr-records')[0] == 403)

# ── recognition ──────────────────────────────────────────────────────────
recog = {'do': 'recognise', 'candidate_id': A['cid'], 'kind': 'safety', 'title': '1,000 hours without a recordable incident', 'awarded_on': hj['today']}
check('A recognition dated tomorrow is refused', act(sup, {**recog, 'awarded_on': hj['tomorrow']})[0] == 422)
check('The supervisor of another crew cannot recognise Alpha', act(sup2, recog)[0] == 403)
check('Alpha\'s supervisor recognises them; they are told', act(sup, recog)[0] == 200 and probe()['recognitions'][-1]['kind'] == 'safety'
      and any(n['user_id'] == ids['worker'] and '1,000 hours' in n['message'] for n in probe()['notified']))
status, body = worker.get('/hr-records')
check('Alpha sees it on their own record', status == 200 and 'My HR record' in body and '1,000 hours without a recordable incident' in body)

# ── HR access ────────────────────────────────────────────────────────────
check('A recruiter cannot grant HR access', act(rec, {'do': 'grant', 'user_id': ids['rec'], 'reason': 'Me'})[0] == 403)
check('A grant without a reason is refused', act(admin, {'do': 'grant', 'user_id': ids['rec'], 'reason': ''})[0] == 422)
check('An administrator grants the first recruiter HR access, with the reason', act(admin, {'do': 'grant', 'user_id': ids['rec'], 'reason': 'Handles the crew\'s HR matters'})[0] == 200
      and ids['rec'] in [g['user_id'] for g in probe()['grants']] and probe()['log'][-1]['action'] == 'granted HR access')

# ── a case ───────────────────────────────────────────────────────────────
case = {'do': 'case_open', 'candidate_id': A['cid'], 'category': 'safety', 'incident_on': hj['yesterday'], 'safety_incident_id': hj['incident'],
        'facts': 'Left a ladder unsecured on the pipe rack after the shift; seen by the night foreman at 22:10.'}
check('A recruiter without HR access cannot open a case', act(rec2, case)[0] == 403)
check('A case with the facts in a word is refused', act(sup, {**case, 'facts': 'Ladder'})[0] == 422)
check('Alpha\'s supervisor reports it, tied to the safety incident', act(sup, case, '/hr-records?candidate=%d' % A['cid'])[0] == 200
      and probe()['cases'][-1]['status'] == 'open' and probe()['cases'][-1]['safety_incident_id'] == hj['incident'])
k = probe()['cases'][-1]
check('It has a reference', re.match(r'HR-\d{4}-\d{4}$', k['reference']) is not None, k['reference'])
check('The supervisor who reported it can read it', sup.get('/hr-records?case=%d' % k['id'])[0] == 200)
check('but cannot add to it', act(sup, {'do': 'case_note', 'case_id': k['id'], 'note': 'More'})[0] == 403)
for label, client in [('Another supervisor cannot see it', sup2), ('A recruiter without HR access cannot see it', rec2), ('Alpha cannot see it while it is open', worker)]:
    check(label, client.get('/hr-records?case=%d' % k['id'])[0] == 404)
status, body = rec.get('/hr-records?case=%d' % k['id'])
check('The recruiter with HR access reads it, and that is logged', status == 200 and 'Left a ladder unsecured' in body
      and any(l['user_id'] == ids['rec'] and l['action'] == 'opened a case' for l in probe()['log']))
check('A note is added', act(rec, {'do': 'case_note', 'case_id': k['id'], 'note': 'Spoke to the night foreman; confirmed.'})[0] == 200)
check('Only an administrator decides a termination', act(rec, {'do': 'case_decide', 'case_id': k['id'], 'outcome': 'termination', 'note': 'Serious breach of the safety plan'})[0] == 403)
check('An outcome without its explanation is refused', act(rec, {'do': 'case_decide', 'case_id': k['id'], 'outcome': 'written_warning', 'note': 'No'})[0] == 422)
check('HR gives a written warning; Alpha is told', act(rec, {'do': 'case_decide', 'case_id': k['id'], 'outcome': 'written_warning', 'note': 'First safety breach; retrained on ladder tie-off.'})[0] == 200
      and probe()['cases'][-1]['outcome'] == 'written_warning' and any(n['user_id'] == ids['worker'] and 'outcome' in n['message'] for n in probe()['notified']))
check('The facts are as they were opened', probe()['cases'][-1]['facts'] == case['facts'])

# ── the person's account ────────────────────────────────────────────────
status, body = worker.get('/hr-records?case=%d' % k['id'])
check('Alpha now reads the outcome and can answer', status == 200 and 'Written warning' in body and 'id="respond-form"' in body)
check('Alpha gives their account and acknowledges it', act(worker, {'do': 'case_respond', 'case_id': k['id'], 'response': 'The tie-off point was taken by the scaffold crew.', 'acknowledge': '1'})[0] == 200
      and probe()['cases'][-1]['response'] == 'The tie-off point was taken by the scaffold crew.' and probe()['cases'][-1]['acknowledged'] == 1)
check('Their account is not rewritten', act(worker, {'do': 'case_respond', 'case_id': k['id'], 'response': 'Changed my mind'})[0] == 422)
check('HR closes the case; nothing more is added', act(rec, {'do': 'case_close', 'case_id': k['id'], 'note': 'Warning issued and acknowledged'})[0] == 200
      and act(rec, {'do': 'case_note', 'case_id': k['id'], 'note': 'Late note'})[0] == 422)
check('The record reads: opened, note, decided, response, acknowledged, closed',
      [n['kind'] for n in probe()['notes'] if n['case_id'] == k['id']] == ['opened', 'note', 'decided', 'response', 'acknowledged', 'closed'])

# ── a termination, and separations ──────────────────────────────────────
act(rec, {**case, 'candidate_id': C['cid'], 'category': 'conduct', 'safety_incident_id': 0, 'facts': 'Threatened a coworker on the platform; two witnesses gave statements.'}, '/hr-records?candidate=%d' % C['cid'])
k2 = probe()['cases'][-1]
check('Whoever opened a case does not decide a final warning on it', act(rec, {'do': 'case_decide', 'case_id': k2['id'], 'outcome': 'final_warning', 'note': 'Serious misconduct, last chance.'})[0] == 422)
check('An administrator decides the termination', act(admin, {'do': 'case_decide', 'case_id': k2['id'], 'outcome': 'termination', 'note': 'Threats of violence; witnessed twice.'})[0] == 200)
sep = lambda placement, reason, rehire, detail, case_id=0: act(rec, {'do': 'separation', 'placement_id': placement, 'reason': reason, 'separated_on': hj['yesterday'], 'rehire': rehire, 'detail': detail, 'case_id': case_id})
check('A live assignment has no separation yet', sep(A['pid'], 'resigned', 'eligible', 'Moving to another state')[0] == 422)
check('Bravo resigned, fine to call again', sep(B['pid'], 'resigned', 'eligible', 'Took a job closer to home')[0] == 200
      and probe()['separations'][-1] == {'placement_id': B['pid'], 'reason': 'resigned', 'voluntary': 1, 'rehire': 'eligible', 'case_id': None})
check('An assignment\'s separation is recorded once', sep(B['pid'], 'other', 'review', 'Again and again')[0] == 422)
check('A termination without the case that decided it is refused', sep(C['pid'], 'terminated', 'ineligible', 'Threats on the platform')[0] == 422)
check('Charlie\'s termination is recorded with its case, not to be rehired', sep(C['pid'], 'terminated', 'ineligible', 'Threats on the platform, see the case', k2['id'])[0] == 200
      and probe()['separations'][-1]['case_id'] == k2['id'] and probe()['separations'][-1]['voluntary'] == 0)
reg = next(r for r in probe()['register'] if int(r['candidate_id']) == C['cid'])
check('Charlie is on the do-not-rehire register, with the reason', reg['rehire_status'] == 'ineligible' and 'Terminated' in reg['exclusion_reason'])
check('A recruiter without HR access does not see separations', 'id="separations"' not in rec2.get('/hr-records?candidate=%d' % C['cid'])[1])

# ── revoking ─────────────────────────────────────────────────────────────
check('An administrator revokes the recruiter\'s HR access', act(admin, {'do': 'revoke', 'user_id': ids['rec'], 'reason': 'Moved to recruiting only'})[0] == 200)
check('After that, the case is closed to them', rec.get('/hr-records?case=%d' % k['id'])[0] == 404)
status, body = admin.get('/hr-records')
check('The administrator sees who opened what, the grant and the revocation', 'data-log="opened the HR record"' in body and 'data-log="granted HR access"' in body and 'data-log="revoked HR access"' in body)

status, body = admin.get('/hr-records?lang=fr')
check('HR records is translated into French', 'Dossiers RH' in body and 'Accès RH' in body)
status, body = admin.get('/hr-records?lang=es')
check('and into Spanish', 'Expedientes de RR. HH.' in body)
admin.get('/hr-records?lang=en')

json.dump(results, open('work/hrr-http-results.json', 'w'), indent=1)
