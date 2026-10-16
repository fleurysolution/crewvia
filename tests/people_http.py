"""P5-M57 through the screens: Team puts the staff and the form to add somebody first.
  - the form is on top, before any list, and offers only the staff desks;
  - the staff, the client portals and the worker portals are three tabs, counted; the staff tab is the default;
  - a portal account shows its kind instead of a desk menu that would be refused;
  - search finds an account by email; adding somebody shows their temporary password and lists them as staff.
Runs after the walkthrough (onboarding_scope_http.py), on its fixture.
"""
import http.cookiejar, json, os, re, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
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
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode(), {'Accept': 'text/html'}))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS ' + label)


admin = Client(); admin.login('wk-admin@test.invalid')
status, body = admin.get('/people')
form = re.search(r'<details class="card" id="add-person" open>(.*?)</details>', body, re.S)
check('The form to add somebody is on top, open, before any list', form and body.index('id="add-person"') < body.index('<table'))
desks = re.findall(r'<option value="([a-z]+)"', form[1]) if form else []
check('and offers only the staff desks', set(desks) == {'admin', 'recruiter', 'hotels', 'payroll', 'supervisor'}, desks)
counts = dict(re.findall(r'data-tab="([a-z]+)">[^<]*· (\d+)</a>', body))
check('Staff, client portals and worker portals are three counted tabs, the staff first',
      set(counts) == {'staff', 'clients', 'workers'} and 'class="on" data-tab="staff"' in body and int(counts['workers']) > 0, counts)
check('The staff tab lists the staff, not the portal accounts', 'wk-rec@test.invalid' in body and 'av-quinn@test.invalid' not in body)

status, body = admin.get('/people?tab=workers&q=av-quinn')
check('Search finds a portal account by email, on its tab', 'av-quinn@test.invalid' in body and 'class="on" data-tab="workers"' in body)
row = re.search(r'av-quinn@test.invalid</td>(.*?)</tr>', body, re.S)
check('A portal account shows its kind, not a desk menu that would be refused', row and 'name="role"' not in row[1] and 'Worker portal' in row[1])

status, body = admin.post('/people', {'do': 'add', 'name': 'Sam Example (TEST)', 'email': 'people-sam@test.invalid', 'phone': '', 'role': 'supervisor', 'job_title': 'Site supervisor'}, '/people')
check('Adding somebody shows their temporary password once', re.search(r'Sam Example \(TEST\) added\. Their temporary password is: [0-9a-f]{16}', body) is not None)
check('and shows them right away, with the staff, temporary password marked', 'people-sam@test.invalid' in body and re.search(r'Sam Example \(TEST\)</strong>.*?temp password', body, re.S) is not None)

json.dump(results, open('work/people-http-results.json', 'w'), indent=1)
