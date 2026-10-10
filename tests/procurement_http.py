"""Procurement (R41-R47) through the screens: request, quotation, purchase
order, approval by the budget owner, receipt, rooms available, commitments,
units, and the lodging request a hire raises.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
proc = json.load(open('work/proc.json'))
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


def probe():
    return json.loads(subprocess.run([php, 'work/procurement_probe.php', str(proc['job']), str(proc['hotel'])], check=True, capture_output=True, text=True).stdout)


def act(client, values):
    client.get('/procurement')
    return client.post('/procurement', values)


def request_named(title):
    return next((r for r in probe()['requests'] if r['title'] == title), None)


def unit_id(client, label):
    body = client.get('/procurement')[1]
    return re.search(r'<option value="(\d+)">%s</option>' % re.escape(label), body)[1]


admin = Client(); check('Procurement admin login', admin.login('proc-admin@test.invalid')[0] == 200)
hotels = Client(); check('Procurement hotels login', hotels.login('m01-hotels@test.invalid')[0] == 200)
payroll = Client(); check('Procurement payroll login', payroll.login('m01-payroll@test.invalid')[0] == 200)
super_ = Client(); check('Procurement supervisor login', super_.login('proc-super@test.invalid')[0] == 200)
recruiter = Client(); check('Procurement recruiter login', recruiter.login('m01-recruiter@test.invalid')[0] == 200)
for c in (admin, hotels, payroll, super_):
    c.post('/select-project', {'job_id': proc['job']})

# ── who uses it ──────────────────────────────────────────────────────────
check('Hotels opens Procurement', hotels.get('/procurement')[0] == 200)
check('A supervisor opens Procurement', 'New request' in super_.get('/procurement')[1])
check('A recruiter cannot open Procurement', recruiter.get('/procurement')[0] == 403)
check('Hotels cannot change who owns the budget', act(hotels, {'do': 'settings', 'budget_owner_id': proc['super'], 'auto_lodging': '1'})[0] == 403)
check('An administrator names the supervisor budget owner and turns on lodging for hires',
      act(admin, {'do': 'settings', 'budget_owner_id': proc['super'], 'auto_lodging': '1'})[0] == 200
      and int(probe()['job']['budget_owner_id']) == proc['super'] and int(probe()['job']['auto_lodging']) == 1)

# ── a request, and every refusal ─────────────────────────────────────────
pair, room = unit_id(super_, 'Pair'), unit_id(super_, 'Room')
boots = {'do': 'request', 'category': 'safety_equipment', 'title': 'Steel-toe boots', 'quantity': '10', 'unit_id': pair}
for label, change in [('A quantity of 0 is refused', {'quantity': '0'}), ('A one-letter title is refused', {'title': 'X'}),
                      ('An unknown unit is refused', {'unit_id': '999999'}), ('An unknown category is refused', {'category': 'snacks'}),
                      ('An end before the start is refused', {'needed_from': proc['to'], 'needed_to': proc['from']}),
                      ('A hotel on a request that is not lodging is refused', {'hotel_id': proc['hotel']})]:
    status, body = act(super_, {**boots, **change})
    check(label, status == 422, (status, body[:200]))
check('Refused requests wrote nothing', probe()['requests'] == [])
check('The supervisor raises a request for 10 pairs of boots', act(super_, boots)[0] == 200 and request_named('Steel-toe boots')['status'] == 'requested')
rid = request_named('Steel-toe boots')['id']
check('A supervisor does not attach quotes or raise orders', act(super_, {'do': 'quote', 'request_id': rid, 'vendor_name': 'Boot Co', 'unit_price': '85'})[0] == 403)
check('Hotels attaches a quotation', act(hotels, {'do': 'quote', 'request_id': rid, 'vendor_name': 'Boot Co', 'unit_price': '85'})[0] == 200)

# ── the order and its approval ───────────────────────────────────────────
check('Hotels raises the purchase order: 10 x 85 = 850',
      act(hotels, {'do': 'order', 'request_id': rid, 'vendor_name': 'Boot Co', 'unit_price': '85'})[0] == 200
      and float(probe()['orders'][0]['total']) == 850 and probe()['orders'][0]['status'] == 'awaiting_approval')
oid = probe()['orders'][0]['id']
check('A second order for the same request is refused', act(hotels, {'do': 'order', 'request_id': rid, 'vendor_name': 'Boot Co', 'unit_price': '85'})[0] == 422)
status, body = act(hotels, {'do': 'approve', 'purchase_order_id': oid})
check('Whoever raised the order cannot approve it', status == 403 and 'does not approve it' in body)
check('Payroll, not the budget owner, cannot approve it', act(payroll, {'do': 'approve', 'purchase_order_id': oid})[0] == 403)
check('Rejecting needs a reason', act(super_, {'do': 'reject', 'purchase_order_id': oid, 'note': ''})[0] == 422)
check('The budget owner rejects it, and the request is open again',
      act(super_, {'do': 'reject', 'purchase_order_id': oid, 'note': 'Too dear, ask again'})[0] == 200 and request_named('Steel-toe boots')['status'] == 'requested')
check('A new order at 80: 800', act(hotels, {'do': 'order', 'request_id': rid, 'vendor_name': 'Boot Co', 'unit_price': '80'})[0] == 200)
oid = probe()['orders'][-1]['id']
check('Only a live order is received', act(hotels, {'do': 'receive', 'purchase_order_id': oid, 'quantity': '1', 'received_on': proc['from']})[0] == 422)
check('The budget owner approves it', act(super_, {'do': 'approve', 'purchase_order_id': oid})[0] == 200 and probe()['orders'][-1]['status'] == 'approved')
check('A decided order is not decided twice', act(super_, {'do': 'reject', 'purchase_order_id': oid, 'note': 'Changed my mind'})[0] == 422)

# ── receipts ─────────────────────────────────────────────────────────────
check('6 pairs arrive', act(hotels, {'do': 'receive', 'purchase_order_id': oid, 'quantity': '6', 'received_on': proc['from']})[0] == 200
      and request_named('Steel-toe boots')['status'] == 'ordered')
status, body = act(hotels, {'do': 'receive', 'purchase_order_id': oid, 'quantity': '5', 'received_on': proc['from']})
check('Receiving more than was ordered is refused', status == 422 and '10 ordered, 6 already received' in body, (status, body[:200]))
check('The last 4 arrive, and the request is received', act(hotels, {'do': 'receive', 'purchase_order_id': oid, 'quantity': '4', 'received_on': proc['from']})[0] == 200
      and request_named('Steel-toe boots')['status'] == 'received')
check('A received request cannot be cancelled', act(hotels, {'do': 'cancel', 'request_id': rid, 'reason': 'Too late'})[0] == 422)

# ── lodging: the receipt is the rooms available ──────────────────────────
# 3 rooms for 4 nights at 100 a room a night: 1200.
check('Hotels raises a lodging request for 3 rooms over 4 nights',
      act(hotels, {'do': 'request', 'category': 'lodging', 'title': 'Rooms for the crew', 'quantity': '3', 'unit_id': room,
                   'needed_from': proc['from'], 'needed_to': proc['to'], 'hotel_id': proc['hotel']})[0] == 200)
lid = request_named('Rooms for the crew')['id']
act(hotels, {'do': 'request', 'category': 'lodging', 'title': 'Rooms somewhere', 'quantity': '1', 'unit_id': room})
nid = request_named('Rooms somewhere')['id']
check('A lodging order that names no hotel is refused',
      act(hotels, {'do': 'order', 'request_id': nid, 'vendor_name': 'Any hotel', 'unit_price': '90', 'hotel_id': ''})[0] == 422
      and not any(o['request_id'] == nid for o in probe()['orders']))
check('A lodging order is priced per room per night: 3 x 100 x 4 = 1200',
      act(hotels, {'do': 'order', 'request_id': lid, 'vendor_name': 'Procurement hotel', 'unit_price': '100'})[0] == 200
      and float(next(o for o in probe()['orders'] if o['request_id'] == lid)['total']) == 1200)
loid = next(o for o in probe()['orders'] if o['request_id'] == lid)['id']
check('An administrator may approve when not its author', act(admin, {'do': 'approve', 'purchase_order_id': loid})[0] == 200)
check('3 rooms confirmed', act(hotels, {'do': 'receive', 'purchase_order_id': loid, 'quantity': '3', 'received_on': proc['from']})[0] == 200)
h = probe()['hotel']
check('The hotel now holds 3 rooms for those dates', int(h['rooms_held']) == 3 and h['block_starts'] == proc['from'] and h['block_ends'] == proc['to'], h)

# The author rule on its own: an administrator could approve any order,
# except one they raised themselves.
act(admin, {'do': 'request', 'category': 'safety_equipment', 'title': 'Work gloves', 'quantity': '5', 'unit_id': pair})
gid = request_named('Work gloves')['id']
act(admin, {'do': 'order', 'request_id': gid, 'vendor_name': 'Glove Co', 'unit_price': '2'})
goid = next(o for o in probe()['orders'] if o['request_id'] == gid)['id']
status, body = act(admin, {'do': 'approve', 'purchase_order_id': goid})
check('An administrator cannot approve an order they raised', status == 403 and 'does not approve it' in body
      and next(o for o in probe()['orders'] if o['id'] == goid)['status'] == 'awaiting_approval', (status, body[:200]))
check('The budget owner can', act(super_, {'do': 'approve', 'purchase_order_id': goid})[0] == 200)

# ── commitments ──────────────────────────────────────────────────────────
payroll.get('/accounts-payable')
check('An invoice for an order on another project is refused',
      payroll.post('/accounts-payable', {'do': 'invoice', 'vendor_name': 'Boot Co', 'reference': 'BC-1', 'amount': '500', 'due_on': proc['to'], 'purchase_order_id': '999999'})[0] == 422)
payroll.get('/accounts-payable')
check('Payroll records 500 of the boots invoice against its order',
      payroll.post('/accounts-payable', {'do': 'invoice', 'vendor_name': 'Boot Co', 'reference': 'BC-1', 'amount': '500', 'due_on': proc['to'], 'purchase_order_id': oid})[0] == 200
      and [(float(i['amount']), int(i['purchase_order_id'])) for i in probe()['invoices']] == [(500.0, int(oid))])
status, body = hotels.get('/procurement')
open_boots = re.search(r'data-commitment="%d">.*?data-k="open">([^<]+)<' % oid, body, re.S)
open_rooms = re.search(r'data-commitment="%d">.*?data-k="open">([^<]+)<' % loid, body, re.S)
check('Commitments: 300 still to be billed on the boots, 1200 on the rooms', open_boots and open_boots[1] == '$300.00' and open_rooms and open_rooms[1] == '$1,200.00')

# ── a hire raises the lodging request ────────────────────────────────────
admin.get('/candidates/%d' % proc['hire1'])
check('Placing a hire on the project', admin.post('/candidates/%d' % proc['hire1'], {'do': 'place', 'start_date': proc['from']})[0] == 200)
auto = next((r for r in probe()['requests'] if r['source'] == 'hire'), None)
pid = next(int(p['id']) for p in probe()['placements'] if int(p['candidate_id']) == proc['hire1'])
check('raises a lodging request for them, by itself', auto and auto['title'] == 'Room for Procurement hire' and int(auto['placement_id']) == pid and auto['category'] == 'lodging', auto)
hotels.get('/hotels')
hotels.post('/hotels', {'do': 'book', 'placement_id': pid, 'hotel_id': proc['hotel'], 'room_number': '101', 'check_in': proc['from'], 'check_out': proc['to'], 'private_room': '1', 'confirmation': 'C-101'})
check('Booking them a bed settles the request', next(r for r in probe()['requests'] if r['id'] == auto['id'])['status'] == 'closed')
act(admin, {'do': 'settings', 'budget_owner_id': proc['super']})
admin.get('/candidates/%d' % proc['hire2'])
admin.post('/candidates/%d' % proc['hire2'], {'do': 'place', 'start_date': proc['from']})
check('With the setting off, a hire raises nothing', len([r for r in probe()['requests'] if r['source'] == 'hire']) == 1)

# ── cancelling, units ────────────────────────────────────────────────────
act(super_, {'do': 'request', 'category': 'vehicle', 'title': 'Van for the week', 'quantity': '5', 'unit_id': unit_id(super_, 'Van-day')})
vid = request_named('Van for the week')['id']
check('Cancelling needs a reason', act(hotels, {'do': 'cancel', 'request_id': vid, 'reason': ''})[0] == 422)
check('With a reason, the request is cancelled', act(hotels, {'do': 'cancel', 'request_id': vid, 'reason': 'Client provides vans'})[0] == 200
      and request_named('Van for the week')['status'] == 'cancelled')
check('Hotels adds a unit of measure', act(hotels, {'do': 'unit', 'label': 'Box'})[0] == 200 and 'box' in probe()['units'])
check('The same unit twice is refused', act(hotels, {'do': 'unit', 'label': 'Box'})[0] == 422)

status, body = hotels.get('/procurement?lang=fr')
check('Procurement is translated into French', 'Achats' in body and 'Bons de commande' in body)
status, body = hotels.get('/procurement?lang=es')
check('Procurement is translated into Spanish', 'Pedidos de compra' in body)
hotels.get('/procurement?lang=en')

json.dump(results, open('work/proc-http-results.json', 'w'), indent=1)
