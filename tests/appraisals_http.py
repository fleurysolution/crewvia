"""P2-M02 through the screens: templates, weights, scale and thresholds;
opening a review; the worker's own view; the reviewer's scores and the
weighted grade; return and approval by somebody else; who sees what; the
roster review and the employment history written on approval.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
ap = json.load(open('work/appr.json'))
php = os.environ.get('PHP_BIN', 'php')
ids = ap['ids']
results = []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def _send(self, req):
        try:
            with self.opener.open(req) as r:
                body, status, final = r.read().decode(), r.status, urllib.parse.urlparse(r.geturl()).path
        except urllib.error.HTTPError as e:
            body, status, final = e.read().decode(), e.code, None
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        self.final = final
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
    return json.loads(subprocess.run([php, 'work/appraisals_probe.php', str(ap['job']), str(ids['worker'])], check=True, capture_output=True, text=True).stdout)


def appraisal(aid):
    return next(a for a in probe()['appraisals'] if a['id'] == aid)


def tpl(values):
    admin.get('/appraisal-templates')
    return admin.post('/appraisal-templates', {'do': 'save', **values})


def act(client, aid, values):
    client.get('/appraisals?id=%d' % aid)
    return client.post('/appraisals', {'appraisal_id': aid, **values})


def scores(prefix, values, comments=None):
    out = {'%s[%s]' % ('score', k): v for k, v in values.items()}
    out.update({'score_comment[%s]' % k: v for k, v in (comments or {}).items()})
    return out


def open_review(client, placement, template, reviewer=0):
    client.get('/appraisals')
    return client.post('/appraisals', {'do': 'open', 'placement_id': placement, 'template_id': template, 'reviewer_id': reviewer})


people = {}
for key in ('admin', 'rec1', 'rec2', 'payroll', 'sup', 'sup2', 'worker', 'worker2'):
    people[key] = Client()
    check('P2-M02 %s login' % key, people[key].login('appr-%s@test.invalid' % key)[0] == 200)
admin, rec1, rec2, payroll, sup, sup2, worker, worker2 = (people[k] for k in ('admin', 'rec1', 'rec2', 'payroll', 'sup', 'sup2', 'worker', 'worker2'))
for c in (admin, rec1, rec2):
    c.post('/select-project', {'job_id': ap['job']})

# ── templates ────────────────────────────────────────────────────────────
check('A recruiter cannot manage templates', rec1.get('/appraisal-templates')[0] == 403)
check('Payroll cannot open performance reviews', payroll.get('/appraisals')[0] == 403)
base_tpl = {'label': 'Probation check', 'kind': 'probation', 'scale_max': '10', 'grade_a': '90', 'grade_b': '80', 'grade_c': '70', 'grade_d': '60',
            'weight[workmanship]': '3', 'weight[safety]': '2', 'weight[timekeeping]': '0', 'weight[instructions]': '0', 'weight[teamwork]': '0'}
for label, change in [('A scale of 1 to 2 is refused', {'scale_max': '2'}),
                      ('Thresholds out of order are refused', {'grade_b': '95'}),
                      ('A template with no weighted criterion is refused', {'weight[workmanship]': '0', 'weight[safety]': '0'}),
                      ('A weight over 10 is refused', {'weight[safety]': '11'})]:
    status, body = tpl({**base_tpl, **change})
    check(label, status == 422, (status, body[:200]))
status, _ = tpl(base_tpl)
probation = next((t for t in probe()['templates'] if t['code'] == 'probation_check'), None)
check('An administrator creates Probation check: 1-10, no self-review, workmanship x3 and safety x2',
      status == 200 and probation and int(probation['scale_max']) == 10 and int(probation['self_review']) == 0 and int(probation['criteria']) == 2, probation)
probation = int(probation['id'])
admin.get('/appraisal-templates')
status, _ = admin.post('/appraisal-templates', {'do': 'criterion', 'label': 'Kept the work area clean'})
check('A criterion is added to the shared catalogue', status == 200 and any(c['slug'] == 'kept_the_work_area_clean' for c in probe()['criteria']))
check('The same criterion twice is refused', admin.post('/appraisal-templates', {'do': 'criterion', 'label': 'Kept the work area clean'})[0] == 422)

# ── opening ──────────────────────────────────────────────────────────────
status, _ = open_review(rec1, ap['place_a'], ap['template'])
a = probe()['appraisals'][-1]
check('A review of somebody with an account starts with their own view, reviewed by the assignment\'s supervisor',
      status == 200 and a['status'] == 'self_review' and a['reviewer_id'] == ids['sup'], a)
A = a['id']
check('The same review twice on the assignment is refused', open_review(rec1, ap['place_a'], ap['template'])[0] == 422)
check('The worker is told their review is ready', any('ready for your own view' in n['message'] for n in probe()['notified']))
check('No reviewer and no supervisor on the assignment is refused', open_review(rec1, ap['place_b'], probation)[0] == 422)
status, _ = open_review(rec1, ap['place_b'], probation, ids['rec1'])
b = probe()['appraisals'][-1]
check('Without self-review it goes straight to the reviewer', status == 200 and b['status'] == 'supervisor_review' and b['reviewer_id'] == ids['rec1'], b)
B = b['id']

# ── the worker's own view ────────────────────────────────────────────────
status, body = worker.get('/appraisals')
check('The worker sees their review in their list', status == 200 and 'data-appraisal="%d"' % A in body)
status, body = worker.get('/appraisals?id=%d' % A)
check('The worker sees the form and not the history', status == 200 and 'id="appraisal-form"' in body and 'id="appraisal-history"' not in body)
check('Another worker cannot open it', worker2.get('/appraisals?id=%d' % A)[0] == 404)
check('The supervisor cannot score before the worker has given their view',
      act(sup, A, {'do': 'score', **scores('', {'workmanship': 4, 'timekeeping': 5, 'safety': 3, 'instructions': 4, 'teamwork': 5}), 'would_rehire': '1'})[0] == 422)
check('A self-review missing a criterion is refused', act(worker, A, {'do': 'self', **scores('', {'workmanship': 5, 'timekeeping': 5})})[0] == 422)
status, _ = act(worker, A, {'do': 'self', **scores('', {'workmanship': 5, 'timekeeping': 4, 'safety': 5, 'instructions': 5, 'teamwork': 4}), 'comment': 'Ran the fit-up crew in week two'})
check('The worker sends their view; it goes to the supervisor', status == 200 and appraisal(A)['status'] == 'supervisor_review'
      and len([s for s in probe()['scores'] if s['appraisal_id'] == A and s['rater'] == 'self']) == 5)
check('The worker can no longer open it while it is with the supervisor', worker.get('/appraisals?id=%d' % A)[0] == 404)

# ── the reviewer ─────────────────────────────────────────────────────────
check('Another supervisor cannot open it', sup2.get('/appraisals?id=%d' % A)[0] == 404)
status, body = sup.get('/appraisals?id=%d' % A)
check('The supervisor sees the worker\'s view beside the form', status == 200 and 'Ran the fit-up crew in week two' in body and 'id="appraisal-form"' in body)
good = {'workmanship': 4, 'timekeeping': 5, 'safety': 3, 'instructions': 4, 'teamwork': 5}
check('A score of 1 without a comment is refused', act(sup, A, {'do': 'score', **scores('', {**good, 'safety': 1}), 'would_rehire': '1'})[0] == 422)
check('A score above the scale is refused', act(sup, A, {'do': 'score', **scores('', {**good, 'safety': 6}), 'would_rehire': '1'})[0] == 422)
check('"Would not rehire" without a reason is refused', act(sup, A, {'do': 'score', **scores('', good), 'would_rehire': '0', 'comment': ''})[0] == 422)
status, _ = act(sup, A, {'do': 'score', **scores('', good), 'would_rehire': '1', 'comment': 'Reliable, needs to slow down near live lines'})
a = appraisal(A)
check('Weighted: (0.8 + 1 + 0.6x2 + 0.8 + 1) / 6 = 80.00%, a B, awaiting approval',
      status == 200 and a['status'] == 'awaiting_approval' and float(a['score_percent']) == 80 and a['grade'] == 'B' and a['supervisor_by'] == ids['sup'], a)
check('A supervisor cannot approve', act(sup, A, {'do': 'decide', 'decision': 'approve'})[0] == 403)
check('The worker still cannot see the draft', worker.get('/appraisals?id=%d' % A)[0] == 404)

# ── return and approval ──────────────────────────────────────────────────
check('Returning without a note is refused', act(rec2, A, {'do': 'decide', 'decision': 'return', 'note': ''})[0] == 422)
status, _ = act(rec2, A, {'do': 'decide', 'decision': 'return', 'note': 'Safety was signed off clean on every JSA'})
check('Returned with a note, it is back with the supervisor', status == 200 and appraisal(A)['status'] == 'supervisor_review')
status, body = sup.get('/appraisals?id=%d' % A)
check('The supervisor sees why it came back', 'Safety was signed off clean on every JSA' in body)
act(sup, A, {'do': 'score', **scores('', {**good, 'safety': 5}), 'would_rehire': '1', 'comment': 'Reliable'})
a = appraisal(A)
check('Rescored: 5.6 / 6 = 93.33%, an A', float(a['score_percent']) == 93.33 and a['grade'] == 'A', a)
status, _ = act(rec2, A, {'do': 'decide', 'decision': 'approve', 'note': ''})
a = appraisal(A)
check('A second recruiter approves it', status == 200 and a['status'] == 'approved' and a['decided_by'] == ids['rec2'], a)
r = next((x for x in probe()['reviews'] if x['placement_id'] == ap['place_a']), None)
check('It becomes the roster\'s review of the assignment: A, would rehire, five scores', r and r['grade'] == 'A' and int(r['would_rehire']) == 1 and int(r['scores']) == 5, r)
check('The worker is told it is approved', any('approved' in n['message'] for n in probe()['notified']))
status, body = worker.get('/appraisals?id=%d' % A)
check('The worker now sees the result and the reviewer\'s marks', status == 200 and 'data-grade="A"' in body and 'data-score="5"' in body and 'id="appraisal-form"' not in body)
check('An approved review cannot be scored again', act(sup, A, {'do': 'score', **scores('', good), 'would_rehire': '1'})[0] == 422)
check('An approved review cannot be cancelled', act(rec1, A, {'do': 'cancel', 'reason': 'Changed my mind'})[0] == 422)
check('Its history reads: opened, self-review, scored, returned, scored, approved',
      [e['event'] for e in probe()['events'] if e['appraisal_id'] == A] == ['opened', 'self_submitted', 'scored', 'returned', 'scored', 'approved'])

# ── the reviewer cannot approve their own ────────────────────────────────
act(rec1, B, {'do': 'score', **scores('', {'workmanship': 7, 'safety': 9}), 'would_rehire': '0', 'comment': 'Walked off site twice'})
b = appraisal(B)
check('On 1-10: (3x0.7 + 2x0.9) / 5 = 78.00%, a C', b['status'] == 'awaiting_approval' and float(b['score_percent']) == 78 and b['grade'] == 'C', b)
status, body = act(rec1, B, {'do': 'decide', 'decision': 'approve'})
check('The reviewer cannot approve their own review', status == 422 and 'other than the reviewer' in body, (status, body[:200]))
check('Somebody else approves it', act(rec2, B, {'do': 'decide', 'decision': 'approve'})[0] == 200 and appraisal(B)['status'] == 'approved')
check('A probation review does not touch the roster\'s review', not any(x['placement_id'] == ap['place_b'] for x in probe()['reviews']))

# ── templates in use, retiring, cancelling ───────────────────────────────
status, body = tpl({**base_tpl, 'template_id': ap['template'], 'label': 'End of assignment'})
check('A template already used cannot change', status == 422 and 'cannot change' in body, (status, body[:200]))
admin.get('/appraisal-templates')
admin.post('/appraisal-templates', {'do': 'retire', 'template_id': probation})
check('A retired template cannot be used for a new review', open_review(rec1, ap['place_c'], probation, ids['sup2'])[0] == 422)
status, _ = open_review(rec1, ap['place_c'], ap['template'])
C = probe()['appraisals'][-1]['id']
check('Cancelling without a reason is refused', act(rec1, C, {'do': 'cancel', 'reason': ''})[0] == 422)
check('Cancelled with a reason', act(rec1, C, {'do': 'cancel', 'reason': 'Opened on the wrong person'})[0] == 200 and appraisal(C)['status'] == 'cancelled')
check('A cancelled review is gone from the worker\'s list', 'data-appraisal="%d"' % C not in worker2.get('/appraisals')[1])

# ── history and translations ─────────────────────────────────────────────
status, body = rec1.get('/employee-folder?id=%d' % ap['cand_a'])
check('The employment history shows the approved review', 'Performance review' in body and '93.3% · A' in body)
status, body = sup.get('/appraisals')
check('The supervisor\'s list holds their review and not the other crew\'s', 'data-appraisal="%d"' % A in body and 'data-appraisal="%d"' % C not in body)
status, body = rec1.get('/appraisals?lang=fr')
check('Performance reviews is translated into French', 'Évaluations de performance' in body)
status, body = admin.get('/appraisal-templates?lang=es')
check('Appraisal templates is translated into Spanish', 'Plantillas de evaluación' in body)
for c in (rec1, admin):
    c.get('/appraisals?lang=en')

json.dump(results, open('work/appr-http-results.json', 'w'), indent=1)
