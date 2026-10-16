"""P5-M59 through the screens: a project manager sees the projects they manage.
  - the administrator names them project manager in Job setup;
  - signed in on the Supervisor desk, without a crew of their own, My team opens on their project: on site,
    hired and not started, without a supervisor, the requisitions being filled, the supervisors and their crews;
  - the project tasks, the project board and the workload open on their project (they were refused before);
  - a supervisor who manages no project sees no such section.
Runs after menu_crawl_http.py (menu_crawl_db.php creates the account), on the walkthrough fixture.
"""
import http.cookiejar, json, re, urllib.error, urllib.parse, urllib.request
from html.parser import HTMLParser

base = 'http://127.0.0.1:8097'
wk = json.load(open('work/walkthrough.json'))
results = []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                return r.status, r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace')

    def get(self, path):
        status, body = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body

    def login(self, email, password='TestPassword123!'):
        self.get('/login')
        self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': password}).encode()))

    def post(self, path, values, page):
        self.get(page)
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}, doseq=True).encode(), {'Accept': 'text/html'}))


class FormValues(HTMLParser):
    """The values a browser would send for the form that contains the element with this id."""
    def __init__(self, marker):
        super().__init__()
        self.marker, self.forms, self.cur, self.select, self.textarea = marker, [], None, None, None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'form':
            self.cur = {'fields': [], 'has': False}
            self.forms.append(self.cur)
        if self.cur is None:
            return
        if a.get('id') == self.marker:
            self.cur['has'] = True
        if tag == 'input' and a.get('name') and a.get('type') not in ('submit', 'button', 'file'):
            if a.get('type') in ('checkbox', 'radio') and 'checked' not in a:
                return
            self.cur['fields'].append([a['name'], a.get('value', '')])
        if tag == 'select' and a.get('name'):
            self.select = [a['name'], None]
            self.cur['fields'].append(self.select)
        if tag == 'option' and self.select is not None:
            if self.select[1] is None or 'selected' in a:
                self.select[1] = a.get('value', '')
        if tag == 'textarea' and a.get('name'):
            self.textarea = [a['name'], '']
            self.cur['fields'].append(self.textarea)

    def handle_endtag(self, tag):
        if tag == 'select':
            self.select = None
        if tag == 'textarea':
            self.textarea = None
        if tag == 'form':
            self.cur = None

    def handle_data(self, data):
        if self.textarea is not None:
            self.textarea[1] += data

    def values(self):
        form = next(f for f in self.forms if f['has'])
        return [(k, v or '') for k, v in form['fields'] if k != '_csrf']


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS ' + label)


admin = Client(); admin.login('wk-admin@test.invalid')
admin.post('/select-project', {'job_id': wk['job']}, '/job')
status, body = admin.get('/job')
pm_id = re.search(r'<option value="(\d+)"[^>]*>Menu crawl supervisor', body)[1]
parser = FormValues('j-pm'); parser.feed(body)
values = [(k, pm_id if k == 'project_manager_id' else v) for k, v in parser.values()]
admin.post('/job', dict(values), '/job')
check('The administrator names them project manager in Job setup', re.search(r'<option value="%s" selected>' % pm_id, admin.get('/job')[1]) is not None)

pm = Client(); pm.login('mc-pm@test.invalid')
status, body = pm.get('/my-team')
section = re.search(r'<section class="card" id="managed-%d"(.*?)</section>' % wk['job'], body, re.S)
check('My team opens on the project they manage, though they carry no crew', status == 200 and 'My projects and team' in body and section is not None, body[:400])
tiles = dict(re.findall(r'data-pm="([a-z_]+)"><div class="n">(\d+)</div>', section[1])) if section else {}
check('with who is on site, hired and not started, without a supervisor, and the recruiting',
      set(tiles) == {'on_site', 'not_started', 'unassigned', 'recruiting'} and int(tiles['on_site']) >= 1, tiles)
check('and the supervisors with their crews', section and 'Walk sup' in section[1], section[1][-600:] if section else '')

title = re.sub(r'<[^>]+>.*', '', re.search(r'<h2 class="m0">(.*?)</h2>', section[1], re.S)[1]).strip()
status, body = pm.get('/project-tasks')
check('The project tasks open on their project, and they can add a task', status == 200 and title in body and 'name="do" value="create"' in body, (title, body[:300]))
import datetime
me = re.search(r'<select[^>]*name="assignee_id".*?<option value="(\d+)"[^>]*>Menu crawl supervisor', body, re.S)
pm.post('/project-tasks', {'do': 'create', 'title': 'P5-M59 walk the plant with the client', 'description': '', 'priority': 'normal', 'assignee_id': me[1] if me else '',
                           'estimate_hours': '6', 'due_on': (datetime.date.today() + datetime.timedelta(days=2)).isoformat(), 'milestone_id': '', 'parent_id': '', 'sprint_id': ''}, '/project-tasks')
status, body = pm.get('/project-board')
check('The project board opens on their project, with the task they added', status == 200 and 'P5-M59 walk the plant with the client' in body, body[:300])
status, body = pm.get('/workload')
check('and the workload counts it against them', status == 200 and 'Menu crawl supervisor' in body and 'Nobody has open tasks' not in body, re.sub(r'<[^>]+>', ' ', body)[-800:])

sup = Client(); sup.login('wk-sup@test.invalid')
status, body = sup.get('/my-team')
check('A supervisor who manages no project sees no such section', status == 200 and 'data-managed=' not in body and 'My supervised team' in body)

json.dump(results, open('work/pm-http-results.json', 'w'), indent=1)
