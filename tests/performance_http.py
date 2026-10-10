"""P2-M03 through the screens: a review cycle opened for everyone in scope
(and nobody twice), goals and their progress record (including the
worker's own account), closing goals, development actions with an owner,
overdue follow-up, who may do what, and translations.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
pj = json.load(open('work/perf.json'))
php = os.environ.get('PHP_BIN', 'php')
ids = pj['ids']
A, B, C, D = pj['a'], pj['b'], pj['c'], pj['d']
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
    out = subprocess.run([php, 'work/performance_probe.php', str(pj['job']), *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def act(client, values, page='/performance'):
    client.get(page)
    return client.post('/performance', values)


people = {}
for key in ('rec', 'sup', 'sup2', 'worker', 'payroll'):
    people[key] = Client()
    check('P2-M03 %s login' % key, people[key].login('perf-%s@test.invalid' % key)[0] == 200)
rec, sup, sup2, worker, payroll = (people[k] for k in ('rec', 'sup', 'sup2', 'worker', 'payroll'))
check('Payroll cannot see goals and development', payroll.get('/performance')[0] == 403)

# ── a review cycle ───────────────────────────────────────────────────────
cycle = {'do': 'cycle_create', 'name': 'Fourth-quarter reviews', 'template_id': pj['template'], 'job_id': pj['job'], 'period_from': pj['ago30'], 'period_to': pj['today'], 'due_on': pj['in30']}
check('A cycle due before its period ends is refused', act(rec, {**cycle, 'due_on': pj['yesterday']})[0] == 422)
check('A supervisor cannot create a cycle', act(sup, cycle)[0] == 403)
check('A recruiter creates the fourth-quarter cycle for the project', act(rec, cycle)[0] == 200 and probe()['cycles'][-1]['status'] == 'planned')
cid = probe()['cycles'][-1]['id']
status, body = act(rec, {'do': 'cycle_open', 'cycle_id': cid})
reviews = [r for r in probe()['reviews'] if r['cycle_id'] == cid]
check('Opening it opens a review for each live assignment with a supervisor: Alpha and Bravo, not the finished Delta',
      status == 200 and sorted(r['candidate_id'] for r in reviews) == sorted([A['cid'], B['cid']]) and all(r['reviewer_id'] == ids['sup'] for r in reviews), reviews)
check('It says who it could not open, and why: Charlie has no supervisor', 'Perf charlie' in body and 'no supervisor' in body)
act(rec, {'do': 'cycle_open', 'cycle_id': cid})
check('Opening it again opens nobody twice', len([r for r in probe()['reviews'] if r['cycle_id'] == cid]) == 2)
check('Closing it with reviews unfinished and no reason is refused', act(rec, {'do': 'cycle_close', 'cycle_id': cid, 'note': ''})[0] == 422)

# ── goals and the progress record ───────────────────────────────────────
review_a = next(r['id'] for r in reviews if r['candidate_id'] == A['cid'])
goal = {'do': 'goal_add', 'candidate_id': A['cid'], 'title': 'Lead a crew of four', 'measure': 'Runs the night shift with no safety incident', 'target_on': pj['in60'], 'appraisal_id': review_a}
check('A goal due in the past is refused', act(rec, {**goal, 'target_on': pj['yesterday']})[0] == 422)
check('A goal with no measure is refused', act(rec, {**goal, 'measure': ''})[0] == 422)
check('A recruiter sets Alpha a goal from the review', act(rec, goal, '/performance?candidate=%d' % A['cid'])[0] == 200
      and probe()['goals'][-1]['title'] == 'Lead a crew of four' and probe()['goals'][-1]['appraisal_id'] == review_a)
check('Alpha is told', any(n['user_id'] == ids['worker'] and 'Lead a crew of four' in n['message'] for n in probe()['notified']))
ga = probe()['goals'][-1]['id']
check('The supervisor of another crew cannot set Bravo a goal', act(sup2, {**goal, 'candidate_id': B['cid'], 'appraisal_id': 0})[0] == 403)
check('Bravo\'s supervisor can', act(sup, {**goal, 'candidate_id': B['cid'], 'title': 'Pass the rigging assessment', 'appraisal_id': 0})[0] == 200)
gb = probe()['goals'][-1]['id']

update = lambda client, gid, progress, note: act(client, {'do': 'goal_update', 'goal_id': gid, 'candidate_id': 0, 'progress': progress, 'note': note})
check('Alpha reports on their own goal: 40 %, marked as their own account', update(worker, ga, '40', 'Ran two night shifts')[0] == 200
      and probe()['updates'][-1] == {'goal_id': ga, 'progress': 40, 'note': 'Ran two night shifts', 'by_self': 1})
check('Alpha cannot report on Bravo\'s goal', update(worker, gb, '50', 'Not mine')[0] == 403)
check('Progress above 100 is refused', update(sup, ga, '120', 'Too much')[0] == 422)
check('Progress without a note is refused', update(sup, ga, '60', '')[0] == 422)
check('The supervisor records 60 %', update(sup, ga, '60', 'Led the crew all week')[0] == 200
      and next(g for g in probe()['goals'] if g['id'] == ga)['progress'] == 60 and probe()['updates'][-1]['by_self'] == 0)
check('Alpha cannot close the goal', act(worker, {'do': 'goal_close', 'goal_id': ga, 'candidate_id': 0, 'status': 'achieved', 'note': 'Done'})[0] == 403)
check('The supervisor closes it achieved: progress 100', act(sup, {'do': 'goal_close', 'goal_id': ga, 'candidate_id': A['cid'], 'status': 'achieved', 'note': 'No incident in six weeks'})[0] == 200
      and (lambda g: g['status'] == 'achieved' and g['progress'] == 100)(next(g for g in probe()['goals'] if g['id'] == ga)))
check('A closed goal takes no more progress', update(sup, ga, '100', 'Again')[0] == 422)

# ── the development plan ─────────────────────────────────────────────────
action = {'do': 'action_add', 'candidate_id': A['cid'], 'kind': 'training', 'description': 'Confined-space entry course', 'owner_id': ids['sup'], 'due_on': pj['in30'], 'appraisal_id': review_a}
check('An owner who is not a recruiter or a supervisor is refused', act(rec, {**action, 'owner_id': ids['payroll']})[0] == 422)
check('An action due in the past is refused', act(rec, {**action, 'due_on': pj['yesterday']})[0] == 422)
check('A recruiter plans a course, followed up by the supervisor, who is told', act(rec, action)[0] == 200 and probe()['actions'][-1]['status'] == 'open'
      and any(n['user_id'] == ids['sup'] and 'Confined-space entry course' in n['message'] for n in probe()['notified']))
aid = probe()['actions'][-1]['id']
probe('overdue', aid)
status, body = rec.get('/performance')
check('Its date passed: it is in the recruiter\'s overdue list', 'data-overdue-action="%d"' % aid in body)
status, body = sup.get('/performance')
check('and in the supervisor\'s list to follow up, marked overdue', re.search(r'data-action="%d" data-action-status="open" data-overdue="1"' % aid, body) is not None)
check('Done without saying what came of it is refused', act(sup, {'do': 'action_close', 'action_id': aid, 'candidate_id': A['cid'], 'as': 'done', 'outcome': ''})[0] == 422)
check('The supervisor marks it done', act(sup, {'do': 'action_close', 'action_id': aid, 'candidate_id': A['cid'], 'as': 'done', 'outcome': 'Passed, card valid two years'})[0] == 200
      and probe()['actions'][-1]['status'] == 'done' and 'data-overdue-action="%d"' % aid not in rec.get('/performance')[1])

# ── who sees what ────────────────────────────────────────────────────────
status, body = worker.get('/performance?candidate=%d' % B['cid'])
check('A worker sees only their own goals, whatever they ask for', status == 200 and 'data-goal="%d"' % ga in body and 'data-goal="%d"' % gb not in body)
check('and neither the goal form nor the progress record', 'id="goal-form"' not in body and 'id="progress-record"' not in body)
check('The supervisor of another crew cannot open Alpha', sup2.get('/performance?candidate=%d' % A['cid'])[0] == 404)
status, body = rec.get('/performance?candidate=%d' % A['cid'])
record = re.findall(r'<strong>([^<]+)</strong>', body.split('id="progress-record"')[1]) if 'id="progress-record"' in body else []
check('The progress record holds the goal, both updates, its close and the action', {'Goal set', 'Progress, own account', 'Progress', 'Goal achieved', 'Development action planned', 'Development action done'} <= set(record), record)
status, body = rec.get('/employee-folder?id=%d' % A['cid'])
check('The employee folder shows it too', 'id="progress-summary"' in body and 'Development action done' in body)
status, body = rec.get('/appraisals?id=%d' % review_a)
check('The review links to the person\'s goals and plan', '/performance?candidate=%d&amp;appraisal=%d' % (A['cid'], review_a) in body)
check('The cycle closes with a reason while reviews are unfinished', act(rec, {'do': 'cycle_close', 'cycle_id': cid, 'note': 'Year end; the rest move to January'})[0] == 200
      and probe()['cycles'][-1]['status'] == 'closed')
check('A closed cycle does not open again', act(rec, {'do': 'cycle_open', 'cycle_id': cid})[0] == 422)

status, body = rec.get('/performance?lang=fr')
check('Goals and development is translated into French', 'Objectifs et développement' in body and 'Nouveau cycle' in body)
status, body = rec.get('/performance?lang=es')
check('and into Spanish', 'Ciclos de evaluación' in body)
rec.get('/performance?lang=en')

json.dump(results, open('work/perf-http-results.json', 'w'), indent=1)
