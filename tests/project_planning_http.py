"""P5-M02 through the screens: subtasks (one level, a parent closes after
them, progress counts the work itself); dependencies (same project, no
self, no loop, a task does not start or finish ahead of what it waits for);
the board and its moves; estimates; optional sprints (one active, closing
returns open tasks to the backlog); weekly capacity less approved time off;
workload per person and week; balancing suggestions and reassignment;
who may see and do what; translations.
"""
import datetime, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/project-planning.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS, A, B = fx['ids'], fx['job_a'], fx['job_b']
NEXT_MON = datetime.date.fromisoformat(fx['next_monday'])
NEXT_WED = (NEXT_MON + datetime.timedelta(days=2)).isoformat()
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
        status, body = self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()))
        assert 'Fatal error' not in body and 'Warning:' not in body, body[:800]
        return status, body


def user(key, project=A):
    c = Client()
    c.get('/login')
    status, body = c.post('/login', {'email': 'pp-%s@test.invalid' % key, 'password': 'TestPassword123!'})
    assert '<title>Sign in' not in body, key
    if project and key != 'worker':
        c.get('/')
        c.post('/select-project', {'job_id': project})
    return c


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:600] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe():
    return json.loads(subprocess.run([php, 'work/project_planning_probe.php'], check=True, capture_output=True, text=True).stdout)


def task(tid):
    return next(t for t in probe()['tasks'] if int(t['id']) == tid)


def last_task():
    return probe()['tasks'][-1]


def events(tid, kind=None):
    return [e for e in probe()['events'] if int(e['task_id']) == tid and (kind is None or e['kind'] == kind)]


rec = user('rec')
rec.get('/project-tasks')
blank = {'do': 'create', 'priority': 'normal', 'due_on': '', 'milestone_id': 0, 'assignee_id': 0, 'description': '', 'estimate_hours': '', 'parent_id': 0, 'sprint_id': 0}


def create(c, **kw):
    status, body = c.post('/project-tasks', {**blank, **kw})
    return status, int(last_task()['id'])


# ── subtasks ────────────────────────────────────────────────────────────
rec.post('/project-tasks', {'do': 'milestone_save', 'title': 'Boiler ready', 'due_on': '2031-01-01'})
M = int(re.search(r'data-milestone="(\d+)"', rec.get('/project-tasks')[1])[1])
status, P = create(rec, title='Mobilize the crew', milestone_id=M, estimate_hours='4')
check('A task is created with an estimate', status == 200 and task(P)['estimate_hours'] == '4.0', task(P))
status, S1 = create(rec, title='Book flights', parent_id=P, assignee_id=IDS['worker'], estimate_hours='6', due_on=NEXT_WED)
status2, S2 = create(rec, title='Book hotel rooms', parent_id=P, assignee_id=IDS['rec'], estimate_hours='2', due_on=NEXT_WED)
check('Subtasks are created under it', status == 200 and status2 == 200 and int(task(S1)['parent_id']) == P and int(task(S2)['parent_id']) == P)
check('and sit under its milestone without being told', int(task(S1)['milestone_id']) == M)
check('A subtask of a subtask is refused: one level only', create(rec, title='Too deep', parent_id=S1)[0] == 422)
check('A parent on another project is refused', create(rec, title='Wrong parent', parent_id=fx['other_task'])[0] == 422)
rec.get('/project-tasks?task=%d' % P)
check('A task with subtasks cannot itself become a subtask', rec.post('/project-tasks', {'do': 'update', 'task_id': P, 'parent_id': S2})[0] == 422)
check('A bad estimate is refused (0, words, over 999)', all(create(rec, title='Bad estimate', estimate_hours=v)[0] == 422 for v in ['0', 'lots', '1000']))
check('The parent does not close while its subtasks are open', rec.post('/project-tasks', {'do': 'update', 'task_id': P, 'status': 'done'})[0] == 422 and task(P)['status'] == 'todo')
check('nor is it cancelled', rec.post('/project-tasks', {'do': 'update', 'task_id': P, 'status': 'cancelled'})[0] == 422)
status, body = rec.get('/project-tasks?task=%d' % P)
check('The parent shows its subtasks', 'id="task-subtasks" data-count="2"' in body and 'data-subtask="%d"' % S1 in body)
p = re.search(r'id="task-progress" data-percent="(\d*)" data-live="(\d+)" data-done="(\d+)"', rec.get('/project-tasks')[1])
check('Progress counts the work itself: the two subtasks, not their parent as a third', p and p.group(2) == '2', p and p.groups())

