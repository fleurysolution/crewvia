"""P5-M29 through the screens: a campaign by text message.
  - Campaigns offers email or text; a text goes to people with a mobile number who never replied STOP;
  - the draft fixes the numbers, the sample ends with the STOP line, a text has no test send;
  - the delivery run texts through Twilio (sandbox here: recorded, not sent) and skips anyone who replied STOP since;
  - with Twilio switched off, Campaigns says texting is not set up.
Runs after notices_http.py, on the walkthrough fixture and campaign_texts_db.php's people.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
wk = json.load(open('work/walkthrough.json'))
fx = json.load(open('work/campaign_texts.json'))
P = fx['people']
php = os.environ.get('PHP_BIN', 'php')
results = []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                return r.status, r.read().decode(), r.geturl()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode(), e.geturl()

    def get(self, path):
        status, body, _ = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body

    def login(self, email, password='TestPassword123!'):
        self.get('/login')
        self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': password}).encode()))

    def post(self, path, values, page=None):
        self.get(page or path)
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    return json.loads(subprocess.run([php, 'work/campaign_texts_probe.php', *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


def said(body):
    """A refusal: flashed on the page it redirects to, or the plain message of a 4xx answer."""
    return flash(body) if '<html' in body.lower() else body.strip()


def flash(body):
    return ' '.join(re.sub(r'<[^>]+>|\s+', ' ', f).strip() for f in re.findall(r'class="flash[^"]*"[^>]*>(.*?)</p>', body, re.S))


rec = Client(); rec.login('wk-rec@test.invalid')
TEXT = 'Hi {first_name}, work for you: {job} in {place} from {starts}, {pay_rate}/h, per diem {per_diem}. First come, first served: {link}'
FORM = {'vacancy_id': wk['vA'], 'language': 'en', 'discipline': 'mechanical', 'state': 'WY', 'subject': 'Text: welders for the plant', 'body': TEXT}

# ── email or text ───────────────────────────────────────────────────────
body = rec.get('/campaigns')[1]
check('Campaigns offers the campaign as an email or as a text message', 'href="/campaigns?channel=text"' in body and 'data-channel="email"' in body)
body = rec.get('/campaigns?channel=text')[1]
check('As a text, it starts from a short message with the link, and says who is never texted',
      'data-channel="text"' in body and 'Hi {first_name}, work for you' in body and 'people who replied STOP' in body and 'id="campaign-not-ready"' not in body)

body = rec.get('/campaigns?' + urllib.parse.urlencode({**FORM, 'channel': 'text', 'preview': 1}))[1]
listed = set(re.findall(r'data-person="(\d+)"', body))
check('A text goes to the three with a mobile number: not the one who replied STOP, not the one with no phone',
      'data-count="3"' in body and listed == {str(P['t1']), str(P['t2']), str(P['t3'])}, listed)
body = rec.get('/campaigns?' + urllib.parse.urlencode({**FORM, 'channel': 'email', 'body': 'Hello {first_name}, we have work for you: {job}. {link}', 'preview': 1}))[1]
check('The same filters by email reach all five: replying STOP to texts is not unsubscribing from email', 'data-count="5"' in body)

# ── the draft ───────────────────────────────────────────────────────────
status, body, _ = rec.post('/campaigns', {'do': 'create', 'channel': 'text', **FORM, 'body': 'x' * 500})
check('A text longer than 480 characters is refused', 'Write the text in 20 to 480 characters' in said(body), said(body))
status, body, url = rec.post('/campaigns', {'do': 'create', 'channel': 'text', **FORM})
cid = probe('last')
m = probe('campaign', cid)
check('The draft is a text campaign with the three numbers fixed, in international form, and no email',
      m['channel'] == 'text' and m['status'] == 'draft' and m['recipients'] == 3
      and {p['phone'] for p in m['people'].values()} == {'+13075550101', '+13075550102', '+13075550103'}
      and all(p['email'] is None for p in m['people'].values()), m)
sample = re.search(r'id="campaign-sample".*?<pre[^>]*>(.*?)</pre>', body, re.S)
check('The sample is the text as the first person reads it, ending with the STOP line',
      sample and 'Hi Aaa, work for you: Walk machinist req in ' in sample[1] and '$45.00/h, per diem $75.00 a day' in sample[1] and '/apply?id=%d' % wk['vA'] in sample[1] and sample[1].strip().endswith('Reply STOP to stop these texts.'),
      sample[1] if sample else body[:500])
check('It lists the mobile numbers, and there is no test send for a text',
      'data-channel="text"' in body and '+13075550101' in body and 'value="test"' not in body)
status, body, _ = rec.post('/campaigns', {'do': 'test', 'campaign_id': cid}, '/campaigns?c=%d' % cid)
check('A test send asked for anyway is refused', 'has no test send' in said(body), said(body))
rec.post('/campaigns', {'do': 'send', 'campaign_id': cid}, '/campaigns?c=%d' % cid)
check('Three people: the recruiter sends it without an approval', probe('campaign', cid)['status'] == 'sending')

# ── delivery ────────────────────────────────────────────────────────────
probe('stop', P['t3'])
out = subprocess.run([php, 'work/test-app/install/deliver-campaigns.php'], capture_output=True, text=True, encoding='utf-8')
# Email may be switched on by an earlier suite's capture file, so the printed totals can include email; the recipients below are the proof.
check('The delivery run goes through', out.returncode == 0 and ' sent, ' in out.stdout, out.stdout + out.stderr)
m = probe('campaign', cid)
t1, t3 = m['people'][str(P['t1'])], m['people'][str(P['t3'])]
check('In sandbox each text is recorded on the person and marked so: nothing left Crewvia',
      t1['status'] == 'sent' and t1['error'] == 'sandbox: recorded, not sent' and t1['sms']['mode'] == 'sandbox' and t1['sms']['phone'] == '+13075550101'
      and 'Walk machinist req' in t1['sms']['body'] and t1['sms']['body'].endswith('Reply STOP to stop these texts.'), t1)
check('The delivery run texted two; the one who replied STOP since got nothing', t3['status'] == 'skipped' and t3['error'] == 'asked not to be texted' and t3['sms'] is None, t3)
check('With nobody left to text, the campaign is sent', m['status'] == 'sent')
body = rec.get('/campaigns')[1]
check('The list marks it as a text', 'data-campaign="%d" data-channel="text"' % cid in body)

# ── Twilio switched off ─────────────────────────────────────────────────
probe('twilio', 'disabled')
body = rec.get('/campaigns?channel=text')[1]
check('With Twilio switched off, a text campaign says texting is not set up', 'id="campaign-not-ready"' in body and 'Texting through Crewvia is not set up.' in body)
body = rec.get('/campaigns')[1]
check('and email campaigns do not', 'Texting through Crewvia is not set up.' not in body)
probe('twilio', 'sandbox')

json.dump(results, open('work/ct-http-results.json', 'w'), indent=1)
