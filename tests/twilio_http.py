"""P5-M08 through the screens, against a stand-in Twilio on this machine:
not set up, nothing offered; credentials entered (refused when malformed,
sealed, never shown back); sandbox (texts and calls recorded, nothing
leaves, nothing logged as a contact, webhooks verified and ignored); live
refused without the account check and the authorization; the account
check; live texts (the exact request, one attempt only, failures kept),
delivery statuses, replies (matched, logged, the recruiter told), STOP and
START, unknown numbers; bridged calls (the staff phone first, the TwiML
that connects the person); signatures refused; credentials changed puts it
back in sandbox; disabled; translations.
"""
import base64, hashlib, hmac, http.cookiejar, http.server, json, os, re, subprocess, threading, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
STUB = 'http://127.0.0.1:8094'
fx = json.load(open('work/twilio.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS, CANDS, PLACES = fx['ids'], fx['cands'], fx['places']
SID = 'AC' + '1' * 32
TOKEN = 'f' * 32
FROM = '+15005550006'
results = []
received = []


class Stub(http.server.BaseHTTPRequestHandler):
    def reply(self, code, body):
        data = json.dumps(body).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        received.append({'method': 'GET', 'path': self.path, 'headers': dict(self.headers), 'form': {}})
        self.reply(200, {'sid': SID, 'status': 'active'}) if self.path.endswith('/Accounts/%s.json' % SID) else self.reply(401, {'code': 20003})

    def do_POST(self):
        length = int(self.headers.get('Content-Length') or 0)
        form = dict(urllib.parse.parse_qsl(self.rfile.read(length).decode()))
        received.append({'method': 'POST', 'path': self.path, 'headers': dict(self.headers), 'form': form})
        body = form.get('Body', '')
        if self.path.endswith('/Messages.json'):
            if 'BADNUMBER' in body:
                return self.reply(400, {'code': 21211, 'message': 'Invalid To'})
            if 'SERVERDOWN' in body:
                return self.reply(500, {'code': 20500})
            return self.reply(201, {'sid': 'SM' + '%032d' % len(received), 'status': 'queued'})
        if self.path.endswith('/Calls.json'):
            return self.reply(201, {'sid': 'CA' + '%032d' % len(received), 'status': 'queued'})
        self.reply(404, {'code': 20404})

    def log_message(self, *a):
        pass


stub = http.server.ThreadingHTTPServer(('127.0.0.1', 8094), Stub)
threading.Thread(target=stub.serve_forever, daemon=True).start()


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

    def post(self, path, values):
        data = urllib.parse.urlencode({'_csrf': self.token, **values}).encode()
        return self.open(urllib.request.Request(base + path, data))


def user(key):
    c = Client()
    c.get('/login')
    status, body = c.post('/login', {'email': 'tw-%s@test.invalid' % key, 'password': 'TestPassword123!'})
    assert '<title>Sign in' not in body, key
    return c


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    out = subprocess.run([php, 'work/twilio_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def sign(url, params, token=TOKEN):
    data = url + ''.join(k + params[k] for k in sorted(params))
    return base64.b64encode(hmac.new(token.encode(), data.encode(), hashlib.sha1).digest()).decode()


def hook(query, params, signature=None):
    url = base + '/twilio-webhook?' + query
    sig = sign(url, params) if signature is None else signature
    req = urllib.request.Request(url, urllib.parse.urlencode(params).encode(), {'X-Twilio-Signature': sig})
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()


admin, rec, hotels = user('admin'), user('rec'), user('hotels')
ANA, NOPHONE = CANDS['ana'], CANDS['nophone']
I = '/integrations'


def text(c, body, page=None, pid=0, cid=ANA):
    c.get(page or '/candidates/%d' % cid)
    return c.post('/contacts', {'do': 'send_sms', 'candidate_id': cid, 'placement_id': pid, 'template': 0, 'text': body})


def call(c, cid=ANA, pid=0):
    c.get('/candidates/%d' % cid)
    return c.post('/contacts', {'do': 'call_twilio', 'candidate_id': cid, 'placement_id': pid})


# ── not set up ─────────────────────────────────────────────────────────
status, body = rec.get('/candidates/%d' % ANA)
check('Not set up: the text panel offers no Send from Crewvia and no call through it', 'id="send-twilio"' not in body and 'id="call-form"' not in body and 'data-twilio="off"' in body)
check('and sending is refused', text(rec, 'Hello')[0] == 422 and probe()['sms'] == [])
status, body = admin.get(I)
check('Integrations shows Twilio in sandbox with no credentials, and lists it among the connectors', 'id="twilio" data-configured="0" data-mode="sandbox"' in body and 'data-connector="twilio"' in body)
check('A webhook before set-up is refused', hook('kind=status', {'MessageSid': 'SMx', 'MessageStatus': 'delivered'})[0] == 403)

# ── credentials ────────────────────────────────────────────────────────
good = {'do': 'twilio_save', 'account_sid': SID, 'auth_token': TOKEN, 'from_number': FROM, 'messaging_service_sid': ''}
for label, override in [('an account SID not starting with AC', {'account_sid': 'XX' + '1' * 32}), ('a short token', {'auth_token': 'abc'}),
                        ('a local number with no area or country code', {'from_number': '555-1234'}), ('a malformed messaging service', {'messaging_service_sid': 'MG123'}),
                        ('neither a number nor a service', {'from_number': '', 'messaging_service_sid': ''})]:
    admin.get(I)
    check('Credentials with %s are refused' % label, admin.post(I, {**good, **override})[0] == 422 and probe()['sealed'] is None)
check('A recruiter does not enter credentials', rec.post(I, good)[0] in (403, 302) and probe()['sealed'] is None)
admin.get(I)
admin.post(I, good)
d = probe()
check('An administrator enters them: the token is sealed, opens with the encryption key, and is not stored as typed', d['sealed'] and d['sealed']['opens'] == TOKEN
      and TOKEN not in d['sealed']['raw'] and TOKEN not in base64.b64decode(d['sealed']['raw']).decode('latin-1') and d['sealed']['public']['account_sid'] == SID)
status, body = admin.get(I)
check('The page shows the account\'s last four and the number, never the token', 'account ending 1111' in body and FROM in body and TOKEN not in body)
check('and the address for Twilio\'s incoming messages', base + '/twilio-webhook?kind=inbound' in body)
check('It is still sandbox', probe()['mode'] == 'sandbox')

# ── sandbox ────────────────────────────────────────────────────────────
status, body = rec.get('/candidates/%d' % ANA)
check('In sandbox the panel says Send from Crewvia will not send', 'Send from Crewvia (sandbox: not sent)' in body and 'data-twilio="sandbox"' in body)
before = len(received)
status, body = text(rec, 'Sandbox hello')
d = probe()
check('A text in sandbox is recorded, marked sandbox, and nothing leaves Crewvia', status == 200 and d['sms'][-1]['mode'] == 'sandbox' and d['sms'][-1]['status'] == 'sandbox'
      and d['sms'][-1]['provider_sid'] is None and len(received) == before and 'nothing was sent' in body)
check('and it is not logged as a contact, since nobody was contacted', [c for c in d['contacts'] if c['outcome'] == 'texted'] == [])
check('A call through Crewvia needs the caller\'s own phone first', call(rec)[0] == 422 and probe()['calls'] == [])
probe('phone', IDS['rec'], '(555) 777-0001')
call(rec)
d = probe()
check('With it, a sandbox call is recorded and nothing is dialled', d['calls'][-1]['mode'] == 'sandbox' and d['calls'][-1]['staff_phone'] == '+15557770001' and len(received) == before)
status, _ = hook('kind=inbound', {'MessageSid': 'SMsandbox1', 'From': '+15553014455', 'Body': 'STOP'})
check('A signed webhook in sandbox is answered and changes nothing', status == 200 and probe()['preferences'] == [] and all(s['provider_sid'] != 'SMsandbox1' for s in probe()['sms']))

# ── going live ─────────────────────────────────────────────────────────
def mode(m, authorization=''):
    admin.get(I)
    return admin.post(I, {'do': 'mode', 'connector': 'twilio', 'mode': m, 'authorization': authorization, 'note': ''})


check('Live is refused before the account is checked', mode('live', 'Email from RSS 12 Oct')[0] == 422 and probe()['mode'] == 'sandbox')
probe('api', STUB)
admin.get(I)
before = len(received)
admin.post(I, {'do': 'twilio_check'})
r = received[before:]
check('Checking the account reads it once, with the SID and token, and sends nothing', len(r) == 1 and r[0]['method'] == 'GET' and r[0]['path'] == '/2010-04-01/Accounts/%s.json' % SID
      and r[0]['headers'].get('Authorization') == 'Basic ' + base64.b64encode(('%s:%s' % (SID, TOKEN)).encode()).decode(), r)
check('An active account passes the sandbox trial', probe()['connector']['sandbox_passed_at'] is not None)
check('Live still needs who at RSS authorized it', mode('live', '')[0] == 422 and probe()['mode'] == 'sandbox')
mode('live', 'Email from RSS 12 Oct')
check('With it, Twilio is live', probe()['mode'] == 'live' and probe()['connector']['authorization_ref'] == 'Email from RSS 12 Oct')

# ── live texts ─────────────────────────────────────────────────────────
before = len(received)
status, body = text(rec, 'Hi Ana, can you start Monday?')
r = received[before:]
sms = probe()['sms'][-1]
check('A live text is one POST to Twilio\'s Messages with the person\'s number, the agency\'s, the words and the status address',
      len(r) == 1 and r[0]['path'] == '/2010-04-01/Accounts/%s/Messages.json' % SID and r[0]['form'] == {'To': '+15553014455', 'From': FROM, 'Body': 'Hi Ana, can you start Monday?',
      'StatusCallback': base + '/twilio-webhook?kind=status'}, r)
check('It is kept with Twilio\'s id and status', sms['mode'] == 'live' and sms['provider_sid'].startswith('SM') and sms['status'] == 'queued')
check('and logged as a contact, sent from Crewvia', probe()['contacts'][-1]['outcome'] == 'texted' and probe()['contacts'][-1]['note'].startswith('Sent from Crewvia: Hi Ana'))
before = len(received)
status, body = text(rec, 'BADNUMBER test')
check('A text Twilio refuses is refused here with Twilio\'s error, kept as failed, and sent once only', status == 422 and 'error 21211' in body and probe()['sms'][-1]['status'] == 'failed'
      and probe()['sms'][-1]['error_code'] == '21211' and len(received) - before == 1)
before = len(received)
text(rec, 'SERVERDOWN test')
check('A send is never retried blindly, even on a server error', len(received) - before == 1 and probe()['sms'][-1]['status'] == 'failed')
check('A text of 1601 characters is refused', text(rec, 'x' * 1601)[0] == 422)
check('A text to someone with no number is refused', text(rec, 'Hello', cid=NOPHONE)[0] == 422)
status, _ = text(hotels, 'Your hotel is ready', page='/placements/%d' % PLACES['ana'], pid=PLACES['ana'])
check('Logistics sends from the placement', status == 200 and probe()['sms'][-1]['user_id'] == IDS['hotels'] and probe()['sms'][-1]['provider_sid'])
check('but not from a person\'s page', text(hotels, 'Hello')[0] == 403)

# ── what Twilio sends back ─────────────────────────────────────────────
check('An unsigned webhook is refused', hook('kind=status', {'MessageSid': sms['provider_sid'], 'MessageStatus': 'delivered'}, signature='')[0] == 403)
check('and one signed with another token', hook('kind=status', {'MessageSid': sms['provider_sid'], 'MessageStatus': 'delivered'}, signature=sign(base + '/twilio-webhook?kind=status', {'MessageSid': sms['provider_sid'], 'MessageStatus': 'delivered'}, 'e' * 32))[0] == 403)
check('and one signed for another address', hook('kind=status', {'MessageSid': sms['provider_sid'], 'MessageStatus': 'delivered'}, signature=sign('https://evil.example/twilio-webhook?kind=status', {'MessageSid': sms['provider_sid'], 'MessageStatus': 'delivered'}))[0] == 403)
check('Nothing refused changed the status', next(s for s in probe()['sms'] if s['id'] == sms['id'])['status'] == 'queued')
hook('kind=status', {'MessageSid': sms['provider_sid'], 'MessageStatus': 'delivered'})
check('A signed status report updates the text: delivered', next(s for s in probe()['sms'] if s['id'] == sms['id'])['status'] == 'delivered')
status, xml = hook('kind=inbound', {'MessageSid': 'SMreply0001', 'From': '+15553014455', 'To': FROM, 'Body': 'Yes, Monday works'})
d = probe()
check('A reply is matched to the person by number, kept, and logged as replied by text', status == 200 and '<Response/>' in xml and d['sms'][-1]['direction'] == 'in'
      and int(d['sms'][-1]['candidate_id']) == ANA and d['contacts'][-1]['outcome'] == 'replied_text' and d['contacts'][-1]['note'] == 'Reply: Yes, Monday works')
check('Their recruiter is told', any(n['message'] == 'Text reply: Yes, Monday works' for n in d['notifications']))
hook('kind=inbound', {'MessageSid': 'SMreply0001', 'From': '+15553014455', 'To': FROM, 'Body': 'Yes, Monday works'})
check('The same reply delivered twice is kept once', len([s for s in probe()['sms'] if s['provider_sid'] == 'SMreply0001']) == 1)
hook('kind=inbound', {'MessageSid': 'SMstranger1', 'From': '+15550000000', 'To': FROM, 'Body': 'Who is this?'})
check('A text from a number that is nobody on file is kept, and attached to no one', next(s for s in probe()['sms'] if s['provider_sid'] == 'SMstranger1')['candidate_id'] is None)
hook('kind=inbound', {'MessageSid': 'SMstop00001', 'From': '+15553014455', 'To': FROM, 'Body': 'Stop'})
pref = next(p for p in probe()['preferences'] if int(p['candidate_id']) == ANA)
check('STOP records that Ana asked not to be texted', pref['no_texts_since'] and pref['reason'] == 'Replied STOP by text (Twilio)')
status, body = rec.get('/candidates/%d' % ANA)
check('and her page no longer offers any text', 'id="no-texts"' in body and 'id="send-twilio"' not in body)
check('A text to her through Crewvia is refused', text(rec, 'Hello again')[0] == 422)
hook('kind=inbound', {'MessageSid': 'SMstart0001', 'From': '+15553014455', 'To': FROM, 'Body': 'START'})
check('START lifts what STOP recorded', next(p for p in probe()['preferences'] if int(p['candidate_id']) == ANA)['no_texts_since'] is None)

# ── calls ──────────────────────────────────────────────────────────────
before = len(received)
status, body = call(rec)
r = received[before:]
c = probe()['calls'][-1]
check('A live call: Twilio is asked to ring the caller\'s own phone, from the agency\'s number, and to ask Crewvia what next',
      len(r) == 1 and r[0]['path'] == '/2010-04-01/Accounts/%s/Calls.json' % SID and r[0]['form']['To'] == '+15557770001' and r[0]['form']['From'] == FROM
      and r[0]['form']['Url'] == base + '/twilio-webhook?kind=voice&call=' + c['token'] and r[0]['form']['StatusCallback'] == base + '/twilio-webhook?kind=call', r)
check('It is kept with Twilio\'s id', c['mode'] == 'live' and c['provider_sid'].startswith('CA') and 'ringing your phone' in body)
status, xml = hook('kind=voice&call=' + c['token'], {'CallSid': c['provider_sid'], 'CallStatus': 'in-progress'})
check('When the caller answers, Crewvia tells Twilio to connect Ana, showing the agency\'s number', status == 200 and '<Dial callerId="%s">+15553014455</Dial>' % FROM in xml, xml)
status, xml = hook('kind=voice&call=' + 'f' * 32, {'CallSid': 'CAunknown', 'CallStatus': 'in-progress'})
check('An unknown call is hung up', '<Hangup/>' in xml)
hook('kind=call', {'CallSid': c['provider_sid'], 'CallStatus': 'completed'})
check('A call status report updates the call', probe()['calls'][-1]['status'] == 'completed')
rec.get('/candidates/%d' % ANA)
rec.post('/contacts', {'do': 'preferences', 'candidate_id': ANA, 'no_calls': '1', 'reason': 'asked by phone not to call'})
status, body = rec.get('/candidates/%d' % ANA)
check('Someone who asked not to be called is offered no call through Crewvia, and one is refused', 'id="call-form"' not in body and call(rec)[0] == 422)

# ── credentials changed, disabled ──────────────────────────────────────
admin.get(I)
admin.post(I, {**good, 'auth_token': '', 'from_number': '+15005550007'})
d = probe()
check('Changed credentials (the token kept when left empty) put Twilio back in sandbox, to be checked again', d['mode'] == 'sandbox' and d['connector']['sandbox_passed_at'] is None
      and d['sealed']['opens'] == TOKEN and d['sealed']['public']['from_number'] == '+15005550007')
mode('disabled')
status, body = rec.get('/candidates/%d' % ANA)
check('Disabled: nothing is offered', 'id="send-twilio"' not in body and probe()['mode'] == 'disabled')
check('and webhooks are refused', hook('kind=status', {'MessageSid': sms['provider_sid'], 'MessageStatus': 'failed'})[0] == 403)
check('Every outcome is in the audit trail, and no token in it', any(e['action'] == 'send' and e['outcome'] == 'failed' for e in probe()['events'])
      and all(TOKEN not in json.dumps(e) for e in probe()['events']))

# ── translations ───────────────────────────────────────────────────────
status, body = admin.get(I + '?lang=fr')
check('Integrations in French', 'Enregistrer les identifiants' in body)
admin.get(I + '?lang=en')

stub.shutdown()
json.dump(results, open('work/tw-http-results.json', 'w'), indent=1)