# ── dependencies ────────────────────────────────────────────────────────
status, D1 = create(rec, title='Order the scaffold', assignee_id=IDS['rec'], estimate_hours='10', due_on=NEXT_WED)
status, D2 = create(rec, title='Erect the scaffold', assignee_id=IDS['worker'], estimate_hours='40', due_on=NEXT_WED)
rec.get('/project-tasks?task=%d' % D2)
status, body = rec.post('/project-tasks', {'do': 'dependency_add', 'task_id': D2, 'depends_on_id': D1})
check('A task is made to wait for another', status == 200 and {'task_id': D2, 'depends_on_id': D1} in probe()['dependencies']
      and events(D2, 'dependency')[-1]['new_value'] == 'Order the scaffold')
check('A task cannot wait for itself', rec.post('/project-tasks', {'do': 'dependency_add', 'task_id': D2, 'depends_on_id': D2})[0] == 422)
check('nor twice for the same one', rec.post('/project-tasks', {'do': 'dependency_add', 'task_id': D2, 'depends_on_id': D1})[0] == 422)
rec.get('/project-tasks?task=%d' % D1)
status, body = rec.post('/project-tasks', {'do': 'dependency_add', 'task_id': D1, 'depends_on_id': D2})
check('A loop is refused', status == 422 and 'loop' in body)
check('nor for a task of another project', rec.post('/project-tasks', {'do': 'dependency_add', 'task_id': D1, 'depends_on_id': fx['other_task']})[0] == 422)
pay = user('pay')
pay.get('/project-tasks?task=%d' % D2)
check('Payroll does not set dependencies', pay.post('/project-tasks', {'do': 'dependency_add', 'task_id': D1, 'depends_on_id': S2})[0] == 422)

worker = user('worker')
worker.get('/my-tasks')
status, body = worker.post('/my-tasks', {'task_id': D2, 'status': 'in_progress'})
check('The worker cannot start a task while what it waits for is open', status == 422 and 'Order the scaffold' in body and task(D2)['status'] == 'todo')
check('nor finish it', worker.post('/my-tasks', {'task_id': D2, 'status': 'done'})[0] == 422)

# ── the board ──────────────────────────────────────────────────────────
status, body = rec.get('/project-board')
cols = dict(re.findall(r'data-column="(\w+)" data-count="(\d+)"', body))
check('The board shows the project in four columns', status == 200 and set(cols) == {'todo', 'in_progress', 'blocked', 'done'} and int(cols['todo']) == 5, cols)
check('A card that waits says so', 'data-card="%d" data-waiting="1"' % D2 in body and 'data-card="%d" data-waiting="0"' % D1 in body)
check('and a parent card shows its subtasks', 'Subtasks: 0 of 2 done' in body)
status, body = rec.post('/project-board', {'do': 'move', 'task_id': D1, 'status': 'in_progress'})
check('Moving a card is a status change, recorded in its history', status == 200 and task(D1)['status'] == 'in_progress' and events(D1, 'status')[-1]['new_value'] == 'in_progress')
check('Blocking from the board needs the reason', rec.post('/project-board', {'do': 'move', 'task_id': D1, 'status': 'blocked', 'blocked_reason': ''})[0] == 422)
rec.post('/project-board', {'do': 'move', 'task_id': D1, 'status': 'blocked', 'blocked_reason': 'Supplier closed until Monday'})
status, body = rec.get('/project-board')
check('and the card shows it in the blocked column', 'data-column="blocked" data-count="1"' in body and 'Supplier closed until Monday' in body)
worker.get('/project-board')
check('A worker does not open the board', worker.final != '/project-board')
rec.post('/project-board', {'do': 'move', 'task_id': D1, 'status': 'done'})
status, body = worker.post('/my-tasks', {'task_id': D2, 'status': 'in_progress'})
check('Once what it waits for is done, the worker starts it', status == 200 and task(D2)['status'] == 'in_progress')
rec.get('/project-tasks?task=%d' % D2)
rec.post('/project-tasks', {'do': 'dependency_remove', 'task_id': D2, 'depends_on_id': D1})
check('A dependency is removed, and that is recorded', not any(d['task_id'] == D2 for d in probe()['dependencies']) and events(D2, 'dependency')[-1]['old_value'] == 'Order the scaffold')
status, body = pay.post('/project-board', {'do': 'move', 'task_id': S1, 'status': 'in_progress'})
check('Someone who neither manages the project nor holds the task cannot move its card', status == 422 and task(S1)['status'] == 'todo')
rec.get('/project-tasks?task=%d' % S2)
rec.post('/project-tasks', {'do': 'update', 'task_id': S2, 'estimate_hours': '3'})
check('An estimate change is recorded with old and new values', events(S2, 'estimate')[-1]['old_value'] == '2.0' and events(S2, 'estimate')[-1]['new_value'] == '3.0')
status, body = rec.get('/project-tasks?task=%d' % S2)
check('and reads in words', 'Estimate: 2 h → 3 h' in body)

