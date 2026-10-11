"""P4-M05 through the screens: ADP in sandbox produces TEST files, every
file kept with its SHA-256; live refused before ADP accepted a test file
and without an authorization; ADP's import recorded; live files; a kept
file refused once altered; disabled; ADP's register compared with
Crewvia's frozen pay. The applicant-tracking partner channel in sandbox
answers and creates nothing, goes live only with an authorization, then
creates the application once. Screening stays unconnected. Translations.
"""
import hashlib, hmac, http.cookiejar, json, os, re, subprocess, time, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/connectors.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []
WEEK = fx['week']
SECRET = 'unit-test-secret-unit-test-secret'


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                return r.status, dict(r.headers), r.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read().decode()

    def get(self, path):
        status, headers, body = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        self.headers = headers
        return status, body

    def post(self, path, values):
        status, headers, body = self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()))
        assert 'Fatal error' not in body and 'Warning:' not in body, body[:800]
        return status, body

    def upload(self, path, values, field, filename, content):
        boundary = 'p4m05boundary' + str(int(time.time()))
        parts = []
        for k, v in {'_csrf': self.token, **values}.items():
            parts.append('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v))
        parts.append('--%s\r\nContent-Disposition: form-data; name="%s"; filename="%s"\r\nContent-Type: text/csv\r\n\r\n%s\r\n--%s--\r\n' % (boundary, field, filename, content, boundary))
        req = urllib.request.Request(base + path, ''.join(parts).encode(), headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
        status, headers, body = self.open(req)
        assert 'Fatal error' not in body and 'Warning:' not in body, body[:800]
        return status, body

    def login(self, email):
        self.get('/login')
        status, body = self.post('/login', {'email': email, 'password': 'TestPassword123!'})
        return (status if '<title>Sign in' not in body else 401), body


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:600] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    out = subprocess.run([php, 'work/connectors_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def mode(client, connector, value, authorization='', note=''):
    client.get('/integrations')
    return client.post('/integrations', {'do': 'mode', 'connector': connector, 'mode': value, 'authorization': authorization, 'note': note})


admin = Client(); check('P4-M05 admin login', admin.login('cn-admin@test.invalid')[0] == 200)
payroll = Client(); check('P4-M05 payroll login', payroll.login('cn-payroll@test.invalid')[0] == 200)
payroll.get('/payroll-export')
payroll.post('/select-project', {'job_id': fx['job']})
admin.get('/projects')
admin.post('/select-project', {'job_id': fx['job']})

# ── ADP in sandbox ───────────────────────────────────────────────────────
status, body = payroll.get('/payroll-export?week=' + WEEK)
check('The payroll page shows ADP in sandbox', status == 200 and 'id="adp-connector" data-mode="sandbox"' in body)
status, body = payroll.get('/payroll-export?week=%s&export=adp_run' % WEEK)
headers = payroll.headers
check('In sandbox the ADP file is marked TEST', status == 200 and 'adp-run-TEST-' + WEEK in headers.get('Content-Disposition', '') and body.startswith('"##GENERIC## V1.0"'), (status, headers.get('Content-Disposition'), repr(body[:300])))
check('It carries the three employees under the configured codes', all(',P4A%d,REG,' % n in body for n in (1, 2, 3)) and 'P4CO,W,06/07/2015,06/13/2015' in body, body[:300])
test_id = int(headers['X-Payroll-Export'])
x = next(e for e in probe()['exports'] if int(e['id']) == test_id)
check('The file is kept with its SHA-256, as a test file of 3 employees', x['kind'] == 'adp_test' and x['sha256'] == hashlib.sha256(body.encode()).hexdigest()
      and int(x['employee_count']) == 3 and headers.get('X-Content-SHA256') == x['sha256'], x)
status, body = payroll.get('/payroll-export?week=%s&export=review' % WEEK)
check('The review file is kept too', status == 200 and next(e for e in probe()['exports'] if int(e['id']) == int(payroll.headers['X-Payroll-Export']))['kind'] == 'review')

check('Live is refused before ADP has accepted a test file', mode(admin, 'adp_run', 'live', 'RSS payroll lead, email of 10 Oct 2026')[0] == 422
      and probe()['modes']['adp_run']['mode'] == 'sandbox')
payroll.get('/payroll-export?week=' + WEEK)
check('Recording the import without ADP\'s batch reference is refused', payroll.post('/payroll-export?week=' + WEEK, {'do': 'confirm_import', 'export_id': test_id, 'batch_ref': '', 'result': 'accepted'})[0] == 422)
check('A rejection without what ADP reported is refused', payroll.post('/payroll-export?week=' + WEEK, {'do': 'confirm_import', 'export_id': test_id, 'batch_ref': 'PRV-0001', 'result': 'rejected'})[0] == 422)
check('Payroll records that ADP\'s preview accepted the test file', payroll.post('/payroll-export?week=' + WEEK, {'do': 'confirm_import', 'export_id': test_id, 'batch_ref': 'PRV-0001', 'result': 'accepted', 'note': 'Preview without errors'})[0] == 200)
m = probe()['modes']['adp_run']
check('That is the sandbox trial passing', m['sandbox_passed_at'] is not None and next(e for e in probe()['exports'] if int(e['id']) == test_id)['adp_batch_ref'] == 'PRV-0001')
check('An import already recorded is not recorded again', payroll.post('/payroll-export?week=' + WEEK, {'do': 'confirm_import', 'export_id': test_id, 'batch_ref': 'PRV-0002', 'result': 'accepted'})[0] == 422)

check('Payroll cannot switch connectors', payroll.get('/integrations')[0] == 403)
check('Live without saying who authorized it is refused', mode(admin, 'adp_run', 'live', '')[0] == 422)
check('An administrator switches ADP live, recording the authorization', mode(admin, 'adp_run', 'live', 'RSS payroll lead, email of 10 Oct 2026')[0] == 200
      and probe()['modes']['adp_run']['mode'] == 'live' and int(probe()['modes']['adp_run']['authorized_by']) == fx['ids']['admin'])
status, body = payroll.get('/payroll-export?week=%s&export=adp_run' % WEEK)
live_id = int(payroll.headers['X-Payroll-Export'])
check('Live, the file is the real one', status == 200 and 'adp-run-' + WEEK in payroll.headers.get('Content-Disposition', '') and 'TEST' not in payroll.headers.get('Content-Disposition', '')
      and next(e for e in probe()['exports'] if int(e['id']) == live_id)['kind'] == 'adp_live')
status, again = payroll.get('/payroll-export?week=%s&download=%d' % (WEEK, live_id))
check('A kept file downloads again exactly as it was made', status == 200 and again == body)
probe('tamper', live_id)
check('Altered after it was made, it is refused', payroll.get('/payroll-export?week=%s&download=%d' % (WEEK, live_id))[0] == 409)

check('An administrator switches ADP off', mode(admin, 'adp_run', 'disabled', '', 'Paused for the year end')[0] == 200)
check('Off, no ADP file is produced', payroll.get('/payroll-export?week=%s&export=adp_run' % WEEK)[0] == 422)
check('but the review file still is', payroll.get('/payroll-export?week=%s&export=review' % WEEK)[0] == 200)
check('Back live, with the authorization recorded again', mode(admin, 'adp_run', 'live', 'RSS payroll lead, email of 10 Oct 2026')[0] == 200)

# ── ADP's register beside Crewvia ────────────────────────────────────────
register = 'Employee ID,Name,Gross Pay,Net Pay\r\nP4A1,Ada,"1,200.00",900.00\r\nP4A2,Ben,990.00,760.00\r\nP4A9,Somebody,$500.00,400.00\r\n'
payroll.get('/payroll-export?week=' + WEEK)
check('A register whose columns are not found is refused', payroll.upload('/payroll-export?week=' + WEEK, {'do': 'reconcile', 'id_column': 'Associate ID', 'gross_column': 'Gross Pay'}, 'register', 'register.csv', register)[0] == 422)
check('A gross pay that is not an amount is refused', payroll.upload('/payroll-export?week=' + WEEK, {'do': 'reconcile', 'id_column': 'Employee ID', 'gross_column': 'Name'}, 'register', 'register.csv', register)[0] == 422)
status, body = payroll.upload('/payroll-export?week=' + WEEK, {'do': 'reconcile', 'id_column': 'Employee ID', 'gross_column': 'Gross Pay'}, 'register', 'register.csv', register)
status, body = payroll.get('/payroll-export?week=' + WEEK)
state = dict(re.findall(r'data-recon="([^"]+)" data-state="([^"]+)"', body))
check('ADP\'s register beside Crewvia: Ada matches, Ben differs, Cy is missing from ADP, an unknown id is in ADP only',
      state == {'P4A1': 'matched', 'P4A2': 'different', 'P4A3': 'only_in_crewvia', 'P4A9': 'only_in_adp'}, state)
check('1 matched, 3 to look into, with the 10 of difference shown', 'data-matched="1" data-differences="3"' in body and '$-10.00' in body)
check('The column names are remembered for next time', 'name="id_column" value="Employee ID"' in body)
status, body = admin.get('/integrations?provider=adp')
check('Every ADP step is in the audit trail', all(s in body for s in ['test file', 'import', 'sandbox', 'mode', 'import file', 'reconcile']))

# ── the applicant-tracking partner ───────────────────────────────────────
def application(event_id, email, secret=SECRET):
    body = json.dumps({'schema': 'workforce.application.v1', 'event_id': event_id, 'vacancy_id': fx['vacancy'], 'name': 'Partner Applicant', 'email': email, 'phone': '', 'consent': True}).encode()
    stamp = str(int(time.time()))
    signature = hmac.new(secret.encode(), (stamp + '.').encode() + body, hashlib.sha256).hexdigest()
    req = urllib.request.Request(base + '/recruiting-webhook?channel=test_partner', body, headers={'Content-Type': 'application/json', 'X-Workforce-Timestamp': stamp, 'X-Workforce-Signature': signature})
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode() or '{}')


check('The partner channel configured before is live', probe()['modes']['ats:test_partner']['mode'] == 'live')
check('An administrator puts it back in sandbox', mode(admin, 'ats:test_partner', 'sandbox', '', 'New partner build to test')[0] == 200)
status, data = application('p4m05-1', 'partner-one@test.invalid')
check('In sandbox a signed application is verified and answered, and nothing is created', status == 202 and data == {'sandbox': True, 'valid': True, 'vacancy_open': True, 'created': False}
      and not probe()['partner'], (status, data))
check('which is the sandbox trial passing', probe()['modes']['ats:test_partner']['sandbox_passed_at'] is not None)
status, data = application('p4m05-2', 'partner-two@test.invalid', 'a-wrong-secret-a-wrong-secret-xx')
check('A wrong signature is refused', status == 401)
check('Live without an authorization is refused', mode(admin, 'ats:test_partner', 'live', '')[0] == 422)
check('With the partner agreement recorded, it goes live', mode(admin, 'ats:test_partner', 'live', 'Partner agreement ref TP-2026-01')[0] == 200)
status, data = application('p4m05-1', 'partner-one@test.invalid')
check('Live, the same event creates the application for review', status == 201 and data.get('duplicate') is False and len(probe()['partner']) == 1, (status, data))
status, data = application('p4m05-1', 'partner-one@test.invalid')
check('and sent again it is a duplicate, created once', status == 200 and data.get('duplicate') is True and len(probe()['partner']) == 1)
check('Switched off', mode(admin, 'ats:test_partner', 'disabled')[0] == 200)
status, data = application('p4m05-3', 'partner-three@test.invalid')
check('a correctly signed application is refused like an unknown one', status == 401 and len(probe()['partner']) == 1)
mode(admin, 'ats:test_partner', 'live', 'Partner agreement ref TP-2026-01')
status, body = admin.get('/integrations?provider=ats')
check('The partner\'s events are in the audit trail, sandbox, refusals and duplicate included', all(s in body for s in ['Sandbox event p4m05-1 verified', 'created application', 'already received', 'signature did not match', 'switched off']))
status, body = admin.get('/integrations')
check('Background screening is shown as not connected', 'data-connector="screening"' in body and 'No screening provider is authorized' in body)
check('An unknown connector cannot be set', mode(admin, 'ats:nobody', 'live', 'x y z')[0] == 422)

status, body = payroll.get('/payroll-export?week=%s&lang=fr' % WEEK)
check('The ADP connector is translated into French', 'Connecteur ADP' in body)
status, body = admin.get('/integrations?lang=es')
check('and the connectors into Spanish', 'Conectores de nómina, seguimiento de candidatos y verificación' in body)
payroll.get('/payroll-export?lang=en')
admin.get('/integrations?lang=en')

json.dump(results, open('work/cn-http-results.json', 'w'), indent=1)
