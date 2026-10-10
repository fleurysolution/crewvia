"""P1-M07: every route, opened by every role.

For each of the seven roles and every route in public/index.php:
  - no PHP error, warning, notice or deprecation, and no 500;
  - a route that belongs to a desk the role does not hold is refused (403)
    or sends them elsewhere - its page is never rendered for them.
Then every form added in Phase 1 is posted without its CSRF token and
must be refused with 419.
"""
import http.cookiejar, json, re, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
src = open('work/test-app/public/index.php', encoding='utf-8').read()
block = src[src.index('$routes = ['):src.index('];', src.index('$routes = ['))]
routes = re.findall(r"'(/[^']*)'\s*=>", block)
results = []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def _send(self, req):
        try:
            with self.opener.open(req) as r:
                body, status, final = r.read().decode(errors='replace'), r.status, urllib.parse.urlparse(r.geturl()).path
        except urllib.error.HTTPError as e:
            body, status, final = e.read().decode(errors='replace'), e.code, urllib.parse.urlparse(e.geturl()).path
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        return status, body, final

    def get(self, path):
        return self._send(base + path)

    def post(self, path, values, csrf=True):
        if csrf:
            values = {'_csrf': self.token, **values}
        return self._send(urllib.request.Request(base + path, urllib.parse.urlencode(values).encode()))

    def login(self, email):
        self.get('/login')
        status, body, _ = self.post('/login', {'email': email, 'password': 'TestPassword123!'})
        return '<title>Sign in' not in body


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:400] if detail else '')
    results.append(label)


# Who may open what. Anything not listed for a role must not render for it.
payroll_only = ['/bill-matching', '/periods', '/balances', '/accounting', '/project-costs', '/hours', '/payroll-export', '/pay-rules', '/pay-items', '/payroll-runs', '/attendance-week', '/billing',
                '/accounts-payable', '/client-invoices']
admin_only = ['/pay-grades', '/appraisal-templates', '/leave-types', '/settings', '/people', '/imports', '/structure', '/approval-chains', '/projects', '/overview',
              '/agency-setup', '/client-access', '/subscription', '/email-delivery', '/job']
roles = {
    'admin':      ('admin@test.invalid', []),
    'recruiter':  ('m01-recruiter@test.invalid', payroll_only + admin_only + ['/benefits', '/hotels', '/travel', '/procurement', '/vendors']),
    'hotels':     ('m01-hotels@test.invalid', payroll_only + admin_only + ['/hr-records', '/benefits', '/performance', '/appraisals', '/candidates', '/recruitment', '/employees', '/advances', '/change-requests']),
    'payroll':    ('m01-payroll@test.invalid', admin_only + ['/assets', '/appraisals', '/performance', '/hr-records', '/hotels', '/travel', '/candidates', '/recruitment']),
    'supervisor': ('supervisor@test.invalid', payroll_only + admin_only + ['/benefits', '/vendors', '/assets', '/candidates', '/employees', '/hotels', '/advances', '/roster', '/change-requests']),
    'worker':     ('worker@test.invalid', payroll_only + admin_only + ['/vendors', '/assets', '/candidates', '/employees', '/hotels', '/advances', '/roster', '/procurement', '/my-team', '/change-requests']),
    'client':     ('client@test.invalid', payroll_only + admin_only + ['/hr-records', '/benefits', '/performance', '/vendors', '/appraisals', '/assets', '/candidates', '/employees', '/hotels', '/advances', '/roster', '/procurement', '/attendance', '/timeoff', '/change-requests']),
}

pages = 0
for role, (email, forbidden) in roles.items():
    c = Client()
    check('Matrix: %s signs in' % role, c.login(email))
    for path in routes:
        status, body, final = c.get(path)
        pages += 1
        bad = [m for m in ('Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught') if m in body]
        check('Matrix: %s %s has no PHP error' % (role, path), status != 500 and not bad, (status, bad, body[:200]))
        if path in forbidden:
            check('Matrix: %s cannot open %s' % (role, path), status in (403, 404) or final != path, (status, final))

# Every form added in Phase 1, posted without its token.
admin = Client()
admin.login('admin@test.invalid')
for path, do in [('/attendance', 'correct'), ('/hours', 'approve_week'), ('/pay-rules', 'create'), ('/pay-items', 'create'),
                 ('/payroll-runs', 'open'), ('/leave-types', 'create'), ('/employee-folder', 'classification'),
                 ('/procurement', 'request'), ('/accounts-payable', 'invoice'), ('/timeoff', 'review'), ('/assets', 'register'), ('/operations', 'issue'), ('/appraisals', 'open'), ('/appraisal-templates', 'save'), ('/project-costs', 'budget'), ('/accounting', 'export'), ('/accounting', 'reverse'), ('/balances', 'record'), ('/balances', 'credit'), ('/periods', 'close'), ('/vendors', 'save'), ('/vendors', 'thresholds'), ('/procurement', 'rfq'), ('/procurement', 'revise'), ('/bill-matching', 'clear'), ('/performance', 'goal_add'), ('/performance', 'cycle_create'), ('/benefits', 'enroll'), ('/advances', 'pause'), ('/hr-records', 'case_open'), ('/hr-records', 'grant')]:
    status, _, _ = admin.post(path, {'do': do}, csrf=False)
    check('CSRF: %s %s without its token is refused' % (path, do), status == 419, status)

print('PASS Access matrix: %d pages opened across %d roles, no PHP error, every forbidden page refused' % (pages, len(roles)), flush=True)
print('PASS CSRF: every Phase 1 form refuses a post without its token', flush=True)
json.dump(results, open('work/m07-access-results.json', 'w'), indent=1)
