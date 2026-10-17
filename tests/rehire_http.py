"""P5-M51 through the screens: the do-not-rehire register holds the person wherever they turn up.
  - set on a record (DO NOT USE, with a reason), Hiring shows it in red in the list and on the application, with the reason;
  - moving forward is refused (Screening, the manager's review, the portal invitation, alone or in bulk); rejecting is not;
  - the same person applying again on the public page (same email) or added by hand (same phone, written differently)
    is held the same way, the banner saying how they were recognised;
  - "DO NOT USE" keeps them out of the talent pool's "add to this requisition";
  - lifting the block on the record frees them.
Runs after bulk_view_http.py, on the walkthrough fixture.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
wk = json.load(open('work/walkthrough.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []
CRLF = chr(13) + chr(10)


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

    def multipart(self, path, page, fields):
        self.get(page)
        b = '----rehire'
        body = b''.join(('--' + b + CRLF + 'Content-Disposition: form-data; name="%s"' % k + CRLF + CRLF + str(v) + CRLF).encode() for k, v in [('_csrf', self.token)] + list(fields.items()))
        body += ('--' + b + '--' + CRLF).encode()
        return self.open(urllib.request.Request(base + path, body, {'Content-Type': 'multipart/form-data; boundary=' + b, 'Accept': 'text/html'}))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS ' + label)


def probe(script, *args):
    return json.loads(subprocess.run([php, 'work/%s_probe.php' % script, *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


rec = Client(); rec.login('wk-rec@test.invalid')
two = probe('reviews', 'app', 'in-two@test.invalid')
cand = probe('availability', 'candidate', 'in-two@test.invalid')
WHY = 'Walked off the Gary job on day two without notice. Supervisor: Jack.'
rec.post('/candidates/%d' % cand['id'], {'do': 'rehire', 'rehire_status': 'do_not_use', 'exclusion_reason': WHY}, '/candidates/%d' % cand['id'])

page = '/hiring?v=%d&app=%d' % (wk['vA'], two['id'])
status, body = rec.get('/hiring?v=%d&stage=all' % wk['vA'])
check('A held applicant is not in Hiring (P5-M93)', ('data-row-blocked="%d"' % two['id']) not in body)
status, body = rec.get(page)
check('The application carries the banner, with the reason, and offers nothing that moves them forward', 'id="rehire-block"' in body and WHY in body and 'This person is on the do-not-rehire register' in body
      and 'id="pass-to-manager"' not in body and 'id="schedule-interview"' not in body)

status, body = rec.post('/hiring', {'do': 'move', 'application_id': two['id'], 'stage': 'screening', 'note': 'x', 'back': page}, page)
check('Moving them forward is refused', probe('reviews', 'app', 'in-two@test.invalid')['stage'] == two['stage'] and 'do-not-rehire register' in body)
status, body = rec.post('/hiring', {'do': 'review_request', 'application_id': two['id'], 'reviewer_id': 1, 'rating': 5, 'recommendation': 'x', 'back': page}, page)
check('So is passing them to a manager', 'do-not-rehire register' in body)
before = probe('availability', 'outbox', 'in-two@test.invalid')
status, body = rec.post('/hiring', {'do': 'bulk', 'vacancy_id': wk['vA'], 'bulk_action': 'invite', 'apps[]': [two['id']], 'back': page}, page)
check('and inviting them to a portal, even in bulk', probe('availability', 'outbox', 'in-two@test.invalid') == before and 'do-not-rehire register' in body, re.findall(r'class="flash[^"]*"[^>]*>(.*?)</', body, re.S)[:1])
status, body = rec.post('/hiring', {'do': 'add', 'vacancy_id': wk['vB'], 'candidate_id': cand['id'], 'back': '/hiring?v=%d&tab=matches' % wk['vB']}, '/hiring?v=%d&tab=matches' % wk['vB'])
check('DO NOT USE keeps them out of another requisition from the talent pool', 'do-not-rehire register' in body)

# The same person, again, as a new record.
visitor = Client()
visitor.get('/apply?id=%d' % wk['vB'])
visitor.multipart('/apply?id=%d' % wk['vB'], '/apply?id=%d' % wk['vB'], {'vacancy_id': wk['vB'], 'name': 'Indeed T. Two', 'email': 'in-two@test.invalid', 'availability': 'available', 'consent': '1'})
again = probe('reviews', 'app', 'in-two@test.invalid')
status, body = rec.get('/hiring?v=%d&app=%d' % (again['vacancy_id'], again['id']))
check('Applying again on the public page (same email) is held too, saying how they were recognised',
      again['id'] != two['id'] and 'id="rehire-block"' in body and 'same email' in body and WHY in body, body[:300])
status, body, = rec.post('/hiring', {'do': 'move', 'application_id': again['id'], 'stage': 'screening', 'note': 'x', 'back': '/hiring?v=%d' % again['vacancy_id']}, '/hiring?v=%d&app=%d' % (again['vacancy_id'], again['id']))
check('and cannot be moved forward', probe('reviews', 'app', 'in-two@test.invalid')['stage'] == 'new')

rec.multipart('/hiring', '/hiring?v=%d&tab=add' % wk['vB'], {'do': 'add_applicant', 'vacancy_id': wk['vB'], 'first_name': 'Phone', 'last_name': 'Twin', 'email': 'phone-twin@test.invalid',
                                                              'phone': '(555) 010-0002', 'channel': 'phone'})
twin = probe('reviews', 'app', 'phone-twin@test.invalid')
status, body = rec.get('/hiring?v=%d&app=%d' % (twin['vacancy_id'], twin['id']))
check('Added by hand with the same phone, written differently, is held too', 'id="rehire-block"' in body and 'same phone' in body, body[:300])

status, body = rec.post('/hiring', {'do': 'reject', 'application_id': again['id'], 'note': 'On the register.', 'back': '/hiring?v=%d' % again['vacancy_id']}, '/hiring?v=%d&app=%d' % (again['vacancy_id'], again['id']))
check('Rejecting stays possible', probe('reviews', 'app', 'in-two@test.invalid')['stage'] == 'rejected')

rec.post('/candidates/%d' % cand['id'], {'do': 'rehire', 'rehire_status': 'eligible', 'exclusion_reason': ''}, '/candidates/%d' % cand['id'])
status, body = rec.post('/hiring', {'do': 'move', 'application_id': twin['id'], 'stage': 'screening', 'note': 'Cleared.', 'back': '/hiring?v=%d' % twin['vacancy_id']}, '/hiring?v=%d&app=%d' % (twin['vacancy_id'], twin['id']))
check('Lifted on the record that carries it, everybody it held is free again', probe('reviews', 'app', 'phone-twin@test.invalid')['stage'] == 'screening')

json.dump(results, open('work/rh-http-results.json', 'w'), indent=1)
