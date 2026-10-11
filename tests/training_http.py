"""P5-M04 through the screens: courses and their settings; assessment
questions (the key never shown to a learner); assigning by hand; taking a
course, failing and passing, every attempt kept; the certificate a pass
awards, written into the worker's qualifications without ever shortening a
better one; a course without questions confirmed; plans; requirements by
desk role, trade and project, assigned automatically and by the scheduled
workflows; renewals as a completion runs out; the compliance matrix; who
may see and do what; translations.
"""
import datetime, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/training.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS, CANDS = fx['ids'], fx['cands']
TODAY = datetime.date.today()
day = lambda n: (TODAY + datetime.timedelta(days=n)).isoformat()
results = []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None
        self.final = None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                self.final = urllib.parse.urlparse(r.geturl()).path
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

    def post(self, path, values):
        data = urllib.parse.urlencode([('_csrf', self.token)] + (values if isinstance(values, list) else list(values.items())), doseq=True).encode()
        status, body = self.open(urllib.request.Request(base + path, data))
        assert 'Fatal error' not in body and 'Warning:' not in body, body[:800]
        return status, body


def user(key):
    c = Client()
    c.get('/login')
    status, body = c.post('/login', {'email': 'tr-%s@test.invalid' % key, 'password': 'TestPassword123!'})
    assert '<title>Sign in' not in body, key
    return c


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:600] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    out = subprocess.run([php, 'work/training_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def assignment(user_key, course_id):
    rows = [a for a in probe()['assignments'] if int(a['user_id']) == IDS[user_key] and int(a['course_id']) == course_id]
    return rows[-1] if rows else None


rec = user('rec')
rec.get('/training')


def train(values, path='/training'):
    rec.get(path)
    return rec.post('/training', values)


# ── courses ────────────────────────────────────────────────────────────
train({'do': 'course_save', 'title': 'Confined space entry', 'pass_mark': '80', 'is_active': '1'})
CS = int(probe()['courses'][-1]['id'])
settings = {'do': 'course_save', 'course_id': CS, 'title': 'Confined space entry', 'description': 'Entry permits, gas testing, the attendant',
            'content': 'Never enter without a permit and a gas test. The attendant stays outside.', 'resource_url': 'https://example.test/confined',
            'valid_months': '12', 'pass_mark': '75', 'awards_type_id': fx['type'], 'is_active': '1'}
for label, override in [('a title of two letters', {'title': 'CS'}), ('an http link', {'resource_url': 'http://example.test'}), ('a validity of 0 months', {'valid_months': '0'}),
                        ('a pass mark of 101', {'pass_mark': '101'}), ('a qualification that does not exist', {'awards_type_id': '999999'})]:
    check('A course with %s is refused' % label, train({**settings, **override}, '/training?course=%d' % CS)[0] == 422)
train(settings, '/training?course=%d' % CS)
c = next(x for x in probe()['courses'] if int(x['id']) == CS)
check('A course is set up: 12 months, 75% to pass, certifying the qualification', int(c['valid_months']) == 12 and int(c['pass_mark']) == 75 and int(c['awards_type_id']) == fx['type'])

def question(q, options, correct):
    return train([('do', 'question_add'), ('course_id', CS), ('question', q), ('correct', str(correct))] + [('options[]', o) for o in options], '/training?course=%d' % CS)

check('A question with one answer is refused', question('Who stays outside?', ['The attendant', '', '', ''], 0)[0] == 422)
check('and one with the same answer twice', question('Who stays outside?', ['The attendant', 'the attendant', '', ''], 0)[0] == 422)
check('and one whose right answer is not among them', question('Who stays outside?', ['The attendant', 'The entrant', '', ''], 3)[0] == 422)
question('Who stays outside the space?', ['The entrant', 'The attendant', 'Nobody', ''], 1)
question('What comes before entry?', ['A gas test and a permit', 'A coffee', '', ''], 0)
question('Can you enter alone?', ['Yes', 'No', '', ''], 1)
check('Three questions are set', len([q for q in probe()['questions'] if int(q['course_id']) == CS]) == 3)

# ── assigning by hand and taking it ───────────────────────────────────
status, _ = train([('do', 'assign'), ('course_id', CS), ('due_on', day(7)), ('user_ids[]', IDS['worker']), ('user_ids[]', IDS['worker2'])], '/training?course=%d' % CS)
a = assignment('worker', CS)
check('A recruiter assigns the course to two workers, due in a week', status == 200 and a and a['due_on'] == day(7) and a['source'] == 'manual' and assignment('worker2', CS))
check('and each is told, inside Crewvia', any(int(n['user_id']) == IDS['worker'] and n['target'] == '/my-training?take=%s' % a['id'] for n in probe()['notifications']))
check('Assigning it again while it is open makes nothing new', 'made' in train([('do', 'assign'), ('course_id', CS), ('due_on', day(7)), ('user_ids[]', IDS['worker'])], '/training?course=%d' % CS)[1]
      and len([x for x in probe()['assignments'] if int(x['user_id']) == IDS['worker'] and int(x['course_id']) == CS]) == 1)
w = user('worker')
status, body = w.get('/my-training')
check('The worker sees it under My training, due', status == 200 and 'data-assignment="%s" data-status="due"' % a['id'] in body)
status, body = w.get('/my-training?take=%s' % a['id'])
check('The course page shows the content and the questions, never which answer is right', status == 200 and 'Never enter without a permit' in body
      and body.count('data-question=') == 3 and 'right answer' not in body and 'correct' not in body)
qs = [q for q in probe()['questions'] if int(q['course_id']) == CS]
wrong = [('answer[%s]' % q['id'], str((int(q['correct_index']) + 1) % len(json.loads(q['options_json'])))) for q in qs]
right = [('answer[%s]' % q['id'], str(q['correct_index'])) for q in qs]
check('Handing in with a question unanswered is refused', w.post('/my-training', [('assignment_id', a['id'])] + right[:2])[0] == 422)
w.get('/my-training?take=%s' % a['id'])
w.post('/my-training', [('assignment_id', a['id'])] + wrong)
a = assignment('worker', CS)
check('All wrong scores 0: not passed, the attempt kept, the course still open', a['completed_at'] is None
      and [x for x in probe()['attempts'] if int(x['assignment_id']) == int(a['id'])][-1] == {'assignment_id': int(a['id']), 'score': 0, 'passed': 0})
w.get('/my-training?take=%s' % a['id'])
w.post('/my-training', [('assignment_id', a['id'])] + right[:2] + wrong[2:])
check('Two of three is 67%, under the 75% mark: not passed', assignment('worker', CS)['completed_at'] is None and probe()['attempts'][-1]['score'] == 67)
w.get('/my-training?take=%s' % a['id'])
w.post('/my-training', [('assignment_id', a['id'])] + right)
a = assignment('worker', CS)
expected_expiry = '%d-%02d' % (TODAY.year + 1, TODAY.month)
check('All right: passed at 100%, complete, and counting for 12 months', a['completed_at'] and a['score'] == 100 and a['expires_on'] and a['expires_on'][:7] == expected_expiry, a)
qual = next((x for x in probe()['qualifications'] if int(x['candidate_id']) == CANDS['worker']), None)
check('The pass certifies the worker: the qualification is in their record, verified, with the same expiry and the course as evidence',
      qual and qual['status'] == 'verified' and qual['expires_on'] == a['expires_on'] and 'Confined space entry' in qual['evidence_reference'] and int(a['qualification_id']) == int(qual['id']), qual)
check('and its history says it came from the training', any(int(e['qualification_id']) == int(qual['id']) and e['action'] == 'verified' and 'training' in e['note'] for e in probe()['qualification_events']))
check('A course already passed is not handed in again', w.post('/my-training', [('assignment_id', a['id'])] + right)[0] == 422)
check('Nobody hands in someone else\'s training', user('worker3').post('/my-training', [('assignment_id', a['id'])] + right)[0] == 422)
check('nor opens it', user('worker3').get('/my-training?take=%s' % a['id'])[0] == 404)
w2 = user('worker2')
a2 = assignment('worker2', CS)
w2.get('/my-training?take=%s' % a2['id'])
w2.post('/my-training', [('assignment_id', a2['id'])] + right)
q2 = next(x for x in probe()['qualifications'] if int(x['candidate_id']) == CANDS['worker2'])
check('A worker who already holds the certificate for longer keeps it as it was (until 2035, their own card as evidence)',
      assignment('worker2', CS)['completed_at'] and q2['expires_on'] == '2035-01-01' and q2['evidence_reference'] == 'CARD-1' and assignment('worker2', CS)['qualification_id'] is None)

# ── a course without questions ────────────────────────────────────────
train({'do': 'course_save', 'title': 'Site induction', 'pass_mark': '80', 'is_active': '1'})
IND = int(probe()['courses'][-1]['id'])
train({'do': 'course_save', 'course_id': IND, 'title': 'Site induction', 'content': 'Muster point, permits, the radio channel.', 'valid_months': '', 'pass_mark': '80', 'awards_type_id': '0', 'is_active': '1'}, '/training?course=%d' % IND)
train([('do', 'assign'), ('course_id', IND), ('due_on', day(3)), ('user_ids[]', IDS['pay'])], '/training?course=%d' % IND)
p = user('pay')
ai = assignment('pay', IND)
p.get('/my-training?take=%s' % ai['id'])
check('Without questions, the person must confirm they read it', p.post('/my-training', {'assignment_id': ai['id']})[0] == 422)
p.post('/my-training', {'assignment_id': ai['id'], 'confirmed': '1'})
ai = assignment('pay', IND)
check('Confirmed, it is done, for good, and certifies nothing for a member of staff', ai['completed_at'] and ai['expires_on'] is None and ai['score'] is None and ai['qualification_id'] is None)

# ── plans ──────────────────────────────────────────────────────────────
train({'do': 'plan_save', 'name': 'Electrician onboarding', 'is_active': '1'})
status, body = rec.get('/training')
PLAN = int(re.findall(r'data-plan="(\d+)"', body)[-1])
check('A course due 0 days after the plan is refused', train({'do': 'plan_course_add', 'plan_id': PLAN, 'course_id': IND, 'due_days': '0'}, '/training?plan=%d' % PLAN)[0] == 422)
train({'do': 'plan_course_add', 'plan_id': PLAN, 'course_id': IND, 'due_days': '3'}, '/training?plan=%d' % PLAN)
train({'do': 'plan_course_add', 'plan_id': PLAN, 'course_id': CS, 'due_days': '14'}, '/training?plan=%d' % PLAN)
check('and one already in it', train({'do': 'plan_course_add', 'plan_id': PLAN, 'course_id': CS, 'due_days': '7'}, '/training?plan=%d' % PLAN)[0] == 422)
check('A plan holds its courses in order with their days', [(int(x['course_id']), int(x['due_days'])) for x in probe()['plan_courses'] if int(x['plan_id']) == PLAN] == [(IND, 3), (CS, 14)])
check('Giving it to nobody is refused', train({'do': 'plan_assign', 'plan_id': PLAN}, '/training?plan=%d' % PLAN)[0] == 422)
train([('do', 'plan_assign'), ('plan_id', PLAN), ('user_ids[]', IDS['worker3']), ('user_ids[]', IDS['worker'])], '/training?plan=%d' % PLAN)
check('Given to a worker, each course is due its days from today', assignment('worker3', IND)['due_on'] == day(3) and assignment('worker3', CS)['due_on'] == day(14)
      and int(assignment('worker3', CS)['plan_id']) == PLAN)
check('and someone who already passed a course keeps it: only what they lack is assigned', assignment('worker', IND)['due_on'] == day(3)
      and len([x for x in probe()['assignments'] if int(x['user_id']) == IDS['worker'] and int(x['course_id']) == CS]) == 1)

# ── requirements ───────────────────────────────────────────────────────
check('A trade requirement without the trade is refused', train({'do': 'requirement_save', 'course_id': CS, 'scope': 'trade', 'trade': '', 'due_days': '30'})[0] == 422)
check('and a desk role that does not exist', train({'do': 'requirement_save', 'course_id': IND, 'scope': 'staff_role', 'staff_role': 'janitor', 'due_days': '30'})[0] == 422)
train({'do': 'requirement_save', 'course_id': IND, 'scope': 'staff_role', 'staff_role': 'payroll', 'due_days': '10'})
check('Required of payroll: the other payroll clerk is given it at once, due in 10 days; the one who did it is left alone; logistics is not',
      assignment('pay2', IND) and assignment('pay2', IND)['due_on'] == day(10) and assignment('pay2', IND)['source'] == 'requirement'
      and len([x for x in probe()['assignments'] if int(x['user_id']) == IDS['pay'] and int(x['course_id']) == IND]) == 1 and assignment('hotels', IND) is None)
train({'do': 'requirement_save', 'course_id': CS, 'scope': 'trade', 'trade': 'electrician', 'due_days': '20'})
check('Required of the electrician trade (whatever the case): the welder is not given it',
      len([x for x in probe()['assignments'] if int(x['user_id']) == IDS['worker2'] and int(x['course_id']) == CS]) == 1)
train({'do': 'requirement_save', 'course_id': IND, 'scope': 'project', 'job_id': fx['job_b'], 'due_days': '5'})
check('Required on the pier: its worker is given it', assignment('workerb', IND) and assignment('workerb', IND)['due_on'] == day(5))

# ── compliance, expiry, renewal ────────────────────────────────────────
status, body = rec.get('/training')
row = lambda uid, course: re.search(r'data-person="%d" data-course="%d" data-status="(\w+)"' % (uid, course), body)
check('Compliance shows each person against what is required: done, due', row(IDS['pay'], IND)[1] == 'done' and row(IDS['pay2'], IND)[1] == 'due'
      and row(IDS['worker'], CS)[1] == 'done' and row(IDS['worker3'], CS)[1] == 'due')
check('and not the welder, whom the trade requirement does not cover', row(IDS['worker2'], CS) is None)
probe('due', assignment('pay2', IND)['id'], day(-2))
probe('expires', assignment('worker', CS)['id'], day(10))
status, body = rec.get('/training')
check('An assignment past its date is overdue; a completion running out within the month is expiring', row(IDS['pay2'], IND)[1] == 'overdue' and row(IDS['worker'], CS)[1] == 'expiring')
check('The counts at the top agree', re.search(r'data-count="overdue"><div class="n">(\d+)', body)[1] == '1')
train({'do': 'sync'})
renewal = assignment('worker', CS)
check('The sync gives the worker a renewal, due the day the completion runs out', renewal['source'] == 'renewal' and renewal['completed_at'] is None and renewal['due_on'] == day(10))
status, body = rec.get('/training')
check('Compliance still shows the completion as expiring until it runs out', row(IDS['worker'], CS)[1] == 'expiring')
status, body = w.get('/my-training')
check('and the worker sees the renewal to do', 'data-assignment="%s" data-status="due"' % renewal['id'] in body)
status, body = rec.get('/training?status=overdue')
check('Filtered by state', re.findall(r'data-status="(\w+)"', body.split('id="compliance"')[1]) == ['overdue'])
req = next(r for r in probe()['requirements'] if r['scope'] == 'project')
train({'do': 'requirement_stop', 'requirement_id': req['id']})
status, body = rec.get('/training')
check('A requirement stopped leaves the matrix', row(IDS['workerb'], IND) is None)

# ── the scheduled workflows assign too ─────────────────────────────────
train({'do': 'requirement_save', 'course_id': CS, 'scope': 'staff_role', 'staff_role': 'hotels', 'due_days': '15'})
hotels_assigned = assignment('hotels', CS)
check('A new requirement assigns at once (here logistics)', hotels_assigned and hotels_assigned['due_on'] == day(15))
out = subprocess.run([php, 'work/test-app/install/run-workflows.php'], capture_output=True, text=True)
check('The scheduled workflows run the same sync, and nothing is assigned twice', out.returncode == 0
      and len([x for x in probe()['assignments'] if int(x['user_id']) == IDS['hotels'] and int(x['course_id']) == CS]) == 1, out.stdout + out.stderr)

# ── who may see what ───────────────────────────────────────────────────
check('Payroll does not open Training', p.get('/training')[0] == 403)
w.get('/training')
check('A worker does not open Training', w.final != '/training')

# ── translations ───────────────────────────────────────────────────────
status, body = rec.get('/training?lang=fr')
check('Training in French', 'Formation requise' in body and 'Conformité' in body)
status, body = w.get('/my-training?lang=es')
check('My training in Spanish', 'Mi formación' in body)
rec.get('/training?lang=en')
w.get('/my-training?lang=en')

json.dump(results, open('work/tr-http-results.json', 'w'), indent=1)
