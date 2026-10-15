"""P5-M26 through the screens: the fixes found walking every flow, role by role,
with the two open Indeed jobs (the findings are in docs/implementation/P5-M26_REPORT.md).
Each fix is checked by its effect, read back through walkthrough_probe.php.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/walkthrough.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS = fx['ids']
results = []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None
        self.url = None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                self.url = urllib.parse.urlparse(r.geturl()).path
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

    def login(self, email, password='TestPassword123!'):
        self.get('/login')
        self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': password}).encode()))
        return self.url

    def post(self, path, values, page=None, raw=None, ctype=None):
        self.get(page or path)
        if raw is not None:
            return self.open(urllib.request.Request(base + path, raw(self.token), {'Content-Type': ctype}))
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    return json.loads(subprocess.run([php, 'work/walkthrough_probe.php', *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


def flash(body):
    return ' '.join(re.sub(r'<[^>]+>|\s+', ' ', f).strip() for f in re.findall(r'class="flash[^"]*"[^>]*>(.*?)</p>', body, re.S))


# ── 1. a first sign-in ends on the role's home ─────────────────────────
temp = Client()
check('A desk on its temporary password is sent to change it', temp.login('wk-temp@test.invalid') == '/account')
temp.post('/account', {'current': 'TestPassword123!', 'new': 'Walk-Fresh-Pass-2026!', 'repeat': 'Walk-Fresh-Pass-2026!'})
check('and, the password changed, lands on its home (Today), not the project dashboard', temp.url == '/home', temp.url)

admin, rec, sup = Client(), Client(), Client()
admin.login('wk-admin@test.invalid'); rec.login('wk-rec@test.invalid'); sup.login('wk-sup@test.invalid')
for c in (admin, rec):
    c.post('/select-project', {'job_id': fx['job']}, '/job' if c is admin else '/requisitions')

# ── 2. a line with no guarantee of its own uses the agreement's ────────
body = admin.get('/job')[1]
check('A full week: 4 x 50 h x $45 + 4 x 7 x $75 + 2 x 50 h x $50 = $16,100 to the crew', '$16,100.00' in body, re.findall(r'\$[\d,]+\.\d\d', body)[:6])
check('and 4 x 50 h x $68 + 2 x 50 h x $78 = $21,400 billed (it read "No bill rate on any line yet")', '$21,400.00' in body and 'No bill rate on any line yet' not in body)

# ── 3. the recruiter raises a requisition, never overrides the rates ───
status, body = rec.get('/requisitions')
check('The recruiter is offered the requisition form without the rate overrides', 'name="order_line_id"' in body and 'name="pay_rate"' not in body)
status, body = rec.post('/requisitions', {'do': 'create', 'order_line_id': fx['lineB'], 'title': 'Walk engineer second', 'discipline': 'chemical', 'openings': '1', 'description': 'Synthetic.'})
check('and raises one against the agreement (it answered "Not your desk")', 'Requisition created' in flash(body) and probe('state')['vacancies_on_lineB'] == 2, flash(body))
# On the machinist line, which still has openings to order, so the rate is what is refused.
status, body = rec.post('/requisitions', {'do': 'create', 'order_line_id': fx['lineA'], 'title': 'Walk override', 'discipline': 'chemical', 'openings': '1', 'description': 'Synthetic.', 'pay_rate': '99'})
check('but an override of the agreed rate is refused to anybody but an administrator', status == 403 and 'Only an administrator can override' in body, (status, body[:200]))

# ── 4. screening: another role's required question does not block; answers on the phone are scored ──
status, body = rec.post('/hiring', {'do': 'move', 'application_id': fx['app1'], 'stage': 'offered', 'note': 'Report at 6:30.'}, '/hiring?v=%d&app=%d' % (fx['vA'], fx['app1']))
st = probe('state')
check('An applicant who answered their role\'s questions is offered, though another role has a required question', st['app1']['stage'] == 'offered', (flash(body), st['app1']))
check('and the person moves forward with their application (not left at "never contacted")', st['cand1_stage'] == 'offered', st['cand1_stage'])
rec.post('/screening-workflow', {'do': 'answers', 'application_id': fx['app2'], 'answer[%d]' % fx['qAll']: 'Yes', 'answer[%d]' % fx['qNum']: '6'}, '/screening-workflow?application_id=%d' % fx['app2'])
st = probe('state')
check('"Yes, I have a full-time job" taken on the phone flags the applicant (it was saved, never scored)', st['app2']['screened_out'] == 1 and st['app2']['screening_score'] is not None, st['app2'])
before = probe('state')['num_answers']
status, body = rec.post('/screening-questions', {'do': 'update', 'question_id': fx['qNum'], 'vacancy_id': fx['vA'], 'weight': '7', 'knockout_answer': '<3', 'required': '1'}, '/screening-questions?vacancy=%d' % fx['vA'])
st = probe('state')
check('Changing a question\'s weight keeps every answer already received', st['num_weight'] == 7 and st['num_answers'] == before and before > 0, (st['num_weight'], before, st['num_answers']))

# ── 5. the application asks where somebody lives; a number has a minimum ──
for email, years in (('wk-apply-one@test.invalid', '1'), ('wk-apply-six@test.invalid', '6')):
    c = Client()
    status, body = c.get('/apply?id=%d' % fx['vA'])
    check('The application asks for the city, state and ZIP code' if years == '1' else 'and takes them', all('name="%s"' % n in body for n in ('city', 'state', 'zip')))
    c.post('/apply', {'vacancy_id': fx['vA'], 'name': 'Walk applicant ' + years, 'email': email, 'phone': '', 'city': 'Pasadena', 'state': 'TX', 'zip': '77506',
                      'screening[%d]' % fx['qAll']: 'No', 'screening[%d]' % fx['qNum']: years, 'consent': '1'}, '/apply?id=%d' % fx['vA'])
applied = {a['email']: a for a in probe('state')['applied']}
check('One year where three are required is flagged; six is not', applied.get('wk-apply-one@test.invalid', {}).get('screened_out') == 1 and applied.get('wk-apply-six@test.invalid', {}).get('screened_out') == 0, applied)
check('and the ZIP code places the applicant for the distance filters', applied.get('wk-apply-six@test.invalid', {}).get('geo_from') == 'zip', applied)

# ── 6. the worker reads the terms, signs them, and the placement starts when the role does ──
w2 = Client(); w2.login('wk-w2@test.invalid')
body = w2.get('/portal')[1]
check('The offer shows its terms: pay, per diem, guaranteed hours, start, place', all(s in body for s in ('Pay: $45.00 an hour', 'Per diem: $75.00 a day', 'Guaranteed: 50 hours a week', 'Starts:', 'Where: Houston, TX')), re.sub(r'\s+', ' ', body)[-900:])
check('with the recruiter\'s note beside them, and the stage in words', 'From recruiting:' in body and 'Bring your steel-toe boots.' in body and '>Offered<' in body)
w2.post('/portal', {'do': 'accept_offer', 'application_id': fx['app3'], 'consent': '1', 'signer_name': 'Walk offered'})
# P5-M53: the office countersigns what the worker signed; that hires them.
rec.post('/hiring', {'do': 'countersign', 'application_id': fx['app3'], 'countersigner_name': 'Walk recruiter', 'reviewed': '1', 'back': '/hiring'})
st = probe('state')
check('What is signed is what was shown: the terms and the note', st['acceptance'] and 'Pay: $45.00 an hour' in st['acceptance'] and 'Bring your steel-toe boots.' in st['acceptance'], st['acceptance'])
check('and the placement starts the day the requisition does (it had none)', st['accepted_start'] == '2026-11-02', st['accepted_start'])

# ── 7. a placement's dates can be set ──────────────────────────────────
status, body = rec.post('/placements/%d' % fx['pw'], {'do': 'dates', 'start_date': '2026-10-05', 'end_date': '2027-01-29'})
check('Recruiting sets a placement\'s dates (nothing could)', probe('state')['placement_dates'] == {'start_date': '2026-10-05', 'end_date': '2027-01-29'}, flash(body))
status, body = rec.post('/placements/%d' % fx['pw'], {'do': 'dates', 'start_date': '2027-02-01', 'end_date': '2027-01-29'})
check('but not an end before the start', 'cannot come before' in flash(body) and probe('state')['placement_dates']['start_date'] == '2026-10-05', flash(body))

# ── 8. onboarding: only what the worker submitted is approved ──────────
status, body = rec.post('/onboarding', {'do': 'review', 'task_id': fx['taskPending'], 'status': 'approved'})
tasks = {t['id']: t['status'] for t in probe('state')['tasks']}
check('A task the worker never submitted cannot be approved (it opened the deployment gate)', tasks[fx['taskPending']] == 'pending' and 'Only a task the worker has submitted' in flash(body), (tasks, flash(body)))
rec.post('/onboarding', {'do': 'review', 'task_id': fx['taskSubmitted'], 'status': 'approved'})
check('and the submitted one is', {t['id']: t['status'] for t in probe('state')['tasks']}[fx['taskSubmitted']] == 'approved')

# ── 9. the supervisor marks roll call ──────────────────────────────────
body = sup.get('/my-team')[1]
check('My team offers the supervisor roll call for their crew on site', 'name="do" value="rollcall"' in body and 'data-rollcall="unmarked"' in body)
sup.post('/my-team', {'do': 'rollcall', 'placement_id': fx['pw'], 'present': '1'})
st = probe('state')
check('and marks them present (only the Deployment view could)', st['checkin'] and st['checkin']['present'] == 1 and st['checkin']['marked_by'] == IDS['sup'], st['checkin'])
status, body = sup.post('/my-team', {'do': 'rollcall', 'placement_id': 999999, 'present': '1'}, '/my-team')
check('but nobody else\'s crew', status == 404)

# ── 10. a job board's export comes in as applicants to a requisition ───
def multipart(token):
    b = '----walk'
    csvb = 'Name,Email\nWalk import one,wk-import-1@test.invalid\nWalk import two,wk-import-2@test.invalid\n'
    return ('--%s\r\nContent-Disposition: form-data; name="_csrf"\r\n\r\n%s\r\n--%s\r\nContent-Disposition: form-data; name="do"\r\n\r\npreview\r\n'
            '--%s\r\nContent-Disposition: form-data; name="sheet"; filename="board.csv"\r\nContent-Type: text/csv\r\n\r\n%s\r\n--%s--\r\n' % (b, token, b, b, csvb, b)).encode()
status, body = admin.post('/imports', {}, '/imports', raw=multipart, ctype='multipart/form-data; boundary=----walk')
check('The import asks what the people in the file become', 'name="target"' in body and 'value="applicants"' in body)
admin.post('/imports', {'do': 'commit', 'target': 'applicants', 'vacancy_id': fx['vA'], 'mapping[full_name]': '0', 'mapping[email]': '1'})
imported = probe('state')['imported']
check('and brings them in as applicants to that requisition, with no placement (they were placed as "offered")',
      len(imported) == 2 and all(i['vacancy_id'] == fx['vA'] and i['placements'] == 0 for i in imported), imported)

# ── 11. the per-connection limits can be set on the server ─────────────
rl = probe('ratelimit')
check('A limit set in the configuration replaces the default; with none set, the default holds', rl == {'first': True, 'second': False, 'default_unchanged': True}, rl)

# ── 12. in words ───────────────────────────────────────────────────────
check('Team counts in words', re.search(r'\d+ accounts? &middot;', admin.get('/people')[1]) is not None)

json.dump(results, open('work/wk-http-results.json', 'w'), indent=1)
