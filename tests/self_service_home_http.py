"""P2-M07 through the screens: the worker's self-service home and what it
puts first, HR requests routed to the right desk and nobody else, the
conversation, closing and withdrawing, an employment letter with and
without the pay rate, frozen with its fingerprint, roles and translations.
"""
import hashlib, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
sj = json.load(open('work/ss7.json'))
php = os.environ.get('PHP_BIN', 'php')
ids = sj['ids']
A, B = sj['a'], sj['b']
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


def probe(*args):
    out = subprocess.run([php, 'work/self_service_home_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def requests_():
    return probe(A['cid'], B['cid'])['requests']


def act(client, values, page='/hr-requests'):
    client.get(page)
    return client.post('/hr-requests', values)


people = {}
for key in ('worker', 'worker2', 'rec', 'payroll', 'hotels'):
    people[key] = Client()
    check('P2-M07 %s login' % key, people[key].login('ss7-%s@test.invalid' % key)[0] == 200)
worker, worker2, rec, payroll, hotels = (people[k] for k in ('worker', 'worker2', 'rec', 'payroll', 'hotels'))

# ── the home ─────────────────────────────────────────────────────────────
status, body = worker.get('/self-service')
check('Alpha opens their self-service home', status == 200 and 'My self-service' in body and 'Self alpha' in body)
check('It puts first what waits on them: a review for their view, an HR decision to read', 'data-waiting="self_review"' in body and 'data-waiting="to_ack"' in body)
check('and every area is one click away', all('data-card="%s"' % k in body for k in ('details', 'timeoff', 'payslips', 'benefits', 'reviews', 'goals', 'hr_record', 'requests', 'expenses', 'notifications')))
status, body = worker2.get('/self-service')
check('Bravo has nothing waiting', 'data-waiting="0"' in body)
check('A recruiter has no self-service home', rec.get('/self-service')[0] == 403)
check('Logistics cannot open HR requests', hotels.get('/hr-requests')[0] == 403)

# ── requests ─────────────────────────────────────────────────────────────
new = lambda client, kind, subject, detail, pay=False: act(client, {'do': 'create', 'kind': kind, 'subject': subject, 'detail': detail, **({'include_pay': '1'} if pay else {})})
check('A request with no detail is refused', new(worker, 'pay_question', 'Overtime', 'Why?')[0] == 422)
check('A request about nothing known is refused', new(worker, 'raise', 'More pay', 'I would like more money please')[0] == 422)
check('Staff cannot send a request as a worker', new(rec, 'other', 'Test', 'Not a worker asking anything')[0] == 403)
check('Alpha asks payroll why last week\'s overtime is short', new(worker, 'pay_question', 'Overtime last week', 'I worked 46 hours and was paid 40 at the higher rate.')[0] == 200)
pq = requests_()[-1]
check('It goes to payroll, and payroll is told', pq['desk'] == 'payroll' and pq['status'] == 'open' and re.match(r'REQ-\d{6}-\d{4}$', pq['reference'])
      and any(n['user_id'] == ids['payroll'] and pq['reference'] in n['message'] for n in probe(A['cid'], B['cid'])['notified']))
check('Alpha asks for an employment letter stating the pay, for a landlord', new(worker, 'employment_letter', 'Letter for my landlord', 'The landlord needs proof of employment and pay.', True)[0] == 200)
lt = requests_()[-1]
check('It goes to recruiting, with the pay rate asked for', lt['desk'] == 'recruiter' and lt['include_pay'] == 1)
body = rec.get('/hr-requests')[1]
check('Recruiting sees the letter and not the pay question', 'data-list-request="%s"' % lt['reference'] in body and 'data-list-request="%s"' % pq['reference'] not in body)
body = payroll.get('/hr-requests')[1]
check('Payroll sees the pay question and not the letter', 'data-list-request="%s"' % pq['reference'] in body and 'data-list-request="%s"' % lt['reference'] not in body)
check('Bravo cannot open Alpha\'s request', worker2.get('/hr-requests?id=%d' % pq['id'])[0] == 404)
check('Payroll cannot open the letter request', payroll.get('/hr-requests?id=%d' % lt['id'])[0] == 404)

# ── the conversation ─────────────────────────────────────────────────────
check('Payroll answers; Alpha is told', act(payroll, {'do': 'reply', 'request_id': pq['id'], 'message': 'Two days went in as leave; we will adjust it this week.'}, '/hr-requests?id=%d' % pq['id'])[0] == 200
      and requests_()[0]['status'] == 'answered' and any(n['user_id'] == ids['worker'] and 'answered' in n['message'] for n in probe(A['cid'], B['cid'])['notified']))
check('The home now says HR answered', 'data-waiting="answered"' in worker.get('/self-service')[1])
check('Alpha replies, and it is open again', act(worker, {'do': 'reply', 'request_id': pq['id'], 'message': 'Thank you, I will check the slip.'}, '/hr-requests?id=%d' % pq['id'])[0] == 200 and requests_()[0]['status'] == 'open')
check('Payroll cannot withdraw a worker\'s request', act(payroll, {'do': 'withdraw', 'request_id': pq['id']})[0] == 403)
check('Alpha closes it once settled; nothing more is added', act(worker, {'do': 'close', 'request_id': pq['id']})[0] == 200 and requests_()[0]['status'] == 'closed'
      and act(worker, {'do': 'reply', 'request_id': pq['id'], 'message': 'One more thing'})[0] == 422)

# ── the employment letter ───────────────────────────────────────────────
check('Recruiting issues the letter', act(rec, {'do': 'letter', 'request_id': lt['id']}, '/hr-requests?id=%d' % lt['id'])[0] == 200)
letter = next(r for r in requests_() if r['id'] == lt['id'])
text = letter['letter_text']
check('It states the name, since when, the trade and the project', 'Self alpha has worked with' in text and 'Pipefitter' in text and 'P2-M07 project' in text)
check('and the pay rate, as asked: $32.00 an hour', '$32.00 an hour' in text)
check('Its fingerprint is its text\'s', letter['letter_sha256'] == hashlib.sha256(text.encode()).hexdigest())
check('It is issued once', act(rec, {'do': 'letter', 'request_id': lt['id']})[0] == 422)
status, body = worker.get('/hr-requests?letter=%d' % lt['id'])
check('Alpha opens it as a printable letter', status == 200 and '@page' in body and 'To whom it may concern' in body and '/assets/app-icon.svg' in body and 'SS7 rec' in body)
check('Bravo cannot', worker2.get('/hr-requests?letter=%d' % lt['id'])[0] == 404)
probe('tamper', lt['id'])
check('A letter altered after it was issued no longer opens', worker.get('/hr-requests?letter=%d' % lt['id'])[0] == 404)
new(worker2, 'employment_letter', 'Letter for the bank', 'The bank wants to know I work here.')
lb = requests_()[-1]
act(rec, {'do': 'letter', 'request_id': lb['id']}, '/hr-requests?id=%d' % lb['id'])
check('A letter not asked to state the pay does not', ' an hour' not in next(r for r in requests_() if r['id'] == lb['id'])['letter_text'])
check('Bravo withdraws a request they no longer need', new(worker2, 'schedule', 'Night shift', 'Could I move to the day shift next month?')[0] == 200
      and act(worker2, {'do': 'withdraw', 'request_id': requests_()[-1]['id']})[0] == 200 and requests_()[-1]['status'] == 'withdrawn')

status, body = worker.get('/self-service?lang=fr')
check('Self-service is translated into French', 'Mon libre-service' in body and 'En attente de vous' in body)
status, body = worker.get('/hr-requests?lang=es')
check('and into Spanish', 'Mis solicitudes a RR. HH.' in body)
worker.get('/self-service?lang=en')

json.dump(results, open('work/ss7-http-results.json', 'w'), indent=1)
