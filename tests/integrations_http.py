"""P4-M04 through the screens, with every provider off as in production:
the Integrations page for administrators only, never a setting's value,
the Gmail connection to reconnect and the failed Connect event left by
integrations_db.php, its requeue, both webhooks refusing while off and
recording it, the inbox while Google is not configured, translations.
"""
import http.cookiejar, json, re, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/integrations.json'))
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


def raw(method, path, body=b'', headers=None):
    req = urllib.request.Request(base + path, data=body if method == 'POST' else None, method=method, headers=headers or {})
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()


admin = Client(); check('P4-M04 admin login', admin.login('int-admin@test.invalid')[0] == 200)
recruiter = Client(); check('P4-M04 recruiter login', recruiter.login('int-mail@test.invalid')[0] == 200)

check('Only administrators open Integrations', recruiter.get('/integrations')[0] == 403)
status, body = admin.get('/integrations')
check('An administrator sees the three providers, all off', status == 200 and re.findall(r'data-provider="(\w+)" data-enabled="(\d)"', body)[:3]
      == [('gmail', '0'), ('docusign', '0'), ('stripe', '0')])
check('No setting\'s value is on the page', 'AAAAAAAAAAAAAAAA' not in body and 'unit-test-secret' not in body and 'sk_test' not in body and 'whsec' not in body)
check('The connection Google refused is listed to reconnect', 'id="gmail-reconnect"' in body and 'data-user="%d"' % fx['reconnect_user'] in body)
check('The failed Connect event is shown with its reason', 'data-connect="%s" data-status="failed"' % fx['failed_hash'] in body and 'Unknown contract' in body)
check('The audit trail holds the outcomes, all six kinds', all('data-outcome="%s"' % o in admin.get('/integrations?outcome=' + o)[1] for o in ['ok', 'duplicate', 'ignored', 'retry', 'failed', 'refused']))

check('Recruiting cannot requeue', recruiter.post('/integrations', {'do': 'requeue', 'event': fx['failed_hash']})[0] == 403)
check('An administrator requeues it', admin.post('/integrations', {'do': 'requeue', 'event': fx['failed_hash']})[0] == 200
      and 'data-connect="%s" data-status="pending"' % fx['failed_hash'] in admin.get('/integrations')[1])
check('Requeueing one that has not failed is refused', admin.post('/integrations', {'do': 'requeue', 'event': fx['failed_hash']})[0] == 422)

status, body = raw('GET', '/stripe-webhook')
check('The Stripe webhook takes only POST', status == 405)
status, body = raw('POST', '/stripe-webhook', b'{"id":"evt_x"}', {'Stripe-Signature': 't=1,v1=00'})
check('With Stripe off, a webhook is refused', status == 400 and 'Invalid webhook' in body)
status, body = raw('POST', '/docusign-webhook', b'{}', {'X-DocuSign-Signature-1': 'AAAA'})
check('With DocuSign off, Connect answers 503', status == 503)
status, body = admin.get('/integrations?outcome=refused')
check('Both refusals are recorded, with the sender', 'Invalid webhook' in body and 'DocuSign is disabled' in body and '(from 127.0.0.1)' in body)

status, body = admin.get('/inbox')
check('The inbox says Google is not set up, and offers no connection', status == 200 and 'Google setup required' in body and '/google-start' not in body)
status, body = raw('GET', '/google-start')
check('Starting a Google connection while it is off is refused', status in (302, 503))

status, body = admin.get('/integrations?lang=fr')
check('Integrations is translated into French', 'File Connect de DocuSign' in body and 'Piste d’audit' in body)
status, body = admin.get('/integrations?lang=es')
check('and into Spanish', 'Registro de auditoría' in body)
admin.get('/integrations?lang=en')

json.dump(results, open('work/int-http-results.json', 'w'), indent=1)
