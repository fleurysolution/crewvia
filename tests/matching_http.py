"""P3-M08 through the screens: receiving with rejections, every three-way
match exception, payment refused until a bill is ready, clearing an
exception (and a clearance that stops counting when the exceptions change),
bills with no order, the tolerance, roles and translations.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
mj = json.load(open('work/match.json'))
php = os.environ.get('PHP_BIN', 'php')
JOB = mj['job']
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
    return json.loads(subprocess.run([php, 'work/matching_probe.php', str(JOB)], check=True, capture_output=True, text=True).stdout)


def act(client, path, values):
    client.get(path)
    return client.post(path, values)


people = {}
for key in ('admin', 'hotels', 'payroll', 'recruiter'):
    people[key] = Client()
    check('P3-M08 %s login' % key, people[key].login('match-%s@test.invalid' % key)[0] == 200)
    people[key].post('/select-project', {'job_id': JOB})
admin, hotels, payroll, recruiter = (people[k] for k in ('admin', 'hotels', 'payroll', 'recruiter'))

check('A recruiter cannot see bill matching', recruiter.get('/bill-matching')[0] == 403)


def order(title, qty, price):
    act(hotels, '/procurement', {'do': 'request', 'category': 'safety_equipment', 'title': title, 'quantity': str(qty), 'unit_id': mj['unit']})
    rid = next(r['id'] for r in probe()['requests'] if r['title'] == title)
    act(hotels, '/procurement', {'do': 'order', 'request_id': rid, 'vendor_name': 'Delta Tools', 'unit_price': str(price)})
    oid = next(o['id'] for o in probe()['orders'] if o['title'] == title)
    act(admin, '/procurement', {'do': 'approve', 'purchase_order_id': oid})
    return oid


def receive(oid, qty, rejected='', why=''):
    return act(hotels, '/procurement', {'do': 'receive', 'purchase_order_id': oid, 'quantity': qty, 'received_on': mj['today'], 'rejected_quantity': rejected, 'rejection_reason': why})


def bill(ref, amount, po, qty='', vendor='Delta Tools', approve=True):
    act(payroll, '/accounts-payable', {'do': 'invoice', 'vendor_name': vendor, 'reference': ref, 'amount': str(amount), 'due_on': mj['today'], 'purchase_order_id': po, 'quantity': qty})
    b = next(x for x in probe()['bills'] if x['reference'] == ref)
    if approve:
        act(payroll, '/accounts-payable', {'do': 'approve', 'invoice_id': b['id']})
    return b['id']


def pay(bid, ref):
    return act(payroll, '/accounts-payable', {'do': 'pay', 'invoice_id': bid, 'payment_method': 'transfer', 'payment_reference': ref})


def state(ref):
    body = payroll.get('/bill-matching')[1]
    codes = re.search(r'<tr data-bill="%s" data-codes="([^"]*)"' % re.escape(ref), body)
    ready = re.search(r'<tr data-open-bill="%s" data-ready="(\d)"' % re.escape(ref), body)
    return (codes.group(1).split(',') if codes else []), (ready.group(1) == '1' if ready else None)


def status(bid):
    return next(x for x in probe()['bills'] if x['id'] == bid)['status']


# ── receiving, with what was rejected ───────────────────────────────────
po = order('Cordless drills', 10, 50)
check('An order for 10 drills at 50: 500, approved', next(o for o in probe()['orders'] if o['id'] == po)['status'] == 'approved')
check('A rejection without a reason is refused', receive(po, '6', '2', '')[0] == 422 and probe()['receipts'] == [])
check('6 accepted and 2 rejected for a cracked casing', receive(po, '6', '2', 'Cracked casing')[0] == 200
      and probe()['receipts'][-1] == {'purchase_order_id': po, 'quantity': 6, 'rejected_quantity': 2, 'rejection_reason': 'Cracked casing'})
check('A delivery that is only rejections is recorded, nothing received', receive(po, '', '1', 'Wrong model')[0] == 200 and probe()['receipts'][-1]['quantity'] == 0)
check('Rejected drills are not received: 5 more would be 11 of 10', receive(po, '5')[0] == 422)

# ── a bill that matches ──────────────────────────────────────────────────
b1 = bill('DT-1', 300, po, '6')
codes, ready = state('DT-1')
check('Bill DT-1, 6 drills for 300: matched, ready to pay', codes == [] and ready is True, (codes, ready))
check('It is paid', pay(b1, 'PAY-DT-1')[0] == 200 and status(b1) == 'paid')

# ── a bill that does not ─────────────────────────────────────────────────
b2 = bill('DT-2', 250, po)
codes, ready = state('DT-2')
check('Bill DT-2 for 250 more: beyond the order (550 of 500) and beyond what arrived (300)', codes == ['over_order', 'over_receipt'] and ready is False, codes)
status_code, body = pay(b2, 'PAY-DT-2')
check('Paying it is refused, with the reason', status_code == 422 and 'not ready to pay' in body and 'billed beyond the order' in body, body[:200])
status_code, body = act(payroll, '/balances', {'side': 'ap', 'party': 'Delta Tools', 'do': 'record', 'received_on': mj['today'], 'amount': '250', 'method': 'check', 'reference': 'PAY-DT-2b', 'apply[%d]' % b2: '250'})
check('Paying it from payables is refused too', status_code == 422 and 'not ready to pay' in body, body[:200])
check('Payroll cannot clear an exception', act(payroll, '/bill-matching', {'do': 'clear', 'invoice_id': b2, 'reason': 'Agreed'})[0] == 403)
check('Clearing without a reason is refused', act(admin, '/bill-matching', {'do': 'clear', 'invoice_id': b2, 'reason': ''})[0] == 422)
check('An administrator clears it: the vendor delivered extras agreed by phone', act(admin, '/bill-matching', {'do': 'clear', 'invoice_id': b2, 'reason': 'Extras agreed by phone'})[0] == 200
      and probe()['clearances'][-1]['codes'] == 'over_order,over_receipt' and state('DT-2')[1] is True)
check('Clearing it twice is refused', act(admin, '/bill-matching', {'do': 'clear', 'invoice_id': b2, 'reason': 'Again'})[0] == 422)
check('Cleared, it is paid', pay(b2, 'PAY-DT-2')[0] == 200 and status(b2) == 'paid')

# ── a clearance covers exactly what it cleared ──────────────────────────
b3 = bill('DT-3', 40, po, '1')
codes, _ = state('DT-3')
check('Bill DT-3, 1 drill at 40 instead of 50: also a price difference', codes == ['over_order', 'over_receipt', 'price_variance'], codes)
act(admin, '/bill-matching', {'do': 'clear', 'invoice_id': b3, 'reason': 'Discount for the late delivery'})
check('Cleared, it is ready', state('DT-3')[1] is True)
check('Payroll cannot change the tolerance', act(payroll, '/bill-matching', {'do': 'tolerance', 'percent': '20', 'amount': '10'})[0] == 403)
check('A tolerance above 25 % is refused', act(admin, '/bill-matching', {'do': 'tolerance', 'percent': '30', 'amount': '10'})[0] == 422)
act(admin, '/bill-matching', {'do': 'tolerance', 'percent': '20', 'amount': '10'})
codes, ready = state('DT-3')
check('At 20 % the exceptions change: the clearance no longer counts, and it is held again', codes == ['over_receipt'] and ready is False, (codes, ready))
act(admin, '/bill-matching', {'do': 'tolerance', 'percent': '2', 'amount': '10'})

# ── the other exceptions ─────────────────────────────────────────────────
b4 = bill('OT-1', 100, po, '2', vendor='Other Tools')
check('A bill from another vendor than the order\'s', 'vendor_mismatch' in state('OT-1')[0])
po2 = order('Safety glasses', 20, 5)
b5 = bill('DT-4', 100, po2)
check('A bill before anything arrived', state('DT-4')[0] == ['not_received'])
receive(po2, '20')
check('Once it arrives, it matches', state('DT-4') == ([], True))
rid2 = next(r['id'] for r in probe()['requests'] if r['title'] == 'Safety glasses')
act(hotels, '/procurement', {'do': 'revise', 'purchase_order_id': po2, 'quantity': '20', 'unit_price': '6', 'reason': 'Price went up'})
check('While a revision of the order waits for approval, its bill is held', 'order_not_authorised' in state('DT-4')[0] and state('DT-4')[1] is False)
act(admin, '/procurement', {'do': 'approve', 'purchase_order_id': po2})
check('Revision approved, the bill matches again', state('DT-4') == ([], True))

# ── a bill with no order ─────────────────────────────────────────────────
b6 = bill('UTIL-1', 80, '')
body = payroll.get('/bill-matching')[1]
check('A bill with no order is checked by its approval alone, and is ready', state('UTIL-1')[1] is True and 'No order: approval only' in body)
body = payroll.get('/accounts-payable')[1]
check('The bill register shows each bill\'s match', 'data-match="exception"' in body and 'data-match="matched"' in body and 'No order: approval only' in body)

status_code, body = admin.get('/bill-matching?lang=fr')
check('Bill matching is translated into French', 'Rapprochement des factures' in body and 'Prêt à payer' in body)
status_code, body = admin.get('/bill-matching?lang=es')
check('Bill matching is translated into Spanish', 'Conciliación de facturas' in body)
admin.get('/bill-matching?lang=en')

json.dump(results, open('work/match-http-results.json', 'w'), indent=1)
