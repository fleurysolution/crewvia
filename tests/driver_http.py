"""P5-M09 through the screens: requesting a driving-record check with the
consent it needs (and refusing it otherwise), the licence number sealed
and only its last four shown, the link to the provider's own site; the
MVR result (validations, a licence that is not valid never acceptable, a
decision against the person needing the pre-adverse notice), the CDL and
medical certificate written into Qualifications without shortening a
better one; the Clearinghouse (information found never acceptable, a
limited query's hit opening the full query, prohibited means not
acceptable); PSP; due dates and the workflows' alerts; cancelling; the
links; who may see what; translations.
"""
import datetime, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/driver.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS, CANDS = fx['ids'], fx['cands']
TODAY = datetime.date.today()
day = lambda n: (TODAY + datetime.timedelta(days=n)).isoformat()
results = []
D = '/driver-checks'


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
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body

    def post(self, values):
        self.get(D)
        data = urllib.parse.urlencode({'_csrf': self.token, **values}).encode()
        return self.open(urllib.request.Request(base + D, data))


def user(key):
    c = Client()
    c.get('/login')
    data = urllib.parse.urlencode({'_csrf': c.token, 'email': 'dr-%s@test.invalid' % key, 'password': 'TestPassword123!'}).encode()
    status, body = c.open(urllib.request.Request(base + '/login', data))
    assert '<title>Sign in' not in body, key
    return c


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    out = subprocess.run([php, 'work/driver_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def last():
    return probe()['checks'][-1]


def qual(cand, name):
    return next((q for q in probe()['qualifications'] if int(q['candidate_id']) == cand and q['name'] == name), None)


rec, admin, hotels = user('rec'), user('admin'), user('hotels')
DANA, ELI = CANDS['dana'], CANDS['eli']
mvr = {'do': 'request', 'candidate_id': DANA, 'kind': 'mvr', 'state': 'TX', 'license_number': 'tx 1234-5678', 'consent_method': 'signed_form', 'consent_on': day(-2), 'consent_reference': 'Signed form in the hiring file'}

# ── requesting ─────────────────────────────────────────────────────────
for label, override in [('a state that does not exist', {'state': 'ZZ'}), ('a licence number of 3 characters', {'license_number': 'AB1'}),
                        ('the wrong consent for an MVR', {'consent_method': 'clearinghouse_portal'}), ('a consent dated tomorrow', {'consent_on': day(1)})]:
    check('A request with %s is refused' % label, rec.post({**mvr, **override})[0] == 422 and probe()['checks'] == [])
check('A Clearinghouse request without the kind of query is refused', rec.post({**mvr, 'kind': 'clearinghouse', 'query_type': ''})[0] == 422)
check('and a full query on a consent kept by the agency: the driver consents in the Clearinghouse', rec.post({**mvr, 'kind': 'clearinghouse', 'query_type': 'full'})[0] == 422)
check('and a PSP without the signed PSP disclosure', rec.post({**mvr, 'kind': 'psp'})[0] == 422)
status, body = rec.post(mvr)
c = last()
check('A recruiter requests an MVR on the signed consent', c['kind'] == 'mvr' and c['status'] == 'requested' and c['consent_method'] == 'signed_form' and c['consent_on'] == day(-2))
check('The licence number is sealed (normalised), and only its last four kept in clear', c['license_opened'] == 'TX12345678' and 'TX12345678' not in c['encrypted_license_number']
      and c['license_last4'] == '5678')
check('The page gives the last four only, and the link to MVRNOW in a new tab', 'licence ending 5678' in body and 'TX12345678' not in body
      and re.search(r'id="provider-link" href="https://www\.mvrnow\.com/" target="_blank" rel="noopener noreferrer">Open MVRNOW<', body), body[:300])
check('Asking for the same check again while it waits is refused', rec.post(mvr)[0] == 422)
MVR = int(c['id'])

# ── the MVR result ─────────────────────────────────────────────────────
res = {'do': 'record', 'check_id': MVR, 'result_on': day(0), 'provider_reference': 'MVRN-77812', 'license_status': 'valid', 'cdl': '1', 'cdl_class': 'A', 'endorsements': 'h n',
       'license_expires_on': '2028-06-30', 'medical_expires_on': '2027-03-31', 'violations_3y': '1', 'accidents_3y': '0', 'decision': 'acceptable', 'notes': ''}
for label, override in [('a date before the consent', {'result_on': day(-3)}), ('a date in the future', {'result_on': day(1)}), ('no decision', {'decision': ''}),
                        ('a CDL with no class', {'cdl_class': ''}), ('an endorsement that does not exist', {'endorsements': 'HQ'}),
                        ('a valid CDL with no expiry', {'license_expires_on': ''}), ('violations of 100', {'violations_3y': '100'}),
                        ('a suspended licence recorded as acceptable', {'license_status': 'suspended'}),
                        ('not acceptable without the pre-adverse notice', {'decision': 'not_acceptable', 'notes': 'Two speeding tickets last year'}),
                        ('not acceptable with a short reason', {'decision': 'not_acceptable', 'notes': 'No', 'adverse_notice_on': day(0)})]:
    check('An MVR result with %s is refused' % label, rec.post({**res, **override})[0] == 422 and next(x for x in probe()['checks'] if int(x['id']) == MVR)['status'] == 'requested')
status, body = rec.post(res)
c = next(x for x in probe()['checks'] if int(x['id']) == MVR)
next_year = (TODAY.replace(year=TODAY.year + 1) if not (TODAY.month == 2 and TODAY.day == 29) else TODAY.replace(year=TODAY.year + 1, day=28)).isoformat()
check('The MVR is recorded: valid CDL class A, endorsements H and N, acceptable, due again in a year', c['status'] == 'completed' and c['cdl_class'] == 'A' and c['endorsements'] == 'HN'
      and c['decision'] == 'acceptable' and c['next_due_on'] == next_year and int(c['recorded_by']) == IDS['rec'], c)
cdl = qual(DANA, "Commercial driver's licence (CDL)")
check('The CDL goes into Dana\'s qualifications, verified, with its expiry and the MVR as evidence', cdl and cdl['status'] == 'verified' and cdl['expires_on'] == '2028-06-30'
      and cdl['detail'] == 'Class A · HN · TX …5678' and cdl['evidence_reference'] == 'MVR TX %s MVRN-77812' % day(0), cdl)
med = qual(DANA, 'DOT medical certificate')
check('but her medical certificate, already verified until 2035, is not shortened to 2027', med['expires_on'] == '2035-01-01' and med['evidence_reference'] == 'MED-1')
check('The qualification\'s history says it came from a driving record', any('driving record' in e['note'] for e in probe()['qualification_events']))
check('and so does the person\'s history', probe()['events'][-1]['detail'] == 'Motor vehicle record (MVRNOW): Acceptable')
check('A recorded check is not recorded twice', rec.post(res)[0] == 422)
status, body = rec.get(D + '?check=%d' % MVR)
check('Its page shows the result and when it is due again', 'id="result" data-decision="acceptable"' in body and 'id="next-due"' in body and 'id="result-form"' not in body)
rec.post({**mvr, 'candidate_id': ELI, 'license_number': 'TX99887766'})
eli_mvr = int(last()['id'])
rec.post({**res, 'check_id': eli_mvr, 'license_status': 'suspended', 'cdl': '', 'decision': 'not_acceptable', 'notes': 'Licence suspended for unpaid tickets', 'adverse_notice_on': day(0), 'medical_expires_on': '2027-01-31'})
e = next(x for x in probe()['checks'] if int(x['id']) == eli_mvr)
check('A suspended licence, not acceptable, with the pre-adverse notice dated', e['decision'] == 'not_acceptable' and e['adverse_notice_on'] == day(0))
check('writes no CDL, but the medical certificate on the record is kept', qual(ELI, "Commercial driver's licence (CDL)") is None and qual(ELI, 'DOT medical certificate')['expires_on'] == '2027-01-31')

# ── the Clearinghouse ──────────────────────────────────────────────────
rec.post({**mvr, 'kind': 'clearinghouse', 'query_type': 'limited', 'consent_reference': 'Multi-year limited query consent, 2026'})
LIM = int(last()['id'])
check('A limited query on the agency\'s kept consent is requested', last()['query_type'] == 'limited')
status, body = rec.get(D + '?check=%d' % LIM)
check('and opens the Clearinghouse\'s own site', 'href="https://clearinghouse.fmcsa.dot.gov/"' in body)
ch = {'do': 'record', 'check_id': LIM, 'result_on': day(0), 'clearinghouse_result': 'information_found', 'decision': 'acceptable', 'notes': ''}
check('Information found cannot be recorded as acceptable', rec.post(ch)[0] == 422)
rec.post({**ch, 'decision': 'review', 'notes': 'Hit on the limited query'})
follow = last()
check('Recorded for review, it opens the full query that must follow, on the driver\'s consent in the Clearinghouse',
      follow['kind'] == 'clearinghouse' and follow['query_type'] == 'full' and follow['status'] == 'requested' and int(follow['follows_check_id']) == LIM
      and follow['consent_method'] == 'clearinghouse_portal' and follow['license_opened'] == 'TX12345678')
status, body = rec.get(D + '?candidate=%d' % DANA)
check('The person\'s page shows it as following the limited query', 'follows a limited query' in body and 'data-check="%s" data-status="requested"' % follow['id'] in body)
full = {'do': 'record', 'check_id': follow['id'], 'result_on': day(0), 'clearinghouse_result': 'prohibited', 'decision': 'review', 'notes': 'Positive test reported by the previous employer'}
check('A prohibited driver cannot be recorded as anything but not acceptable', rec.post(full)[0] == 422)
rec.post({**full, 'decision': 'not_acceptable'})
f = next(x for x in probe()['checks'] if x['id'] == follow['id'])
check('Recorded not acceptable; the Clearinghouse needs no pre-adverse notice', f['decision'] == 'not_acceptable' and f['adverse_notice_on'] is None and f['next_due_on'] is not None)

# ── PSP ────────────────────────────────────────────────────────────────
rec.post({**mvr, 'kind': 'psp', 'consent_method': 'psp_form'})
PSP = int(last()['id'])
psp = {'do': 'record', 'check_id': PSP, 'result_on': day(0), 'crashes_5y': '1', 'inspections_3y': '4', 'oos_3y': '5', 'decision': 'acceptable', 'notes': ''}
check('More out-of-service violations than inspections is refused', rec.post(psp)[0] == 422)
rec.post({**psp, 'oos_3y': '1'})
p = next(x for x in probe()['checks'] if int(x['id']) == PSP)
check('A PSP is recorded with its crashes and inspections, and is not due again', p['status'] == 'completed' and int(p['crashes_5y']) == 1 and int(p['inspections_3y']) == 4 and p['next_due_on'] is None)

# ── due again, and the workflows ───────────────────────────────────────
status, body = rec.get(D)
check('The overview shows Dana\'s MVR current', 'data-latest="%d" data-due="current"' % MVR in body)
probe('age', MVR, day(-340), day(25))
status, body = rec.get(D)
check('Within the month of its year, it is due', 'data-latest="%d" data-due="due"' % MVR in body)
out = subprocess.run([php, 'work/test-app/install/run-workflows.php'], capture_output=True, text=True)
alerts = [n for n in probe()['notifications'] if int(n['user_id']) == IDS['rec'] and n['target'] == '/driver-checks?candidate=%d' % DANA]
check('The scheduled workflows tell the recruiters', out.returncode == 0 and len(alerts) >= 1 and 'Motor vehicle record (MVRNOW) for Dana Driver is due on' in alerts[0]['message'], out.stdout + out.stderr)
subprocess.run([php, 'work/test-app/install/run-workflows.php'], capture_output=True, text=True)
check('once', len([n for n in probe()['notifications'] if int(n['user_id']) == IDS['rec'] and n['target'] == '/driver-checks?candidate=%d' % DANA]) == len(alerts))
probe('age', MVR, day(-400), day(-35))
status, body = rec.get(D)
check('Past its year, it is overdue', 'data-latest="%d" data-due="overdue"' % MVR in body)
rec.post({**mvr, 'consent_on': day(0)})
before = len(probe()['notifications'])
subprocess.run([php, 'work/test-app/install/run-workflows.php'], capture_output=True, text=True)
check('With a new MVR already waiting for Dana, nobody is told again', len([n for n in probe()['notifications'][before:] if n['target'] == '/driver-checks?candidate=%d' % DANA]) == 0)
waiting = int(last()['id'])
check('Cancelling needs a reason', rec.post({'do': 'cancel', 'check_id': waiting, 'reason': ''})[0] == 422)
rec.post({'do': 'cancel', 'check_id': waiting, 'reason': 'Driver left before the review'})
check('and is kept with it', next(x for x in probe()['checks'] if int(x['id']) == waiting)['status'] == 'cancelled')

# ── links, access, the person's page ───────────────────────────────────
check('An administrator\'s link must be HTTPS', admin.post({'do': 'links', 'mvr': 'http://mvr.example', 'clearinghouse': '', 'psp': ''})[0] == 422)
admin.post({'do': 'links', 'mvr': 'https://www.mvrnow.com/login', 'clearinghouse': '', 'psp': ''})
check('and is used from then on; the others keep their sites', probe()['links']['mvr'] == 'https://www.mvrnow.com/login' and probe()['links']['psp'] == 'https://www.psp.fmcsa.dot.gov/')
check('A recruiter does not change the links', rec.post({'do': 'links', 'mvr': 'https://elsewhere.example/', 'clearinghouse': '', 'psp': ''})[0] == 403)
check('Logistics does not open driving records', hotels.get(D)[0] == 403 and hotels.get(D + '?check=%d' % MVR)[0] == 403)
status, body = rec.get('/candidates/%d' % DANA)
check('The person\'s page links to their driving records', 'href="/driver-checks?candidate=%d"' % DANA in body)
status, body = rec.get(D + '?lang=fr')
check('Driving records in French', 'Dossiers de conduite' in body)
rec.get(D + '?lang=en')

json.dump(results, open('work/dr-http-results.json', 'w'), indent=1)
