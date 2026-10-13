"""P4-M06 through the screens: response headers, sessions (a forged id not
adopted, sign-out clearing the cookie, a backslash redirect refused), the
password rule and rehash, failed sign-ins logged with their address, the
two-step requirement, payroll details hidden from recruiters, bank details
needing a second person, CSV cells neutralised, a supervisor kept to their
own projects, the privacy notice, consent, subject export and
anonymisation, the audit log and its refusal to change, accessibility
markers, translations.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/security-review.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []
IDS = fx['ids']


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.raw = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
        self.token = None
        self.headers = {}

    def open(self, req, follow=True):
        try:
            with (self.opener if follow else self.raw).open(req) as r:
                return r.status, r.headers, r.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.headers, e.read().decode()

    def get(self, path, headers=None, follow=True):
        status, h, body = self.open(urllib.request.Request(base + path, headers=headers or {}), follow)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        self.headers = h
        return status, body

    def post(self, path, values, follow=True):
        status, h, body = self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()), follow)
        assert 'Fatal error' not in body and 'Warning:' not in body, body[:800]
        self.headers = h
        return status, body

    def login(self, email, password='TestPassword123!'):
        self.get('/login')
        status, body = self.post('/login', {'email': email, 'password': password})
        return (status if '<title>Sign in' not in body else 401), body


def user(key):
    c = Client()
    status, body = c.login('sr-%s@test.invalid' % key)
    assert status == 200, (key, status, body[:400])
    return c


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail) if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    out = subprocess.run([php, 'work/security_review_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def te_button_count(body):
    return sum(1 for label in ['Change', 'Open project'] if re.search(r'<button[^>]*>\s*' + label + r'\s*</button>', body))


def logged(action, **match):
    return [a for a in probe()['activity'] if a['action'] == action and all(str(a[k]) == str(v) for k, v in match.items())]


# ── headers ─────────────────────────────────────────────────────────────
anon = Client()
status, body = anon.get('/login')
csp = anon.headers.get('Content-Security-Policy', '')
check('Every page sends a content security policy: own scripts only, no framing, no plugins, forms to known places',
      "default-src 'self'" in csp and "frame-ancestors 'none'" in csp and "object-src 'none'" in csp and "base-uri 'none'" in csp and "form-action 'self'" in csp, csp)
check('Camera, microphone, location and payment are switched off for the page', 'camera=()' in anon.headers.get('Permissions-Policy', '') and 'payment=()' in anon.headers.get('Permissions-Policy', ''))
check('No HSTS over plain HTTP', anon.headers.get('Strict-Transport-Security') is None)
anon.get('/login', {'X-Forwarded-Proto': 'https'})
check('Behind HTTPS the browser is told to stay on HTTPS for a year', 'max-age=31536000' in (anon.headers.get('Strict-Transport-Security') or ''))
cookie_name = next(c.name for c in anon.jar)

# ── sessions ────────────────────────────────────────────────────────────
forged = 'p4m06forgedsessionid0000000000ab'
status, h, body = Client().open(urllib.request.Request(base + '/login', headers={'Cookie': '%s=%s' % (cookie_name, forged)}))
set_cookie = ' '.join(h.get_all('Set-Cookie') or [])
check('A session id the server never issued is not adopted (strict mode)', cookie_name + '=' in set_cookie and forged not in set_cookie, set_cookie)
admin = user('admin')
admin.get('/projects')
status, body = admin.post('/select-project', {'job_id': fx['job_crew'], 'then': '/\\evil.test/'}, follow=False)
check('A project switch asked to go to /\\evil.test stays on this site', status in (302, 303) and admin.headers.get('Location') == '/', admin.headers.get('Location'))
status, body = admin.post('/select-project', {'job_id': fx['job_crew'], 'then': '/projects'}, follow=False)
check('and a path inside the application is still followed', admin.headers.get('Location') == '/projects')
leaving = user('recruiter')
leaving.get('/')
status, body = leaving.post('/logout', {}, follow=False)
gone = ' '.join(leaving.headers.get_all('Set-Cookie') or [])
check('Signing out expires the session cookie in the browser', cookie_name + '=deleted' in gone or ('expires=' in gone.lower() and '1970' in gone) or 'Max-Age=0' in gone, gone)

# ── sign-in, passwords ──────────────────────────────────────────────────
status, body = Client().login('sr-recruiter@test.invalid', 'WrongPassword999!')
check('A wrong password is refused', status == 401)
check('and the failed sign-in is in the audit log, with the address it came from',
      any(a['ip'] == '127.0.0.1' and 'sr-recruiter@test.invalid' in (a['detail'] or '') for a in logged('failed sign-in')))
probe('weak-hash', IDS['weak'])
check('A cheap stored hash is found (cost 4)', probe('cost', IDS['weak']) == 4)
weak = user('weak')
check('Signing in upgrades it to the current cost', (probe('cost', IDS['weak']) or 0) >= 10, probe('cost', IDS['weak']))
weak.get('/account')
status, body = weak.post('/account', {'current': 'TestPassword123!', 'new': 'Elevenchars', 'repeat': 'Elevenchars'})
check('A new password of 11 characters is refused', 'Use at least 12 characters' in body)
long = 'A' * 73
status, body = weak.post('/account', {'current': 'TestPassword123!', 'new': long, 'repeat': long})
check('One over 72 bytes is refused rather than silently cut (bcrypt reads only 72)', 'Use at most 72 characters' in body)
check('and the password is unchanged', Client().login('sr-weak@test.invalid')[0] == 200)

# ── two-step verification, required for administrators and payroll ──────
admin.get('/account-security')
status, body = admin.post('/account-security', {'do': 'require_two_step', 'required': '1'})
check('Changing the requirement needs the current password of the administrator', probe()['two_step'] != '1' and 'That is not your current password' in body)
status, body = admin.post('/account-security', {'do': 'require_two_step', 'required': '1', 'password': 'TestPassword123!'})
check('An administrator without two-step cannot require it (they would lock themselves out)', probe()['two_step'] != '1' and 'Turn on two-step verification for your own account first' in body)
check('The refusal is announced to screen readers', 'role="alert"' in body)
check('The policy card is on the page', 'id="two-step-policy"' in body and 'data-required="0"' in body)
probe('setting', 'require_two_step_privileged', '1')
payroll = Client()
payroll.login('sr-payroll@test.invalid')
status, body = payroll.get('/payroll-export')
check('With two-step required, payroll without it is sent to set it up', '<title>' in body and 'Two-step verification is required for your role' in body and 'id="two-step-policy"' not in body)
rec = user('recruiter')
status, body = rec.get('/candidates')
check('A recruiter is not affected', status == 200 and 'Two-step verification is required' not in body)
probe('setting', 'require_two_step_privileged', '0')
status, body = payroll.get('/payroll-export')
check('Once the requirement is lifted payroll works again', status == 200 and 'Two-step verification is required' not in body)

# ── payroll details in the folder history ────────────────────────────────
status, body = rec.get('/employee-folder?id=%d' % fx['placed'])
check('A recruiter sees the folder history with the pay change hidden', status == 200 and 'data-redacted="1"' in body and '42.50' not in body and 'Available from Monday' in body)
status, body = payroll.get('/employee-folder?id=%d' % fx['placed'])
check('Payroll sees the pay change', status == 200 and '42.50' in body and 'data-redacted' not in body)
check('The bank fields payroll fills have labels tied to them', all('for="%s"' % i in body and 'id="%s"' % i in body for i in ['b-holder', 'b-bank', 'b-routing', 'b-account']))

# ── bank details need a second person ───────────────────────────────────
payroll.post('/employee-folder', {'do': 'bank', 'candidate_id': fx['placed'], 'account_holder': 'Wren Placed', 'bank_name': 'Review Bank', 'routing_number': '021000021', 'account_number': '555566667777'})
req = [r for r in probe()['bank_requests'] if int(r['candidate_id']) == fx['placed']]
check('Bank details payroll types are a proposal, not in use', len(req) == 1 and req[0]['status'] == 'pending' and int(req[0]['submitted_by']) == IDS['payroll']
      and not [b for b in probe()['bank'] if int(b['candidate_id']) == fx['placed']], req)
check('and the proposal is in the audit log with the last four digits only', any('7777' in (a['detail'] or '') and '555566667777' not in (a['detail'] or '') for a in logged('proposed bank details', entity_id=fx['placed'])))
payroll.get('/change-requests')
status, body = payroll.post('/change-requests', {'do': 'bank', 'request_id': req[0]['id'], 'decision': 'approve', 'note': ''})
check('Whoever entered them cannot accept them', status == 422 and probe()['bank_requests'][-1]['status'] == 'pending')
payroll2 = user('payroll2')
payroll2.get('/change-requests')
status, body = payroll2.post('/change-requests', {'do': 'bank', 'request_id': req[0]['id'], 'decision': 'approve', 'note': ''})
bank = [b for b in probe()['bank'] if int(b['candidate_id']) == fx['placed']]
check('A second payroll person accepts them, and only then are they used', status == 200 and len(bank) == 1 and bank[0]['status'] == 'verified'
      and int(bank[0]['submitted_by']) == IDS['payroll'] and int(bank[0]['reviewed_by']) == IDS['payroll2'], bank)

# ── a formula in an ADP id does not run in a spreadsheet ────────────────
status, body = payroll.post('/employee-folder', {'do': 'payment', 'candidate_id': fx['placed'], 'payment_method': 'direct_deposit', 'adp_employee_id': '=HYPERLINK("http://x")'})
check('An ADP employee id with a formula is refused when typed', status == 422 and 'An ADP employee ID is letters' in body)
probe('adp', fx['cn_ada'], '=HYPERLINK("http://x")')
payroll.post('/select-project', {'job_id': fx['cn_job']})
status, body = payroll.get('/payroll-export?week=%s&export=review' % fx['cn_week'])
check('One already stored is written to the review CSV as text, not a formula', status == 200 and '\'=HYPERLINK' in body and '\n=HYPERLINK' not in body and ',=HYPERLINK' not in body and ',"=HYPERLINK' not in body, body[:400])
probe('adp', fx['cn_ada'], 'P4A1')

# ── a supervisor keeps to the projects they supervise ────────────────────
sup = user('super')
sup.get('/my-team')
# P5-M20: the chooser shows on pages that read the working project (My team lists every crew of theirs).
status, body = sup.get('/procurement')
options = re.findall(r'<option value="(\d+)"', re.search(r'<select id="working-project".*?</select>', body, re.S)[0])
check('A supervisor opens on their own project, and the chooser lists only the projects they supervise', options == [str(fx['job_crew'])], options)
status, body = sup.post('/select-project', {'job_id': fx['job_other']})
check('Switching to a project they do not supervise is refused', status == 403)
status, body = sup.get('/procurement')
check('They see the purchasing of the project they supervise', status == 200)
probe('crew', 'add', fx['placed'], fx['job_other'], IDS['super'])
sup.get('/my-team')
sup.post('/select-project', {'job_id': fx['job_other']})
probe('crew', 'remove', fx['placed'], fx['job_other'], IDS['super'])
status, body = sup.get('/procurement')
check('Once their crew leaves a project, its purchasing closes to them, though it is still their working project', status == 403)
sup.post('/select-project', {'job_id': fx['job_crew']})
sup.get('/learning')
before = len(probe()['incidents'])
sup.post('/learning', {'do': 'incident', 'job_id': fx['job_other'], 'description': 'Reported on someone else\'s project', 'severity': 'low'})
check('A supervisor cannot report an incident on a project they do not supervise', len(probe()['incidents']) == before)
sup.post('/learning', {'do': 'incident', 'job_id': fx['job_crew'], 'description': 'Loose scaffold board on level 2', 'severity': 'medium'})
check('but can on their own', int(probe()['incidents'][-1]['job_id']) == fx['job_crew'] and int(probe()['incidents'][-1]['reported_by']) == IDS['super'])

# ── privacy notice and consent ──────────────────────────────────────────
status, body = Client().get('/privacy')
check('The privacy notice page is public and says when none is published yet', status == 200 and 'id="privacy-notice" data-version="0"' in body)
def join(email, phone):
    c = Client()
    status, body = c.get('/join')
    check_link = 'href="/privacy"' in body
    status, body = c.post('/join', {'name': 'Joiner ' + email[:8], 'email': email, 'phone': phone, 'consent': '1'})
    return status, check_link
probe('reset-intake')
status, link = join('sr-join1@test.invalid', '555-0101')
check('The talent pool form links to the notice', link)
first = probe()['consents'][-1]
check('A consent given before any notice is recorded, against no notice', status == 200 and first['source'] == 'talent pool form' and first['notice_id'] is None, first)
admin.get('/privacy-requests')
status, body = admin.post('/privacy-requests', {'do': 'publish', 'body': 'Too short.'})
check('A notice of a few words is refused', status == 422 and probe()['notices'] == [])
notice = ('Review Staffing collects your name, contact details and work history to consider you for work and to contact you about it. '
          'It keeps them while you are in the talent pool and for as long as the law requires after any placement. '
          'Ask the office for a copy of what is held about you, or for it to be removed.')
status, body = admin.post('/privacy-requests', {'do': 'publish', 'body': notice})
check('An administrator publishes the notice, version 1', status == 200 and len(probe()['notices']) == 1)
status, body = Client().get('/privacy')
check('The public page shows it', 'data-version="%s"' % probe()['notices'][0]['id'] in body and 'talent pool' in body)
join('sr-join2@test.invalid', '555-0102')
check('A consent given afterwards is recorded against that version', probe()['consents'][-1]['notice_id'] == probe()['notices'][0]['id'])
status, body = admin.post('/privacy-requests', {'do': 'export', 'who': 'sr-placed@test.invalid'})
check('Everything held about a person is exported as JSON', status == 200 and 'Wren Placed' in body and 'application/json' in admin.headers.get('Content-Type', ''), body[:300])
check('and the export is in the audit log with its fingerprint', any((a['detail'] or '').startswith('sha256 ') for a in logged('exported everything held about a person', entity_id=fx['placed'])))
status, body = admin.post('/privacy-requests', {'do': 'anonymise', 'who': 'sr-placed@test.invalid', 'reason': 'Asked to be removed'})
check('A placed worker is not anonymised: their pay records are kept', status == 422 and probe('candidate', fx['placed'])['full_name'] == 'Wren Placed')
status, body = admin.post('/privacy-requests', {'do': 'anonymise', 'who': 'sr-applicant@test.invalid', 'reason': 'Asked to be removed by email'})
gone = probe('candidate', fx['applicant'])
check('An applicant never placed is anonymised: name, email, phone, notes and CV text gone', status == 200 and gone['full_name'] != 'Ivy Applicant'
      and gone['email'] != 'sr-applicant@test.invalid' and gone['phone'] is None and gone['notes'] is None and gone['resume_text'] is None, gone)
check('and the anonymisation is logged with the reason', logged('anonymised an applicant', entity_id=fx['applicant']) != [])
status, body = rec.get('/privacy-requests')
check('A recruiter has no access to privacy requests', status == 403)

# ── the audit log ───────────────────────────────────────────────────────
status, body = admin.get('/audit-log?action=failed+sign-in')
check('An administrator reads the audit log, filtered, with addresses', status == 200 and 'data-action="failed sign-in"' in body and '127.0.0.1' in body and 'data-action="signed out"' not in body)
check('Reading it is itself logged', logged('read the audit log', user_id=IDS['admin']) != [])
status, body = payroll.get('/audit-log')
check('Payroll does not read the audit log', status == 403)
tamper = probe('tamper-log')
check('The database refuses to change or delete an audit entry', 'The audit log cannot be changed' in tamper['update'] and 'The audit log cannot be changed' in tamper['delete'], tamper)
check('Downloading the payroll review is logged', logged('exported the payroll review', user_id=IDS['payroll']) != [])

# ── accessibility markers ───────────────────────────────────────────────
status, body = admin.get('/')
check('A skip link leads to the main content, which takes focus', 'class="skip-link" href="#main"' in body and 'id="main" tabindex="-1"' in body)
pickers = re.findall(r'<select id="(?:lang-pick|working-project)"[^>]*>', body)
check('The language and project choosers do not submit on change; each has its button', len(pickers) == 2 and not any('onchange' in s for s in pickers)
      and te_button_count(body) >= 2, pickers)

# ── translations ────────────────────────────────────────────────────────
status, body = admin.get('/audit-log?lang=fr')
check('The audit log in French', 'Journal d\'audit' in body or 'Journal d&#039;audit' in body)
status, body = admin.get('/privacy-requests?lang=es')
check('Privacy requests in Spanish', 'Aviso de privacidad' in body)
status, body = Client().get('/privacy?lang=fr')
check('The public notice page in French', 'Avis de confidentialité' in body)
admin.get('/audit-log?lang=en')
for c in [rec, payroll, payroll2, sup, weak]:
    c.get('/?lang=en')

json.dump(results, open('work/sr-http-results.json', 'w'), indent=1)
