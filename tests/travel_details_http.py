"""P5-M30 through the screens: the worker's trip, from both ends.
  - the worker enters their name as on their ID, home address (kept encrypted) and airport; the coordinator is told;
  - Travel shows them on the booking; the booking takes the ticket, pasted, and its cost; only modes the table holds;
  - the advice for travellers is the operations VP's to write;
  - booked, the worker is told in the portal and by email, with the legs, the ticket and the advice.
Runs after campaign_texts_http.py, on the walkthrough fixture and travel_details_db.php's people.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
wk = json.load(open('work/walkthrough.json'))
fx = json.load(open('work/travel_details.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []


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
    return json.loads(subprocess.run([php, 'work/travel_details_probe.php', *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


def said(body):
    """A refusal: flashed on the page it redirects to, or the plain message of a 4xx answer."""
    return flash(body) if '<html' in body.lower() else body.strip()


def flash(body):
    return ' '.join(re.sub(r'<[^>]+>|\s+', ' ', f).strip() for f in re.findall(r'class="flash[^"]*"[^>]*>(.*?)</p>', body, re.S))


def text(html):
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', html)).replace('&middot;', '·').replace('&#039;', "'")


worker = Client(); worker.login('trip-worker@test.invalid')
hotels = Client(); hotels.login('trip-hotels@test.invalid')
admin = Client(); admin.login('wk-admin@test.invalid')
for c, page in ((hotels, '/travel'), (admin, '/travel')):
    c.post('/select-project', {'job_id': wk['job']}, page)

# ── the worker's travel details ─────────────────────────────────────────
body = worker.get('/portal')[1]
check('A worker with a trip ahead is asked for their travel details, their name filled in',
      'id="travel-details" data-entered="0"' in body and 'value="Aaa Trip Worker"' in body and 'Your travel details for the booking' in body)
status, body = worker.post('/portal', {'do': 'travel_profile', 'legal_name': 'AAA TRIP WORKER', 'address': 'Austin', 'airport': 'AUS'}, '/portal')
check('An address without the street is refused', 'Enter your home address' in said(body) and probe('profile', fx['worker']) is None, said(body))
h0 = probe('notices', fx['hotels'])['count']
status, body = worker.post('/portal', {'do': 'travel_profile', 'legal_name': 'AAA TRIP WORKER', 'address': '1200 Lamar Blvd\nAustin, TX 78703', 'airport': 'AUS', 'travel_notes': 'Aisle seat please'}, '/portal')
tp = probe('profile', fx['worker'])
check('Saved: the name as on the ID, the airport, the note', tp and tp['legal_name'] == 'AAA TRIP WORKER' and tp['airport'] == 'AUS' and tp['notes'] == 'Aisle seat please', tp)
check('The home address is stored encrypted, and opens with the workspace key', 'Lamar' not in tp['stored'] and tp['address'] == '1200 Lamar Blvd\nAustin, TX 78703', tp)
n = probe('notices', fx['hotels'])
check('The travel coordinator is told who entered their details and where they fly from', n['count'] == h0 + 1 and 'Aaa Trip Worker entered their travel details: flies from AUS' in n['last'] and n['target'] == '/travel', n)
body = worker.get('/portal')[1]
check('The portal keeps them, ready to change', 'data-entered="1"' in body and '1200 Lamar Blvd' in body and 'Your travel details for the booking' not in body)

# ── Travel: the booking ─────────────────────────────────────────────────
body = hotels.get('/travel')[1]
row = re.search(r'data-travel-details="entered">(.*?)</form>', body, re.S)
check('Travel shows the coordinator the name as on the ID, the airport and the address', row and 'AAA TRIP WORKER' in row[1] and 'flies from AUS' in row[1] and '1200 Lamar Blvd' in row[1], body[:300])
form = re.search(r'data-book="%d">(.*?)</form>' % fx['placement'], body, re.S)
modes = re.findall(r'<option value="([a-z_]+)">', form[1]) if form else []
check('The booking form starts from their airport and offers only the ways the booking can hold',
      form and 'name="depart_from" value="AUS"' in form[1] and modes == ['flight', 'drive', 'bus', 'train'] and 'name="ticket_details"' in form[1] and 'name="depart_time"' in form[1], modes)

# ── the advice for travellers ───────────────────────────────────────────
check('Until someone writes it, there is no advice, and the coordinator does not write it', 'No advice yet.' in body and 'The operations VP writes it.' in body and 'value="advice_add"' not in body)
status, body = hotels.post('/travel', {'do': 'advice_add', 'body': 'Bring snacks.'}, '/travel')
check('A coordinator who tries is refused', 'The operations VP writes the advice for travellers.' in said(body) and probe('advice', wk['job']) == [], said(body))
admin.post('/travel', {'do': 'advice_usual'}, '/travel')
admin.post('/travel', {'do': 'advice_add', 'body': 'Keep your boarding pass: the agency reimburses checked bags.'}, '/travel')
advice = probe('advice', wk['job'])
check('The operations VP starts from the usual advice (ID, be early, bag fees, a card, not cash) and adds their own',
      len(advice) == 5 and advice[0].startswith('Bring a government photo ID') and 'cash' in advice[3] and advice[4].startswith('Keep your boarding pass'), advice)

# ── booked: the worker is told ──────────────────────────────────────────
w0 = probe('notices', fx['workerUser'])['count']
ticket = 'UA 1234 AUS-IAH 1 Nov 06:10-07:20 Seat 14C. One personal item.'
status, body = hotels.post('/travel', {'do': 'book_travel', 'direction': 'inbound', 'placement_id': fx['placement'], 'mode': 'flight', 'carrier': 'United', 'reference': 'QX7P2L',
                                       'depart_from': 'AUS', 'depart_time': '2026-11-01T06:10', 'arrive_at': 'IAH', 'arrive_time': '2026-11-01T07:20', 'cost': '189.40',
                                       'ticket_details': ticket, 'pickup_needed': '1', 'send_itinerary': '1'}, '/travel')
check('Saved, the coordinator hears the itinerary is on its way', 'Travel saved for Aaa Trip Worker. The itinerary is on its way to them by email' in said(body), said(body))
leg = probe('leg', fx['placement'])
check('The booking holds the ticket, pasted, and its cost', leg['status'] == 'booked' and leg['mode'] == 'flight' and float(leg['cost']) == 189.40 and leg['ticket_details'] == ticket and leg['depart_time'] == '2026-11-01 06:10:00', leg)
n = probe('notices', fx['workerUser'])
check('The worker is told in the portal', n['count'] == w0 + 1 and 'Your travel for P5-M26 plant is booked' in n['last'] and n['target'] == '/portal#trip', n)
mail = probe('outbox', fx['worker'])
m = mail[-1] if mail else {}
b = (m.get('message') or {}).get('body', '')
check('and by email, sealed in the queue until it goes', len(mail) == 1 and m['purpose'] == 'itinerary' and m['recipient'] == 'trip-worker@test.invalid' and m['status'] == 'pending' and 'QX7P2L' not in m['stored'], m)
check('The email has the leg, the times, the pickup, the ticket and the advice',
      'Hello Aaa,' in b and 'Coming to the job: Flight · United · confirmation QX7P2L' in b and '1 Nov 2026, 6:10 am' in b and 'arrives IAH' in b
      and 'Someone from the agency picks you up at the airport.' in b and 'Seat 14C' in b and '- Bring a government photo ID' in b and '- Keep your boarding pass' in b and '/portal' in b, b)
out = subprocess.run([php, 'work/test-app/install/deliver-mail.php'], capture_output=True, text=True, encoding='utf-8')
captured = [json.loads(l) for l in open(fx['capture'], encoding='utf-8')] if os.path.exists(fx['capture']) else []
check('The delivery run sends it', out.returncode == 0 and 'Worker mail: 1 sent' in out.stdout and probe('outbox', fx['worker'])[-1]['status'] == 'sent'
      and any(c['to'] == 'trip-worker@test.invalid' and c['subject'] == 'Your travel for P5-M26 plant' for c in captured), out.stdout + out.stderr)

body = worker.get('/portal')[1]
itin = re.search(r'id="itinerary">(.*?)</div>\s*<div class="card"><h2>', body, re.S)
check('The portal shows the itinerary in words, the ticket, and the advice before you travel',
      itin and 'Coming to the job: Flight · United · confirmation QX7P2L' in text(itin[1]) and 'Seat 14C' in itin[1] and 'id="travel-advice"' in itin[1] and 'Keep your boarding pass' in itin[1],
      itin[1][:600] if itin else body[:400])
body = hotels.get('/travel')[1]
check('Travel lists the booking with its cost', 'QX7P2L' in body and '$189.40' in body)

json.dump(results, open('work/trip-http-results.json', 'w'), indent=1)
