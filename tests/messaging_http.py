"""P5-M07 through the screens: telephone numbers made into links; messages
written once (unknown placeholders and assignment details refused where
they cannot be known); a text filled from the person, the assignment, the
hotel and the flight, missing details shown in brackets; the sms: link;
a text opened on the phone logged in the existing contact register (last
contacted, new -> contacted); do-not-text and do-not-call honoured on
every screen and in the register; who may do what; translations.
"""
import html, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/messaging.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS, CANDS, PLACES = fx['ids'], fx['cands'], fx['places']
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

    def post(self, path, values):
        data = urllib.parse.urlencode({'_csrf': self.token, **values}).encode()
        return self.open(urllib.request.Request(base + path, data))


def user(key):
    c = Client()
    c.get('/login')
    status, body = c.post('/login', {'email': 'ms-%s@test.invalid' % key, 'password': 'TestPassword123!'})
    assert '<title>Sign in' not in body, key
    return c


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    out = subprocess.run([php, 'work/messaging_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def options(body):
    panel = body.split('id="text-panel"')[1].split('</select>')[0]
    return [(int(i), html.unescape(t), html.unescape(m)) for i, t, m in re.findall(r'<option value="(\d+)" data-text="([^"]*)" data-missing="([^"]*)"', panel)]


def contacts(key):
    return [c for c in probe()['contacts'] if int(c['candidate_id']) == CANDS[key]]


rec, hotels, pay = user('rec'), user('hotels'), user('pay')
ANA, ABROAD, NOPHONE = CANDS['ana'], CANDS['abroad'], CANDS['nophone']
T = {(t['name'], t['language']): int(t['id']) for t in probe()['templates']}

# ── numbers ────────────────────────────────────────────────────────────
check('A US number becomes +1 and ten digits', probe('phone', '(555) 201-3344') == '+15552013344')
check('a number given with its country code keeps it', probe('phone', '+52 55 1234 5678') == '+525512345678' and probe('phone', '1-555-201-3344') == '+15552013344')
check('and something too short is no number at all', probe('phone', '123') is None and probe('phone', '') is None)

# ── messages written once ──────────────────────────────────────────────
M = '/message-templates'
rec.get(M)
for label, values, words in [('a two-letter name', {'name': 'Hi', 'language': 'en', 'purpose': 'candidate', 'body': 'Hello {first_name}'}, '3 to 120'),
                             ('an unknown placeholder', {'name': 'Bad field', 'language': 'en', 'purpose': 'candidate', 'body': 'Hello {nickname}'}, 'Unknown placeholder: {nickname}'),
                             ('assignment details in a recruiting message', {'name': 'Too early', 'language': 'en', 'purpose': 'candidate', 'body': 'See you at {hotel}'}, 'only known from a placement'),
                             ('a name already used in that language', {'name': 'Following up', 'language': 'en', 'purpose': 'candidate', 'body': 'Hello again {first_name}'}, 'already exists'),
                             ('a message of 1001 characters', {'name': 'Too long', 'language': 'en', 'purpose': 'candidate', 'body': 'x' * 1001}, '5 to 1000')]:
    status, body = rec.post(M, values)
    check('A message with %s is refused' % label, status == 422 and words in body, body[:200])
rec.get(M)
rec.post(M, {'name': 'Interview tomorrow', 'language': 'en', 'purpose': 'candidate', 'body': 'Hi {first_name}, {sender} here from {agency}. Can you meet tomorrow at 10?'})
mine = next(t for t in probe()['templates'] if t['name'] == 'Interview tomorrow')
check('A recruiter writes a message; the six to start from are there too', mine['purpose'] == 'candidate' and len(probe()['templates']) == 7)
check('Logistics does not write messages', hotels.get(M)[0] == 403)

# ── a text from the person's page ──────────────────────────────────────
status, body = rec.get('/candidates/%d' % ANA)
opts = options(body)
check('The person\'s page offers the recruiting messages only', {o[0] for o in opts} == {T[('Following up', 'en')], T[('Following up', 'es')], int(mine['id'])}, opts)
follow = next(o for o in opts if o[0] == T[('Following up', 'en')])
check('filled from what Crewvia holds: first name, the sender, the agency', follow[1].startswith('Hi Ana, this is Messaging rec with ') and '{' not in follow[1] and follow[2] == '', follow)
first = opts[0]
expected = 'href="' + html.escape('sms:+15552013344?&body=' + urllib.parse.quote(first[1], safe=''), quote=True) + '"'
check('The Text link opens the phone\'s texting app with the number and the message written in', expected in body, expected)
check('with Copy for Google Voice next to it, and the honest note about iPhones', 'text-copy' in body and 'On an iPhone that is Messages' in body and '/assets/messaging.js?v=1' in body)
check('The contact register offers "Texted them" and "They replied by text"', 'value="texted"' in body and 'value="replied_text"' in body)
status, _ = rec.post('/contacts', {'do': 'texted', 'candidate_id': ANA, 'placement_id': 0, 'template_id': T[('Following up', 'en')], 'text': follow[1]})
c = contacts('ana')[-1]
cand = next(x for x in probe()['candidates'] if int(x['id']) == ANA)
check('Opening the text logs it in the contact register, with its message', status == 204 and c['outcome'] == 'texted' and int(c['template_id']) == T[('Following up', 'en')]
      and c['note'].startswith('Following up: Hi Ana') and int(c['user_id']) == IDS['rec'], (status, c))
check('and the person is now contacted, with when', cand['stage'] == 'contacted' and cand['last_contact_at'] is not None)
status, body = rec.get('/candidates/%d' % ANA)
check('The page shows it as the last contact', 'Texted them' in body)

# ── a text from the placement, with the hotel and the flight ───────────
status, body = hotels.get('/placements/%d' % PLACES['ana'])
opts = options(body)
travel = next(o for o in opts if o[0] == T[('Travel and hotel', 'en')])
placement_ids = {T[(n, l)] for n in ('Start reminder', 'Travel and hotel') for l in ('en', 'es')}
check('Logistics texts from the placement: the assignment\'s messages first, then the recruiting ones', {o[0] for o in opts[:4]} == placement_ids and len(opts) == 7, opts)
check('The travel text is filled from the flight, the hotel and the pickup already recorded',
      'United QX7Y2B IAH 2026-11-01 09:30' in travel[1] and 'Harbor Inn, 12 Dock Rd, Baytown, TX' in travel[1] and 'Pickup: Van 3' in travel[1] and travel[2] == '', travel)
start = next(o for o in opts if o[0] == T[('Start reminder', 'es')])
check('The Spanish start reminder too, with the project and the date', 'P5-M07 turnaround' in start[1] and start[1].startswith('Hola Ana'))
status, body = hotels.get('/placements/%d' % PLACES['abroad'])
gap = next(o for o in options(body) if o[0] == T[('Travel and hotel', 'en')])
check('What is not on file stays visible in brackets, and is named', '[hotel]' in gap[1] and '[travel]' in gap[1] and 'hotel' in gap[2] and 'travel' in gap[2], gap)
check('A number abroad keeps its country code in the link', 'href="sms:+525512345678?&amp;body=' in body)
status, _ = hotels.post('/contacts', {'do': 'texted', 'candidate_id': ANA, 'placement_id': PLACES['ana'], 'template_id': T[('Travel and hotel', 'en')], 'text': travel[1]})
check('Logistics\' text is logged too', status == 204 and contacts('ana')[-1]['note'].startswith('Travel and hotel: Hi Ana') and int(contacts('ana')[-1]['user_id']) == IDS['hotels'])
check('but not without its placement', hotels.post('/contacts', {'do': 'texted', 'candidate_id': ANA, 'placement_id': 0, 'template_id': 0, 'text': 'x'})[0] == 403)
check('and not for someone else than that placement\'s person', hotels.post('/contacts', {'do': 'texted', 'candidate_id': ABROAD, 'placement_id': PLACES['ana'], 'template_id': 0, 'text': 'x'})[0] == 404)
status, body = rec.get('/candidates/%d' % NOPHONE)
check('Someone with no number is offered no text', 'id="no-phone"' in body and 'text-send' not in body)
check('and a text to them is refused', rec.post('/contacts', {'do': 'texted', 'candidate_id': NOPHONE, 'placement_id': 0, 'template_id': 0, 'text': 'Hello'})[0] == 422)

# ── do not text, do not call ───────────────────────────────────────────
rec.get('/candidates/%d' % ANA)
check('Recording a refusal needs how they asked', rec.post('/contacts', {'do': 'preferences', 'candidate_id': ANA, 'no_texts': '1', 'reason': ''})[0] == 422)
rec.post('/contacts', {'do': 'preferences', 'candidate_id': ANA, 'no_texts': '1', 'reason': 'replied STOP'})
pref = next(p for p in probe()['preferences'] if int(p['candidate_id']) == ANA)
check('A recruiter records that Ana asked not to be texted, with how and since when', pref['no_texts_since'] and pref['reason'] == 'replied STOP' and pref['no_calls_since'] is None)
check('and it is in her history', probe()['events'][-1]['detail'].startswith('no texts, calls allowed: replied STOP'))
status, body = rec.get('/candidates/%d' % ANA)
check('Her page no longer offers a text, and says why', 'id="no-texts"' in body and 'text-send' not in body and 'replied STOP' in body)
status, body = hotels.get('/placements/%d' % PLACES['ana'])
check('Nor does her placement', 'id="no-texts"' in body and 'text-send' not in body)
check('A text to her is refused', rec.post('/contacts', {'do': 'texted', 'candidate_id': ANA, 'placement_id': 0, 'template_id': 0, 'text': 'Hello'})[0] == 422)
rec.get('/candidates/%d' % ANA)
check('and so is logging one by hand', rec.post('/candidates/%d' % ANA, {'do': 'call', 'outcome': 'texted', 'note': 'by hand'})[0] == 422)
rec.get('/candidates/%d' % ANA)
rec.post('/contacts', {'do': 'preferences', 'candidate_id': ANA, 'no_texts': '1', 'no_calls': '1', 'reason': 'replied STOP, then asked by phone not to call'})
status, body = rec.get('/candidates/%d' % ANA)
check('Asked not to be called either: the Call button is gone, the number is no longer a link', 'id="no-calls"' in body and 'href="tel:+15552013344"' not in body and 'href="tel:5552013344"' not in body
      and 'href="tel:' not in body.split('id="contact"')[1].split('id="text-panel"')[0])
check('A call to her is not logged', rec.post('/candidates/%d' % ANA, {'do': 'call', 'outcome': 'reached', 'note': 'called anyway'})[0] == 422)
rec.get('/candidates/%d' % ANA)
check('An email still is', rec.post('/candidates/%d' % ANA, {'do': 'call', 'outcome': 'emailed', 'note': 'sent the forms'})[0] in (200, 302) and contacts('ana')[-1]['outcome'] == 'emailed')
status, body = pay.get('/placements/%d' % PLACES['ana'])
check('Her placement shows no call link either', 'href="tel:' not in body.split('</h1>')[1].split('id="text-panel"')[0])
rec.get('/candidates/%d' % ANA)
rec.post('/contacts', {'do': 'preferences', 'candidate_id': ANA, 'reason': ''})
status, body = rec.get('/candidates/%d' % ANA)
check('When she agrees again, a recruiter clears it and the text and call come back', 'text-send' in body and 'id="no-calls"' not in body
      and next(p for p in probe()['preferences'] if int(p['candidate_id']) == ANA)['no_texts_since'] is None)

# ── who may do what ────────────────────────────────────────────────────
check('Logistics does not record contact preferences', hotels.post('/contacts', {'do': 'preferences', 'candidate_id': ANA, 'no_texts': '1', 'reason': 'not theirs'})[0] == 403)
pay.get('/placements/%d' % PLACES['ana'])
check('Payroll texts from a placement', pay.post('/contacts', {'do': 'texted', 'candidate_id': ANA, 'placement_id': PLACES['ana'], 'template_id': 0, 'text': 'Your first pay is Friday'})[0] == 204)
check('/contacts takes no GET', rec.get('/contacts')[0] == 405)

# ── translations ───────────────────────────────────────────────────────
status, body = rec.get(M + '?lang=fr')
check('Text messages in French', 'Messages SMS' in body)
status, body = rec.get('/candidates/%d?lang=es' % ANA)
check('The text panel in Spanish', 'Copiar para Google Voice' in body)
rec.get(M + '?lang=en')

json.dump(results, open('work/ms-http-results.json', 'w'), indent=1)