# ── sprints ────────────────────────────────────────────────────────────
rec.get('/project-board')
check('A sprint ending before it starts is refused', rec.post('/project-board', {'do': 'sprint_save', 'name': 'Bad', 'starts_on': '2030-02-10', 'ends_on': '2030-02-01', 'goal': ''})[0] == 422)
check('and one longer than 90 days', rec.post('/project-board', {'do': 'sprint_save', 'name': 'Long', 'starts_on': '2030-01-01', 'ends_on': '2030-06-01', 'goal': ''})[0] == 422)
rec.post('/project-board', {'do': 'sprint_save', 'name': 'Sprint 1', 'starts_on': '2030-01-06', 'ends_on': '2030-01-17', 'goal': 'Scaffold up'})
rec.post('/project-board', {'do': 'sprint_save', 'name': 'Sprint 2', 'starts_on': '2030-01-20', 'ends_on': '2030-01-31', 'goal': ''})
sp = [s for s in probe()['sprints'] if int(s['job_id']) == A]
SP1, SP2 = int(sp[0]['id']), int(sp[1]['id'])
check('Two sprints are planned', [s['status'] for s in sp] == ['planned', 'planned'])
rec.post('/project-board', {'do': 'sprint_start', 'sprint_id': SP1})
check('The first starts', next(s for s in probe()['sprints'] if int(s['id']) == SP1)['status'] == 'active')
check('The second cannot start while it is active', rec.post('/project-board', {'do': 'sprint_start', 'sprint_id': SP2})[0] == 422)
for tid in (S1, D2):
    rec.get('/project-tasks?task=%d' % tid)
    rec.post('/project-tasks', {'do': 'update', 'task_id': tid, 'sprint_id': SP1})
check('Tasks are put into the sprint, recorded', int(task(S1)['sprint_id']) == SP1 and events(S1, 'sprint')[-1]['new_value'] == 'Sprint 1')
status, body = rec.get('/project-board?sprint=%d' % SP1)
check('The board for the sprint shows only its tasks', sorted(int(x) for x in re.findall(r'data-card="(\d+)"', body)) == sorted([S1, D2]))
status, body = rec.get('/project-board?sprint=-1')
check('and the backlog the rest', str(S1) not in re.findall(r'data-card="(\d+)"', body) and str(S2) in re.findall(r'data-card="(\d+)"', body))
worker.post('/my-tasks', {'task_id': D2, 'status': 'done'})
rec.get('/project-board')
status, body = rec.post('/project-board', {'do': 'sprint_close', 'sprint_id': SP1})
check('Closing the sprint returns its open task to the backlog', status == 200 and task(S1)['sprint_id'] is None and int(task(D2)['sprint_id']) == SP1
      and next(s for s in probe()['sprints'] if int(s['id']) == SP1)['status'] == 'closed')
