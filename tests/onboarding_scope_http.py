"""P5-M55 through the screens: an onboarding requirement asks the new hires, the crew on site only when ticked.
  - a requirement added to a project becomes a task for the new hire (assignment not started), not for the crew on site;
  - the same title twice on a project is refused;
  - "Also ask the crew already on site" gives it to them; unticked, their untouched tasks go;
  - a requirement nobody answered can be removed, with its tasks; once answered, it cannot.
Runs after offer_flow_http.py, on the walkthrough fixture (Quinn hired there, a worker on site from the walkthrough).
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
wk = json.load(open('work/walkthrough.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []
QUINN, PASSWORD = 'av-quinn@test.invalid', 'Portal-Test-Pass-2026'


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
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode(), {'Accept': 'text/html'}))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS ' + label)


def probe(*args):
    return json.loads(subprocess.run([php, 'work/onboarding_scope_probe.php', *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


def by_placement(title):
    return {t['placement'] for t in probe('tasks', title)['tasks']}


rec = Client(); rec.login('wk-rec@test.invalid')
rec.post('/select-project', {'job_id': wk['job']}, '/requisitions')
BADGE = 'P5-M55 gate badge'
rec.post('/onboarding', {'do': 'requirement', 'title': BADGE, 'instructions': 'Pick up your gate badge at the security office.'}, '/onboarding')
rec.get('/onboarding')
st = probe('tasks', BADGE)
check('A requirement becomes a task for the new hire, not for the crew already on site',
      any(t['email'] == QUINN and t['placement'] == 'offered' for t in st['tasks']) and 'on_site' not in by_placement(BADGE), st)

status, body = rec.post('/onboarding', {'do': 'requirement', 'title': BADGE, 'instructions': 'Again.'}, '/onboarding')
check('The same title twice on a project is refused', 'already has that requirement' in body and probe('tasks', BADGE)['count'] == 1)

rid = st['requirement']
rec.post('/onboarding', {'do': 'configure_requirement', 'requirement_id': rid, 'instructions': 'Pick up your gate badge at the security office.', 'required': '1', 'for_crew': '1'}, '/onboarding')
rec.get('/onboarding')
check('"Also ask the crew already on site" gives it to them', probe('tasks', BADGE)['for_crew'] == 1 and 'on_site' in by_placement(BADGE))
rec.post('/onboarding', {'do': 'configure_requirement', 'requirement_id': rid, 'instructions': 'Pick up your gate badge at the security office.', 'required': '1'}, '/onboarding')
rec.get('/onboarding')
check('unticked, their untouched tasks go; the new hire keeps theirs', 'on_site' not in by_placement(BADGE) and 'offered' in by_placement(BADGE))

TYPO = 'P5-M55 typo requirement'
rec.post('/onboarding', {'do': 'requirement', 'title': TYPO, 'instructions': 'Added by mistake.'}, '/onboarding')
status, body = rec.get('/onboarding')
typo = probe('tasks', TYPO)
check('A requirement nobody answered offers "Remove"', ('data-remove-requirement="%d"' % typo['requirement']) in body and typo['tasks'])
rec.post('/onboarding', {'do': 'remove_requirement', 'requirement_id': typo['requirement']}, '/onboarding')
check('and is removed with its tasks', probe('tasks', TYPO)['count'] == 0)

quinn = Client(); quinn.login(QUINN, PASSWORD)
task = [t for t in probe('tasks', BADGE)['tasks'] if t['email'] == QUINN][0]
quinn.post('/portal', {'task_id': task['id'], 'response': 'Picked it up on Monday.'}, '/portal')
status, body = rec.get('/onboarding')
check('Once somebody answered it, it cannot be removed', ('data-remove-requirement="%d"' % rid) not in body
      and 'Somebody has already answered this requirement' in rec.post('/onboarding', {'do': 'remove_requirement', 'requirement_id': rid}, '/onboarding')[1] and probe('tasks', BADGE)['count'] == 1)

json.dump(results, open('work/onboarding-scope-results.json', 'w'), indent=1)
