"""P5-M15 (T20) through the screens: opening a hotel's folio for a week
fills a room line per guest booked that week (cancelled bookings and the
other hotel's left out); each line is checked against its booking (more or
fewer nights, another rate, a guest not booked, nights absent at roll
call) and every difference needs a note before the folio is reconciled;
taxes and incidentals, each incidental charged to the agency, the client
or a guest; the totals; reconciling freezes the lines and reopening frees
them; payroll alone turns it into the hotel's bill in Payables, once, and
never on top of a bill already typed; the worker sees a charge to them on
their portal; who may open the page; translations.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/folios.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS, P, JOB, HOTEL, HOTEL2, WEEK = fx['ids'], fx['places'], fx['job'], fx['hotel'], fx['hotel2'], fx['week']
results = []
F = '/folios'


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

    def post(self, values, path=F):
        self.get(F if path in (F, '/select-project') else path)
        data = urllib.parse.urlencode({'_csrf': self.token, **values}).encode()
        return self.open(urllib.request.Request(base + path, data))


def user(key):
    c = Client()
    c.get('/login')
    data = urllib.parse.urlencode({'_csrf': c.token, 'email': 'fo-%s@test.invalid' % key, 'password': 'TestPassword123!'}).encode()
    status, body = c.open(urllib.request.Request(base + '/login', data))
    assert '<title>Sign in' not in body, key
    return c


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe():
    return json.loads(subprocess.run([php, 'work/folios_probe.php'], check=True, capture_output=True, text=True).stdout)


def folio(hotel=HOTEL):
    return next((f for f in probe()['folios'] if int(f['hotel_id']) == hotel), None)


def line(f, **match):
    return next((l for l in f['lines'] if all(l.get(k) == v for k, v in match.items())), None)


logistics, pay, rec, w, w2 = user('hotels'), user('pay'), user('rec'), user('w'), user('w2')
for c in (logistics, pay):
    c.post({'job_id': JOB}, '/select-project')

# ── opening a folio ────────────────────────────────────────────────────
status, body = logistics.get(F)
check('Logistics opens Hotel folios from the menu', status == 200 and 'href="/folios"' in body and 'id="folio-open"' in body and 'id="no-folios"' in body)
check('A folio needs its number, a hotel, and a week that has started',
      logistics.post({'do': 'open', 'hotel_id': HOTEL, 'week': fx['any_day'], 'reference': ''})[0] == 422
      and logistics.post({'do': 'open', 'hotel_id': 0, 'week': fx['any_day'], 'reference': 'X'})[0] == 422
      and logistics.post({'do': 'open', 'hotel_id': HOTEL, 'week': '2099-01-07', 'reference': 'X'})[0] == 422 and folio() is None)
logistics.post({'do': 'open', 'hotel_id': HOTEL, 'week': fx['any_day'], 'reference': 'RI-4471'})
f = folio()
check('Opened for the week, with a room line per guest booked there', f is not None and f['week_ending'] == WEEK and f['status'] == 'open' and len(f['lines']) == 2
      and {l['placement_id'] for l in f['lines']} == {P['a'], P['b']}, f and f['lines'])
A, B = line(f, placement_id=P['a']), line(f, placement_id=P['b'])
check('The whole week at the hotel\'s rate, and three nights at the guest\'s own rate', (A['nights'], A['rate'], A['amount']) == (7, 100.0, 700.0) and (B['nights'], B['rate'], B['amount']) == (3, 90.0, 270.0))
check('Nothing differs yet; a night absent at roll call is pointed out without blocking', A['flags'] == ['absent'] and A['absent'] == 1 and not A['needs_note'] and B['flags'] == [])
check('Billed and booked agree', f['totals']['billed'] == 970.0 and f['totals']['booked'] == 970.0)
check('Not twice for the same hotel and week', logistics.post({'do': 'open', 'hotel_id': HOTEL, 'week': fx['any_day'], 'reference': 'RI-4472'})[0] == 422 and len(probe()['folios']) == 1)
FID = int(f['id'])
status, body = logistics.get(F + '?folio=%d' % FID)
check('The folio page shows its lines and what Crewvia booked', 'data-line="%d"' % A['id'] in body and '7 night(s) at $100.00' in body and 'data-flag="absent"' in body)

# ── correcting it to what the hotel billed ─────────────────────────────
logistics.post({'do': 'line_save', 'line_id': B['id'], 'nights': 4, 'rate': '90'})
B = line(folio(), id=B['id'])
check('A night more than booked is flagged and needs a note', 'nights_more' in B['flags'] and B['needs_note'] and B['amount'] == 360.0)
check('Reconciling is refused while a difference is unexplained', logistics.post({'do': 'reconcile', 'folio_id': FID})[0] == 422 and folio()['status'] == 'open')
logistics.post({'do': 'line_save', 'line_id': B['id'], 'nights': 4, 'rate': '90', 'accepted_note': 'Stayed Wednesday night, flight on Thursday'})
logistics.post({'do': 'line_save', 'line_id': A['id'], 'nights': 7, 'rate': '105'})
A = line(folio(), id=A['id'])
check('Another rate is flagged', 'rate' in A['flags'] and A['needs_note'])
logistics.post({'do': 'line_save', 'line_id': A['id'], 'nights': 7, 'rate': '105', 'accepted_note': 'Weekend rate'})
check('A line is checked: nights 0 to 7, a rate', logistics.post({'do': 'line_save', 'line_id': A['id'], 'nights': 9, 'rate': '105'})[0] == 422)
logistics.post({'do': 'line_add', 'folio_id': FID, 'kind': 'room', 'guest_name': 'Walk In Guest', 'room_number': '110', 'nights': 2, 'rate': '100'})
walk = line(folio(), guest='Walk In Guest')
check('A guest Crewvia did not book is flagged', walk is not None and walk['flags'] == ['not_ours'] and walk['needs_note'])
logistics.post({'do': 'line_save', 'line_id': walk['id'], 'nights': 2, 'rate': '100', 'accepted_note': 'Safety inspector, approved by the site lead'})
logistics.post({'do': 'line_add', 'folio_id': FID, 'kind': 'tax', 'description': 'Occupancy tax', 'amount': '85.50'})
check('An incidental needs who pays, and a guest when it is the worker',
      logistics.post({'do': 'line_add', 'folio_id': FID, 'kind': 'incidental', 'description': 'Laundry', 'amount': '25'})[0] == 422
      and logistics.post({'do': 'line_add', 'folio_id': FID, 'kind': 'incidental', 'description': 'Laundry', 'amount': '25', 'charge_to': 'worker'})[0] == 422)
logistics.post({'do': 'line_add', 'folio_id': FID, 'kind': 'incidental', 'description': 'Laundry', 'amount': '25', 'charge_to': 'worker', 'placement_id': P['a']})
logistics.post({'do': 'line_add', 'folio_id': FID, 'kind': 'incidental', 'description': 'Minibar', 'amount': '40', 'charge_to': 'client'})
logistics.post({'do': 'line_add', 'folio_id': FID, 'kind': 'incidental', 'description': 'Late checkout', 'amount': '15', 'charge_to': 'agency'})
gone = line(folio(), kind='incidental', amount=15.0)
logistics.post({'do': 'line_delete', 'line_id': gone['id']})
f = folio()
check('A line can be removed', line(f, kind='incidental', amount=15.0) is None)
check('The totals: billed, as booked, and who pays', f['totals'] == {'billed': 1445.5, 'booked': 970.0, 'agency': 1380.5, 'client': 40.0, 'worker': 25.0}, f['totals'])

# ── reconciling and the bill ───────────────────────────────────────────
logistics.post({'do': 'reconcile', 'folio_id': FID})
check('Explained, it reconciles', folio()['status'] == 'reconciled')
check('Reconciled, its lines are frozen', logistics.post({'do': 'line_save', 'line_id': A['id'], 'nights': 6, 'rate': '105', 'accepted_note': 'x'})[0] == 422)
status, body = w.get('/portal')
check('The worker sees the hotel charge to them on their portal', 'id="hotel-charges"' in body and 'Laundry' in body and '$25.00' in body)
check('and nobody else\'s', 'id="hotel-charges"' not in w2.get('/portal')[1])
check('Logistics does not make the bill', logistics.post({'do': 'bill', 'folio_id': FID, 'due_on': '2026-11-01'})[0] == 422 and probe()['bills'] == [])
logistics.post({'do': 'reopen', 'folio_id': FID})
check('Reopened, it can be changed again', folio()['status'] == 'open')
logistics.post({'do': 'reconcile', 'folio_id': FID})
check('The bill needs its due date', pay.post({'do': 'bill', 'folio_id': FID})[0] == 422)
pay.post({'do': 'bill', 'folio_id': FID, 'due_on': '2026-11-01'})
bills = probe()['bills']
check('Payroll makes it the hotel\'s bill in Payables: the hotel, the folio number, the total', len(bills) == 1 and int(bills[0]['hotel_id']) == HOTEL and bills[0]['vendor_name'] == 'P5-M15 Riverside Inn'
      and bills[0]['reference'] == 'RI-4471' and float(bills[0]['amount']) == 1445.5 and bills[0]['due_on'] == '2026-11-01' and bills[0]['status'] == 'received', bills)
check('Linked, once: not again, and not reopened', int(folio()['vendor_invoice_id']) == int(bills[0]['id'])
      and pay.post({'do': 'bill', 'folio_id': FID, 'due_on': '2026-11-01'})[0] == 422 and logistics.post({'do': 'reopen', 'folio_id': FID})[0] == 422)
check('Payables lists it', 'RI-4471' in pay.get('/accounts-payable')[1])
pay.post({'do': 'invoice', 'vendor_name': 'P5-M15 Highway Lodge', 'reference': 'HL-77', 'amount': '560', 'due_on': '2026-11-01'}, '/accounts-payable')
logistics.post({'do': 'open', 'hotel_id': HOTEL2, 'week': fx['any_day'], 'reference': 'HL-77'})
f2 = folio(HOTEL2)
check('The other hotel\'s folio has only its own guest', f2 is not None and [l['placement_id'] for l in f2['lines']] == [P['c']] and f2['lines'][0]['amount'] == 560.0)
logistics.post({'do': 'reconcile', 'folio_id': f2['id']})
check('A bill already typed in Payables is never entered again', pay.post({'do': 'bill', 'folio_id': f2['id'], 'due_on': '2026-11-01'})[0] == 422 and len(probe()['bills']) == 2)
status, body = logistics.get(F)
check('The list shows each folio and where it stands', 'data-folio="%d"' % FID in body and 'Bill in Payables' in body and 'data-folio="%d"' % int(f2['id']) in body)

# ── who may open it ────────────────────────────────────────────────────
status, body = w.get(F)
check('A recruiter is refused, and a worker is sent back to their portal', rec.get(F)[0] == 403 and 'id="folio-open"' not in body and 'id="folio-lines"' not in body)
w.post({'do': 'line_delete', 'line_id': A['id']})
check('nor change one', rec.post({'do': 'reconcile', 'folio_id': FID})[0] == 403 and line(folio(), id=A['id']) is not None)

# ── translations ───────────────────────────────────────────────────────
logistics.post({'locale': 'fr', 'back': F}, '/language')
check('Hotel folios in French', 'Folios d’hôtel' in logistics.get(F)[1])
logistics.post({'locale': 'en', 'back': F}, '/language')

json.dump(results, open('work/fo-http-results.json', 'w'), indent=1)
