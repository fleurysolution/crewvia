"""P5-M01 through the screens: milestones; tasks with assignees, priorities,
deadlines and statuses; who may create, change and move them (managers,
the supervisor of a crew, the person assigned, a worker on the project);
the history of every change; overdue tasks; progress per milestone and per
project; My tasks; notifications; translations.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/project-tasks.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS, A, B = fx['ids'], fx['job_a'], fx['job_b']
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


def user(key, project=None):
    c = Client()
    c.get('/login')
    status, body = c.post('/login', {'email': 'pt-%s@test.invalid' % key, 'password': 'TestPassword123!'})
    assert '<title>Sign in' not in body, key
    if project:
        c.get('/projects' if key == 'admin' else '/')
        c.post('/select-project', {'job_id': project})
    return c


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:600] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    out = subprocess.run([php, 'work/project_tasks_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def task(tid):
    return next(t for t in probe()['tasks'] if int(t['id']) == tid)


def events(tid):
    return [e for e in probe()['events'] if int(e['task_id']) == tid]


def last_task():
    return probe()['tasks'][-1]


# ── milestones ──────────────────────────────────────────────────────────
rec = user('rec', A)
status, body = rec.get('/project-tasks')
check('A recruiter opens the project tasks of the working project, with the forms to create', status == 200 and 'P5-M01 refinery turnaround' in body and 'id="task-create-form"' in body and 'id="milestone-form"' in body)
check('A project without tasks says so', 'data-live="0"' in body and 'No task on this project yet' in body)
check('A milestone without a title is refused', rec.post('/project-tasks', {'do': 'milestone_save', 'title': '', 'due_on': ''})[0] == 422)
check('and one with a date that is not a date', rec.post('/project-tasks', {'do': 'milestone_save', 'title': 'Mobilized', 'due_on': '2026-13-45'})[0] == 422)
rec.post('/project-tasks', {'do': 'milestone_save', 'title': 'Crew mobilized', 'due_on': '2030-01-15', 'description': 'All 40 on site'})
rec.post('/project-tasks', {'do': 'milestone_save', 'title': 'Hydrotest passed', 'due_on': '2020-01-10'})
ms = [m for m in probe()['milestones'] if int(m['job_id']) == A]
M1, M2 = int(ms[0]['id']), int(ms[1]['id'])
check('A recruiter sets two milestones on the project', len(ms) == 2 and ms[0]['title'] == 'Crew mobilized' and ms[0]['due_on'] == '2030-01-15')

# ── creating tasks: who, what, refusals ─────────────────────────────────
base_task = {'do': 'create', 'priority': 'normal', 'due_on': '', 'milestone_id': 0, 'assignee_id': 0, 'description': ''}
status, body = rec.post('/project-tasks', {**base_task, 'title': 'Book the hotel block', 'priority': 'high', 'due_on': '2030-01-05', 'milestone_id': M1, 'assignee_id': IDS['worker'],
                                           'description': 'Twenty rooms near the gate'})
T1 = int(last_task()['id'])
check('A task is created with its assignee, priority, deadline and milestone', status == 200 and last_task()['assignee_id'] == IDS['worker'] and last_task()['priority'] == 'high'
      and last_task()['due_on'] == '2030-01-05' and last_task()['milestone_id'] == M1 and last_task()['status'] == 'todo', last_task())
check('It can go to a worker placed on this project', last_task()['assignee_id'] == IDS['worker'])
check('Its history starts with its creation and its assignment', [e['kind'] for e in events(T1)] == ['created', 'assigned'] and events(T1)[1]['new_value'] == 'Tasks worker')
check('The worker is told, inside Crewvia', any(int(n['user_id']) == IDS['worker'] and 'Book the hotel block' in n['message'] and n['target'] == '/my-tasks?task=%d' % T1 for n in probe()['notifications']))
check('A worker placed on another project cannot be given the task', rec.post('/project-tasks', {**base_task, 'title': 'Wrong crew', 'assignee_id': IDS['workerb']})[0] == 422)
check('nor an account that is switched off', rec.post('/project-tasks', {**base_task, 'title': 'Inactive', 'assignee_id': IDS['inactive']})[0] == 422)
check('A milestone of another project is refused', rec.post('/project-tasks', {**base_task, 'title': 'Wrong milestone', 'milestone_id': fx['milestone_b']})[0] == 422)
check('A task without a title is refused', rec.post('/project-tasks', {**base_task, 'title': '  '})[0] == 422)
check('and a priority that does not exist', rec.post('/project-tasks', {**base_task, 'title': 'Odd', 'priority': 'critical'})[0] == 422)
check('Nothing was created by the refusals', len(probe('read', A)['tasks']) == 1)

pay = user('pay', A)
status, body = pay.get('/project-tasks')
check('Payroll sees the project tasks, without the forms to create', status == 200 and 'Book the hotel block' in body and 'id="task-create-form"' not in body)
check('and cannot create one', pay.post('/project-tasks', {**base_task, 'title': 'From payroll'})[0] == 422)

sup = user('sup')
status, body = sup.get('/project-tasks')
check('The supervisor of the crew opens the project tasks of their project', status == 200 and 'P5-M01 refinery turnaround' in body and 'id="task-create-form"' in body)
status, body = sup.post('/project-tasks', {**base_task, 'title': 'Toolbox talk on confined space', 'assignee_id': IDS['sup'], 'due_on': '2030-01-02', 'milestone_id': M1})
T2 = int(last_task()['id'])
check('and creates tasks on it', status == 200 and last_task()['title'] == 'Toolbox talk on confined space' and int(last_task()['created_by']) == IDS['sup'])
sup2 = user('sup2')
status, body = sup2.get('/project-tasks')
check('A supervisor with no crew sees no project and no task', status == 200 and 'Open a project first' in body and 'Book the hotel block' not in body)

# ── the person assigned moves it along; managers change everything ──────
rec.post('/project-tasks', {**base_task, 'title': 'Reconcile the per diem sheet', 'assignee_id': IDS['pay'], 'priority': 'low'})
T3 = int(last_task()['id'])
pay.get('/project-tasks?task=%d' % T3)
check('The person assigned moves their task along', pay.post('/project-tasks', {'do': 'update', 'task_id': T3, 'status': 'in_progress'})[0] == 200 and task(T3)['status'] == 'in_progress')
check('but does not change its other details', pay.post('/project-tasks', {'do': 'update', 'task_id': T3, 'priority': 'urgent'})[0] == 422 and task(T3)['priority'] == 'low')
check('nor cancel it', pay.post('/project-tasks', {'do': 'update', 'task_id': T3, 'status': 'cancelled'})[0] == 422)
check('Someone else\'s task cannot be moved by a person who does not manage the project', pay.post('/project-tasks', {'do': 'update', 'task_id': T1, 'status': 'done'})[0] == 422 and task(T1)['status'] == 'todo')
check('Saving with nothing changed is refused, not recorded', rec.post('/project-tasks', {'do': 'update', 'task_id': T3, 'status': 'in_progress'})[0] == 422
      and len(events(T3)) == 3)

rec.get('/project-tasks?task=%d' % T3)
status, body = rec.post('/project-tasks', {'do': 'update', 'task_id': T3, 'title': 'Reconcile the per diem sheets', 'assignee_id': IDS['rec'], 'priority': 'urgent',
                                           'due_on': '2030-01-20', 'milestone_id': M2, 'description': 'Both weeks'})
kinds = [e['kind'] for e in events(T3)]
check('A manager changes title, assignee, priority, deadline, milestone and description in one save', status == 200 and task(T3)['priority'] == 'urgent' and task(T3)['milestone_id'] == M2
      and task(T3)['assignee_id'] == IDS['rec'] and task(T3)['due_on'] == '2030-01-20')
check('and each change is a line of history with what it was and what it became', sorted(kinds[3:]) == sorted(['title', 'priority', 'deadline', 'milestone', 'assigned', 'description'])
      and next(e for e in reversed(events(T3)) if e['kind'] == 'priority')['old_value'] == 'low' and next(e for e in reversed(events(T3)) if e['kind'] == 'priority')['new_value'] == 'urgent'
      and next(e for e in reversed(events(T3)) if e['kind'] == 'assigned')['old_value'] == 'Tasks pay' and next(e for e in reversed(events(T3)) if e['kind'] == 'milestone')['new_value'] == 'Hydrotest passed', events(T3))
check('A wrong date is refused on a change too', rec.post('/project-tasks', {'do': 'update', 'task_id': T3, 'due_on': 'tomorrow'})[0] == 422)
status, body = rec.get('/project-tasks?task=%d' % T3)
check('The history is shown in words', 'Priority: Low → Urgent' in body and 'Assigned: Tasks pay → Tasks rec' in body and 'Milestone: – → Hydrotest passed' in body)

# ── a worker, from My tasks ─────────────────────────────────────────────
worker = user('worker')
status, body = worker.get('/my-tasks')
check('The worker sees the task on My tasks, with its project and milestone', status == 200 and 'Book the hotel block' in body and 'P5-M01 refinery turnaround' in body and 'Crew mobilized' in body)
check('and only their own', 'Toolbox talk' not in body and 'Reconcile' not in body)
worker.get('/project-tasks')
check('A worker does not open the project\'s task list', worker.final != '/project-tasks')
worker.get('/my-tasks')
check('Blocked needs what it is waiting for', worker.post('/my-tasks', {'task_id': T1, 'status': 'blocked', 'blocked_reason': ''})[0] == 422 and task(T1)['status'] == 'todo')
status, body = worker.post('/my-tasks', {'task_id': T1, 'status': 'blocked', 'blocked_reason': 'The client has not sent the purchase order', 'note': 'Called twice'})
check('The worker marks it blocked, with the reason and a note', status == 200 and task(T1)['status'] == 'blocked' and task(T1)['blocked_reason'] == 'The client has not sent the purchase order'
      and [e['kind'] for e in events(T1)][-2:] == ['status', 'note'] and int(events(T1)[-1]['user_id']) == IDS['worker'])
check('Whoever created the task is told it is blocked', any(int(n['user_id']) == IDS['rec'] and n['message'].startswith('Task blocked: Book the hotel block') for n in probe()['notifications']))
check('A worker cannot cancel their task', worker.post('/my-tasks', {'task_id': T1, 'status': 'cancelled'})[0] == 422)
check('nor change its deadline', worker.post('/my-tasks', {'task_id': T1, 'due_on': '2031-01-01'})[0] == 422 and task(T1)['due_on'] == '2030-01-05')
check('nor touch a task that is not theirs', worker.post('/my-tasks', {'task_id': T2, 'status': 'done'})[0] == 404 and task(T2)['status'] == 'todo')
worker.post('/my-tasks', {'task_id': T1, 'status': 'done'})
check('Done records when it was finished, and clears the blocked reason', task(T1)['status'] == 'done' and task(T1)['completed_at'] and task(T1)['blocked_reason'] is None)
worker.post('/my-tasks', {'task_id': T1, 'status': 'in_progress', 'note': 'Two rooms fell through'})
check('Reopening clears the finish time', task(T1)['status'] == 'in_progress' and task(T1)['completed_at'] is None)
worker.post('/my-tasks', {'task_id': T1, 'status': 'done'})
status, body = worker.get('/my-tasks?task=%d' % T1)
check('The worker reads the task\'s history', 'id="my-task-history"' in body and 'Status: Blocked → Done' in body and 'Called twice' in body)

# ── overdue, progress, milestones ───────────────────────────────────────
rec.post('/project-tasks', {**base_task, 'title': 'Order the scaffold tags', 'milestone_id': M2})
T4 = int(last_task()['id'])
probe('due', T4, '2020-01-01')
rec.post('/project-tasks', {**base_task, 'title': 'Old duplicate'})
T5 = int(last_task()['id'])
rec.get('/project-tasks?task=%d' % T5)
rec.post('/project-tasks', {'do': 'update', 'task_id': T5, 'status': 'cancelled'})
status, body = rec.get('/project-tasks')
p = re.search(r'id="task-progress" data-percent="(\d*)" data-live="(\d+)" data-done="(\d+)" data-overdue="(\d+)"', body)
check('Progress counts done over the live tasks, leaving cancelled out: 1 of 4 (25%), 1 overdue', p and p.groups() == ('25', '4', '1', '1'), p and p.groups())
check('An overdue task is marked as such in the list', 'data-task="%d" data-status="todo" data-overdue="1"' % T4 in body)
check('The open list leaves done and cancelled tasks out', 'data-task="%d"' % T1 not in body and 'data-task="%d"' % T5 not in body and 'data-task="%d"' % T4 in body)
status, body = rec.get('/project-tasks?overdue=1')
check('Overdue only shows exactly the late task', re.findall(r'<tr data-task="(\d+)"', body) == [str(T4)])
status, body = rec.get('/project-tasks?status=all&assignee=%d' % IDS['worker'])
check('Filters by person and status', re.findall(r'<tr data-task="(\d+)"', body) == [str(T1)])
check('Milestone 1 is under way: one of its two tasks done', 'data-milestone="%d" data-reached="0" data-overdue="0"' % M1 in body)
check('Milestone 2, past its date with tasks open, is overdue', 'data-milestone="%d" data-reached="0" data-overdue="1"' % M2 in body)
check('A milestone with open tasks cannot be cancelled', rec.post('/project-tasks', {'do': 'milestone_cancel', 'milestone_id': M2})[0] == 422)
sup.get('/project-tasks?task=%d' % T2)
sup.post('/project-tasks', {'do': 'update', 'task_id': T2, 'status': 'done'})
status, body = rec.get('/project-tasks')
check('When every live task of a milestone is done, it is reached', 'data-milestone="%d" data-reached="1"' % M1 in body)

admin = user('admin')
status, body = admin.get('/projects')
cell = re.search(r'data-task-progress="%d">(.*?)</td>' % A, body, re.S)
check('The projects list shows each project\'s task progress', cell and '2 of 4 done (50%)' in cell[1] and '1 overdue' in cell[1], cell and cell[1][:200])
check('and nothing for a project without tasks', re.search(r'data-task-progress="%d">\s*<span class="muted">' % B, body) is not None)
mine = {T1, T2, T3, T4, T5}
people = set(IDS.values())
audit = [a for a in probe()['activity'] if int(a['entity_id'] or 0) in mine and 'project task' in a['action'] and int(a['user_id'] or 0) in people]
check('Every creation and change of this project\'s tasks is in the audit log, with old and new values',
      sum(1 for a in audit if a['action'] == 'created a project task') == 5
      and any(a['action'] == 'updated a project task' and 'priority: low -> urgent' in (a['detail'] or '') for a in audit)
      and sum(1 for a in probe()['activity'] if a['action'] == 'set a milestone' and int(a['entity_id'] or 0) in {M1, M2} and int(a['user_id'] or 0) in people) == 2, [(a['action'], a['detail']) for a in audit])

# ── translations ───────────────────────────────────────────────────────
status, body = rec.get('/project-tasks?lang=fr')
check('Project tasks in French', 'Tâches du projet' in body and 'Jalons' in body)
status, body = worker.get('/my-tasks?lang=es')
check('My tasks in Spanish', 'Mis tareas' in body and 'Hecho' in body)
rec.get('/project-tasks?lang=en')
worker.get('/my-tasks?lang=en')

json.dump(results, open('work/pt-http-results.json', 'w'), indent=1)