check('and says so in the task\'s history', events(S1, 'sprint')[-1]['old_value'] == 'Sprint 1' and events(S1, 'sprint')[-1]['new_value'] is None and 'backlog' in events(S1, 'sprint')[-1]['note'])
status, body = rec.get('/project-board')
check('The closed sprint keeps its result: one task done in it, one sent back to the backlog', re.search(r'data-sprint="%d" data-status="closed" data-live="1" data-done="1" data-returned="1"' % SP1, body) is not None
      and '1 back to the backlog' in body)
rec.get('/project-tasks?task=%d' % S1)
check('A task cannot go into a closed sprint', rec.post('/project-tasks', {'do': 'update', 'task_id': S1, 'sprint_id': SP1})[0] == 422)

# ── capacity, workload, balancing ──────────────────────────────────────
status, D3 = create(rec, title='Inspect the boiler drum', assignee_id=IDS['worker'], estimate_hours='30', due_on=NEXT_WED)
week = NEXT_MON.isoformat()
rec.get('/workload')
check('A capacity over 80 hours is refused', rec.post('/workload', {'do': 'capacity', 'user_id': IDS['rec'], 'hours_per_week': '90'})[0] == 422)
rec.post('/workload', {'do': 'capacity', 'user_id': IDS['rec'], 'hours_per_week': '20'})
check('A recruiter sets someone\'s weekly capacity', any(int(c['user_id']) == IDS['rec'] and c['hours_per_week'] == '20.0' for c in probe()['capacity']))
status, body = rec.get('/workload')
cell = lambda uid: re.search(r'data-person="%d" data-over="(\d)">.*?data-week="%s" data-planned="([\d.]+)" data-capacity="([\d.]+)"' % (uid, week), body, re.S)
w = cell(IDS['worker'])
check('Next week the worker has 36 h planned (6 + 30) against 24 h: 40 less two approved days off (the refused request counts for nothing)', w and w.groups() == ('1', '36', '24'), w and w.groups())
r = cell(IDS['rec'])
check('The recruiter has 3 h against the 20 h set', r and r.groups()[1:] == ('3', '20'), r and r.groups())
check('The worker is flagged over capacity', 'data-person="%d" data-over="1"' % IDS['worker'] in body)
sug = re.search(r'data-suggest="(\d+)" data-to="(\d+)"', body)
check('Balancing suggests moving the worker\'s biggest task to someone on the project with room', sug and int(sug[1]) == D3 and int(sug[2]) not in (IDS['worker'], IDS['rec']), sug and sug.groups())
status, body = rec.post('/workload', {'do': 'reassign', 'task_id': D3, 'assignee_id': sug[2]})
check('Reassigning it is one click, recorded with why', status == 200 and int(task(D3)['assignee_id']) == int(sug[2]) and 'balance the workload' in (events(D3, 'note')[-1]['note'] or ''))
status, body = rec.get('/workload')
check('and the worker is no longer over capacity', 'data-person="%d" data-over="0"' % IDS['worker'] in body)
rec.get('/projects')
rec.post('/select-project', {'job_id': B})
rec.get('/project-tasks')
create(rec, title='Survey the other site', assignee_id=IDS['rec2'], estimate_hours='5', due_on=NEXT_WED)
status, body = rec.get('/workload')
check('A recruiter sees the workload of every project', 'data-person="%d"' % IDS['rec2'] in body)
sup = user('sup', None)
status, body = sup.get('/workload')
check('A supervisor sees only the projects they supervise a crew on', status == 200 and 'data-person="%d"' % IDS['worker'] in body and 'data-person="%d"' % IDS['rec2'] not in body)
check('and does not set capacities', 'name="hours_per_week"' not in body and sup.post('/workload', {'do': 'capacity', 'user_id': IDS['worker'], 'hours_per_week': '10'})[0] == 422)
check('Payroll does not open the workload', pay.get('/workload')[0] == 403)

# ── translations ───────────────────────────────────────────────────────
status, body = rec.get('/workload?lang=fr')
check('Workload in French', 'Charge de travail' in body and 'Équilibrage' in body)
status, body = rec.get('/project-board?lang=es')
check('The board in Spanish', 'Tablero del proyecto' in body and 'Sprints' in body)
rec.get('/workload?lang=en')

json.dump(results, open('work/pp-http-results.json', 'w'), indent=1)
