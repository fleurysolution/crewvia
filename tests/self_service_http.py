"""HR self-service through the screens: a worker proposes bank details and
detail changes, staff validate them, and only then does anything change.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
ss = json.load(open('work/ss.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def _send(self, req):
        try:
            with self.opener.open(req) as r:
                body, status = r.read().decode(), r.status
        except urllib.error.HTTPError as e:
            body, status = e.read().decode(), e.code
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body

    def get(self, path):
        return self._send(base + path)

    def post(self, path, values):
        return self._send(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()))

    def login(self, email):
        self.get('/login')
        status, body = self.post('/login', {'email': email, 'password': 'TestPassword123!'})
        return (status if '<title>Sign in' not in body else 401), body


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:600] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(cid=None):
    return json.loads(subprocess.run([php, 'work/self_service_probe.php', str(cid or ss['me'])], check=True, capture_output=True, text=True).stdout)


def mine(values):
    worker.get('/employee-folder')
    return worker.post('/employee-folder', values)


def decide(client, kind, rid, decision, note=''):
    client.get('/change-requests')
    return client.post('/change-requests', {'do': kind, 'request_id': rid, 'decision': decision, 'note': note})


worker = Client(); check('Self-service worker login', worker.login('ss-worker@test.invalid')[0] == 200)
payroll = Client(); check('Self-service payroll login', payroll.login('ss-payroll@test.invalid')[0] == 200)
recruiter = Client(); check('Self-service recruiter login', recruiter.login('ss-recruiter@test.invalid')[0] == 200)
hotels = Client(); check('Self-service hotels login', hotels.login('ss-hotels@test.invalid')[0] == 200)

status, body = worker.get('/employee-folder')
check('The worker sees their own details and the bank account in use, by its last four only',
      status == 200 and 'My details' in body and 'In use: Old Bank, account ending 1111' in body and '99991111' not in body)

# ── bank details ─────────────────────────────────────────────────────────
good = {'do': 'self_bank', 'account_holder': 'Self-service worker', 'bank_name': 'New Bank', 'routing_number': '021000021', 'account_number': '123456789012'}
for label, change in [('Letters in an account number are refused', {'account_number': 'abc12345'}),
                      ('A routing number that fails its checksum is refused', {'routing_number': '021000022'}),
                      ('An 8-digit routing number is refused', {'routing_number': '02100002'}),
                      ('A bank with no name is refused', {'bank_name': ''})]:
    status, body = mine({**good, **change})
    check(label, status == 422, (status, body[:200]))
check('Refused bank details wrote nothing', probe()['bank_requests'] == [])
check('The worker sends valid bank details', mine(good)[0] == 200)
p = probe()
check('They wait to be checked, ending 9012', [(r['last_four'], r['status']) for r in p['bank_requests']] == [('9012', 'pending')])
check('The account number is not stored in plain', '123456789012' not in p['bank_requests'][0]['encrypted_details'])
check('The details in use are not replaced yet', p['bank_in_use']['last_four'] == '1111')
check('Sending again while they are checked is refused', mine({**good, 'account_number': '555566667777'})[0] == 422)
status, body = worker.get('/employee-folder')
check('The worker sees the proposal by its last four, never the number', 'ending 9012' in body and 'Waiting to be checked' in body and '123456789012' not in body)

check('Hotels cannot open change requests', hotels.get('/change-requests')[0] == 403)
check('A recruiter does not see bank details to check', 'Bank details to check' not in recruiter.get('/change-requests')[1])
status, body = payroll.get('/change-requests')
rid = p['bank_requests'][0]['id']
check('Payroll sees the proposal, numbers hidden', 'data-bank-request="%s"' % rid in body and '123456789012' not in body and 'replaces the account ending 1111' in body)
status, body = payroll.get('/change-requests?reveal=%s' % rid)
check('Shown on request, and the look is recorded', '123456789012' in body and 'revealed a proposal' in probe()['access'])
check('Refusing needs a reason', decide(payroll, 'bank', rid, 'reject')[0] == 422)
check('Payroll refuses them with a reason', decide(payroll, 'bank', rid, 'reject', 'Account number does not match the cheque')[0] == 200
      and probe()['bank_requests'][0]['status'] == 'rejected' and probe()['bank_in_use']['last_four'] == '1111')
check('The worker sees the refusal and why', 'Account number does not match the cheque' in worker.get('/employee-folder')[1])
check('A decided proposal is not decided again', decide(payroll, 'bank', rid, 'approve')[0] == 422)
mine({**good, 'account_number': '123456780000'})
rid2 = probe()['bank_requests'][-1]['id']
check('A recruiter cannot accept bank details', decide(recruiter, 'bank', rid2, 'approve')[0] == 403)
check('Payroll accepts the corrected details', decide(payroll, 'bank', rid2, 'approve')[0] == 200)
p = probe()
check('Only now are they in use: ending 0000, verified', p['bank_in_use'] == {'last_four': '0000', 'bank_label': 'New Bank', 'status': 'verified'}, p['bank_in_use'])

# ── personal details ─────────────────────────────────────────────────────
check('The worker asks to change their phone', mine({'do': 'self_detail', 'field': 'phone', 'value': '555 0199', 'reason': 'New phone'})[0] == 200)
for label, values in [('The same change twice while it waits is refused', {'field': 'phone', 'value': '555 0188'}),
                      ('Asking for what is already on file is refused', {'field': 'city', 'value': 'Gary'}),
                      ('An invalid email is refused', {'field': 'email', 'value': 'not-an-email'}),
                      ('A detail workers do not change is refused', {'field': 'ssn', 'value': '123-45-6789'})]:
    status, body = mine({'do': 'self_detail', **values})
    check(label, status == 422, (status, body[:200]))
check('The phone is unchanged until it is checked', probe()['candidate']['phone'] == '555 0100')
d = next(r for r in probe()['details'] if r['field'] == 'phone')
check('Payroll does not decide detail changes', decide(payroll, 'detail', d['id'], 'approve')[0] == 403)
check('A recruiter accepts it, and only then the phone changes',
      decide(recruiter, 'detail', d['id'], 'approve')[0] == 200 and probe()['candidate']['phone'] == '555 0199' and probe()['events'] == ['detail change approved'])
mine({'do': 'self_detail', 'field': 'city', 'value': 'Detroit'})
c = next(r for r in probe()['details'] if r['field'] == 'city')
check('Refusing a change needs a reason', decide(recruiter, 'detail', c['id'], 'reject')[0] == 422)
check('Refused with a reason, the city stays', decide(recruiter, 'detail', c['id'], 'reject', 'Send proof of address first')[0] == 200 and probe()['candidate']['city'] == 'Gary')

# ── nothing has changed ──────────────────────────────────────────────────
check('The worker says nothing has changed', mine({'do': 'self_confirm'})[0] == 200
      and len(probe()['confirmations']) == 1 and probe()['confirmations'][0]['placement_id'] is not None)
check('and sees when they last said so', 'Last confirmed unchanged on' in worker.get('/employee-folder')[1])
check('The recruiter sees it on the folder', 'Last confirmed unchanged on' in recruiter.get('/employee-folder?id=%d' % ss['me'])[1])

# ── nobody else's record ─────────────────────────────────────────────────
mine({'do': 'self_detail', 'candidate_id': ss['other'], 'field': 'state', 'value': 'MI'})
check('A request with somebody else\'s id is made on the worker\'s own record', probe(ss['other'])['details'] == []
      and any(r['field'] == 'state' for r in probe()['details']))

status, body = worker.get('/employee-folder?lang=fr')
check('Self-service is translated into French', 'Mes coordonnées bancaires' in body and 'Rien n’a changé' in body)
status, body = payroll.get('/change-requests?lang=es')
check('Change requests is translated into Spanish', 'Solicitudes de cambio' in body)

json.dump(results, open('work/ss-http-results.json', 'w'), indent=1)
