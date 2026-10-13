"""P5-M18 (T7) through the screens and the delivery run: a campaign about
one requisition goes to the people the filters find (trade, state, distance
from the site) and never to anyone who unsubscribed, is on the do-not-rehire
register, has a switched-off account, has no valid email, or is already on
the requisition; nothing goes until an administrator has entered the postal
address; the draft fixes its recipients and shows the email as its first
reader gets it; a test goes to the sender only; up to 50 people it goes at
once, above 50 an administrator approves; delivery sends 50 a run, never
more than 500 in a day, from the configured sender with replies to the
recruiter, an unsubscribe link and the address; each person's link leads to
their portal or the job's application page; unsubscribing (by the page's
button or a mail program's one-click) stops what is still queued; a
cancelled campaign sends nothing; who may do what; translations.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/campaigns.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS, C, BULK, V, EV, GONE = fx['ids'], fx['cands'], fx['bulk'], fx['vacancy'], fx['electric'], fx['gone']
CAPTURE = fx['capture']
results = []
M = '/campaigns'


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None
        self.url = None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                self.url = r.geturl()
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

    def post(self, path, values, token=True):
        if token:                                   # a form token from the campaign page
            self.get(M)
            values = {'_csrf': self.token, **values}
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode(values).encode()))


def user(key):
    c = Client()
    c.get('/login')
    c.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': c.token, 'email': 'cm-%s@test.invalid' % key, 'password': 'TestPassword123!'}).encode()))
    return c


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    out = subprocess.run([php, 'work/campaigns_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def deliver():
    r = subprocess.run([php, 'work/test-app/install/deliver-campaigns.php'], capture_output=True, text=True)
    return r.returncode, r.stdout + r.stderr


def mails():
    return [json.loads(l) for l in open(CAPTURE, encoding='utf-8')] if os.path.exists(CAPTURE) else []


def recipients(campaign):
    return [r for r in probe()['recipients'] if int(r['campaign_id']) == campaign]


def preview(c, **f):
    q = urllib.parse.urlencode({'vacancy_id': V, 'preview': 1, **f})
    body = c.get(M + '?' + q)[1]
    m = re.search(r'id="campaign-preview" data-count="(\d+)"', body)
    return (int(m[1]) if m else None), {int(x) for x in re.findall(r'data-person="(\d+)"', body)}, body


def create(c, **f):
    values = {'do': 'create', 'vacancy_id': V, 'language': 'en', 'subject': 'Work available: {job} in {place}',
              'body': 'Hello {first_name},\n\n{job} in {place}, starting {starts}. Pay {pay_rate} an hour, per diem {per_diem}.\n\nSay so here: {link}', **f}
    c.post(M, values)
    return max(int(x['id']) for x in probe()['campaigns'])


rec, admin, hotels = user('rec'), user('admin'), user('hotels')

# ── who it goes to ─────────────────────────────────────────────────────
status, body = rec.get(M)
check('A recruiter opens Campaign email; it says it cannot go out before the postal address is entered', status == 200 and 'id="campaign-new"' in body and 'id="campaign-not-ready"' in body and 'postal address' in body)
n, shown, body = preview(rec, discipline='chemical', state='TX')
check('Chemical people in Texas: Houston, Austin and Dallas', {C['ana'], C['ben'], C['ivy']} <= shown, shown)
check('never the unsubscribed, the do-not-rehire register, no valid email, already applying, another trade, a switched-off account',
      not shown & {C['cal'], C['dee'], C['eve'], C['fay'], C['gus'], C['hal']}, shown)
n50, near, _ = preview(rec, discipline='chemical', state='TX', miles=50)
check('Within 50 miles of the Katy site: Houston, not Austin or Dallas', C['ana'] in near and not {C['ben'], C['ivy']} & near and n50 < n, (n50, n))
nbulk, _, body = preview(rec, discipline='electrical', state='TX')
check('Fifty-five electricians: more than 50, so it will need an administrator', nbulk >= 55 and 'an administrator approves it before it goes' in body, nbulk)

# ── the draft ──────────────────────────────────────────────────────────
small = create(rec, discipline='chemical', state='TX', miles=50)
listed = {int(r['candidate_id']) for r in recipients(small)}
camp = next(x for x in probe()['campaigns'] if int(x['id']) == small)
check('The draft fixes its recipients: the same people the preview showed', camp['status'] == 'draft' and int(camp['recipients']) == len(listed) == n50 and C['ana'] in listed and C['ben'] not in listed, (camp, len(listed), n50))
check('Replies go to the recruiter who wrote it', camp['reply_to'] == 'cm-rec@test.invalid')
status, body = rec.get(M + '?c=%d' % small)
check('The draft shows the email as its first reader gets it: pay, per diem, start, link, unsubscribe', 'id="campaign-sample"' in body and '$42.00' in body and '$75.00 a day' in body
      and '/unsubscribe?t=' in body and ('/my-jobs' in body or '/apply?id=%d' % V in body))
check('Nothing goes before the postal address is in', rec.post(M, {'do': 'send', 'campaign_id': small})[0] == 422 and next(x for x in probe()['campaigns'] if int(x['id']) == small)['status'] == 'draft')
check('Only an administrator enters it', rec.post(M, {'do': 'address', 'address': '100 Main Street, Katy, TX 77494'})[0] == 403)
admin.post(M, {'do': 'address', 'address': 'RSS Staffing, 100 Main Street, Katy, TX 77494'})
check('Entered, the page no longer says it cannot go out', 'id="campaign-not-ready"' not in rec.get(M)[1])
before = len(mails())
rec.post(M, {'do': 'test', 'campaign_id': small})
test = mails()[before:]
check('A test goes to the recruiter only, marked as a test', len(test) == 1 and test[0]['to'] == 'cm-rec@test.invalid' and test[0]['subject'].startswith('[TEST] Work available: P5-M18 chemical operator in Katy, TX'))

# ── sending up to 50 ───────────────────────────────────────────────────
rec.post(M, {'do': 'send', 'campaign_id': small})
check('Up to 50 people, the recruiter sends it at once', next(x for x in probe()['campaigns'] if int(x['id']) == small)['status'] == 'sending')
before = len(mails())
code, out = deliver()
sent = mails()[before:]
ana = next(m for m in sent if m['to'] == 'cm-ana@test.invalid')
check('The delivery run sends each person their own email', code == 0 and len(sent) == n50 and 'sent' in out, out)
check('from the configured sender, replies to the recruiter, with a one-click unsubscribe', ana['headers']['Reply-To'] == 'cm-rec@test.invalid' and ana['headers']['From']
      and ana['headers']['List-Unsubscribe'].startswith('<http') and ana['headers']['List-Unsubscribe-Post'] == 'List-Unsubscribe=One-Click')
check('Ana, who has an account, is sent to her portal; the foot carries the unsubscribe link and the address', 'Hello Aaa' in ana['body'] and '/my-jobs' in ana['body']
      and '/unsubscribe?t=' in ana['body'] and 'RSS Staffing, 100 Main Street, Katy, TX 77494' in ana['body'])
check('The campaign is sent, and each email is on the person\'s history', next(x for x in probe()['campaigns'] if int(x['id']) == small)['status'] == 'sent'
      and any(int(e['candidate_id']) == C['ana'] and e['event_type'] == 'campaign email' for e in probe()['events']))
check('A second run sends nothing more', len(mails()) == before + n50 and deliver()[0] == 0 and len(mails()) == before + n50)

# ── unsubscribing ──────────────────────────────────────────────────────
token = next(r['token'] for r in recipients(small) if int(r['candidate_id']) == C['ana'])
status, body = Client().get('/unsubscribe?t=' + token)
check('The link opens a page with a button, and opening it changes nothing', status == 200 and 'id="confirm-unsubscribe"' in body and C['ana'] not in probe()['opted_out'])
Client().post('/unsubscribe', {'t': token}, token=False)
check('The button records it on the person', C['ana'] in probe()['opted_out'] and any(int(e['candidate_id']) == C['ana'] and 'unsubscribed' in e['detail'] for e in probe()['events']))
check('and they are never in a list again', C['ana'] not in preview(rec, discipline='chemical', state='TX')[1])
check('A link that is not one changes nothing', Client().get('/unsubscribe?t=' + '0' * 64)[0] == 404)

# ── more than 50: approval, pace, the day's limit ──────────────────────
big = create(rec, discipline='electrical', state='TX', subject='Electricians wanted near {place}')
rec.post(M, {'do': 'send', 'campaign_id': big})
check('Above 50 people a recruiter asks, and the administrators are told', next(x for x in probe()['campaigns'] if int(x['id']) == big)['status'] == 'awaiting_approval'
      and any(int(x['user_id']) == IDS['admin'] and x['target'] == '/campaigns?c=%d' % big for x in probe()['notifications']))
check('A recruiter cannot approve it, and nothing goes meanwhile', rec.post(M, {'do': 'approve', 'campaign_id': big})[0] == 422 and deliver()[1].count('0 sent') == 1)
bulk_token = next(r['token'] for r in recipients(big) if int(r['candidate_id']) == BULK[0])
status, _ = Client().post('/unsubscribe', {'List-Unsubscribe': 'One-Click', 't': bulk_token}, token=False)
check('A mail program\'s one-click unsubscribe works without a session', status == 200 and BULK[0] in probe()['opted_out'])
admin.post(M, {'do': 'approve', 'campaign_id': big})
check('The administrator approves it', next(x for x in probe()['campaigns'] if int(x['id']) == big)['status'] == 'sending')
before = len(mails())
deliver()
first_run = len(mails()) - before
skipped = [r for r in recipients(big) if r['status'] == 'skipped']
check('A run sends 50 at most; the one who unsubscribed is skipped', first_run == 50 and len(skipped) == 1 and skipped[0]['error'] == 'unsubscribed', (first_run, len(skipped)))
probe('cap')
code, out = deliver()
check('Once 500 have gone in 24 hours, the run waits', 'daily limit' in out and len(mails()) == before + 50, out)
probe('uncap')
deliver()
check('and finishes the next time', next(x for x in probe()['campaigns'] if int(x['id']) == big)['status'] == 'sent' and not [r for r in recipients(big) if r['status'] == 'queued'])

# ── cancelling, closing, access ────────────────────────────────────────
third = create(rec, discipline='chemical', state='TX')
rec.post(M, {'do': 'cancel', 'campaign_id': third})
check('A cancelled draft sends nothing', next(x for x in probe()['campaigns'] if int(x['id']) == third)['status'] == 'cancelled' and all(r['status'] == 'skipped' for r in recipients(third)))
fourth = create(rec, discipline='chemical', state='TX')
probe('close', V)
check('A requisition closed since the draft is not sent', rec.post(M, {'do': 'send', 'campaign_id': fourth})[0] == 422)
check('Logistics does not send campaigns', hotels.get(M)[0] == 403)
rec.post('/language', {'locale': 'es', 'back': M})
check('Campaign email in Spanish', 'Campaña de correo' in rec.get(M)[1])
rec.post('/language', {'locale': 'en', 'back': M})

json.dump(results, open('work/cm-http-results.json', 'w'), indent=1)
