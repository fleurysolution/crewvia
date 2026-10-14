"""The layout walkthrough (P5-M20 onward), checked page by page for every
role's menu, so the look cannot drift apart again:
  - every page answers, with exactly one title;
  - every page says which part of Crewvia it belongs to, above its title;
  - every page opens with the standard header, except the pages still
    listed in PENDING for the page-by-page wave (the list only shrinks);
  - the working-project bar shows on the pages that read the working
    project, and only there.
Runs on the main fixture's accounts.
"""
import http.cookiejar, json, re, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
results = []
ROLES = {'admin': 'admin@test.invalid', 'worker': 'worker@test.invalid', 'supervisor': 'supervisor@test.invalid', 'client': 'client@test.invalid'}
# Pages still on their own header. Wave 4 (P5-M23) converted the last of them; keep it empty.
PENDING = set()
SKIP = {'/logout', '/inbox'}


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
        return status, body

    def login(self, email):
        self.get('/login')
        self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': 'TestPassword123!'}).encode()))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:900] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def main_of(body):
    m = re.search(r'<main[^>]*>(.*)</main>', body, re.S)
    return m[1] if m else ''


seen = {}
for role, email in ROLES.items():
    c = Client()
    c.login(email)
    status, body = c.get('/')
    nav = re.search(r'<nav[^>]*>(.*?)</nav>', body, re.S)
    menu = sorted({h.split('#')[0] for h in re.findall(r'href="(/[^"]*)"', nav[1] if nav else '')} - SKIP)
    broken, titles, labels, headers = [], [], [], []
    for path in menu:
        status, page = c.get(path)
        if status != 200 or re.search(r'Fatal error|Warning:|Notice:|Deprecated:', page):
            broken.append((path, status))
            continue
        content = main_of(page)
        if len(re.findall(r'<h1[\s>]', content)) != 1:
            titles.append((path, len(re.findall(r'<h1[\s>]', content))))
        if 'class="page-eyebrow"' not in content and 'class="eyebrow"' not in content:
            labels.append(path)
        if path not in PENDING and 'class="page-head"' not in content and 'class="page-heading"' not in content:
            headers.append(path)
        seen[path] = content
    check('%s: every page in the menu answers (%d pages)' % (role, len(menu)), not broken, broken)
    check('%s: every page has exactly one title' % role, not titles, titles)
    check('%s: every page says which part of Crewvia it belongs to' % role, not labels, labels)
    check('%s: every page opens with the standard header' % role, not headers, headers)

admin = Client()
admin.login(ROLES['admin'])
bar = lambda path: 'id="working-project"' in admin.get(path)[1]
check('The project bar shows where the page reads the working project', all(bar(p) for p in ('/hours', '/roster', '/hotels', '/attendance', '/job')))
check('and not on pages that have nothing to do with it', not any(bar(p) for p in ('/people', '/settings', '/integrations', '/candidates', '/campaigns', '/audit-log')))

json.dump(results, open('work/layout-http-results.json', 'w'), indent=1)
