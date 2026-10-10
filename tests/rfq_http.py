"""P3-M07 through the screens: requests for quotation to approved vendors,
their answers and declines, quotations listed as received and never
ranked, an order raised on a quotation (not on an expired one), revisions
that keep what was and start authorization again under the new total, the
limits a revision cannot cross, the printed request and order, roles and
translations.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
rj = json.load(open('work/rfq.json'))
php = os.environ.get('PHP_BIN', 'php')
JOB = rj['job']
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
        return self._send(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}, doseq=True).encode()))

    def login(self, email):
        self.get('/login')
        status, body = self.post('/login', {'email': email, 'password': 'TestPassword123!'})
        return (status if '<title>Sign in' not in body else 401), body


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:600] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    out = subprocess.run([php, 'work/rfq_probe.php', str(JOB), *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def act(client, values):
    client.get('/procurement')
    return client.post('/procurement', values)


people = {}
for key in ('admin', 'hotels', 'super', 'payroll'):
    people[key] = Client()
    check('P3-M07 %s login' % key, people[key].login('rfq-%s@test.invalid' % key)[0] == 200)
    if key != 'super':
        people[key].post('/select-project', {'job_id': JOB})
admin, hotels, sup, payroll = (people[k] for k in ('admin', 'hotels', 'super', 'payroll'))

act(hotels, {'do': 'request', 'category': 'safety_equipment', 'title': 'Hard hats', 'quantity': '20', 'unit_id': rj['unit']})
rid = next(r['id'] for r in probe()['requests'] if r['title'] == 'Hard hats')

# ── requests for quotation ───────────────────────────────────────────────
rfq = lambda vendors, reply=rj['in10'], client=hotels: act(client, {'do': 'rfq', 'request_id': rid, 'vendors[]': vendors, 'reply_by': reply})
check('A supervisor cannot ask for quotations', rfq(['Alpha Supply'], client=sup)[0] == 403)
for label, vendors, reply, text in [('No vendor chosen is refused', [], rj['in10'], 'at least one vendor'),
                                    ('A vendor not approved for the category is refused', ['Gamma Rentals'], rj['in10'], 'not approved to supply'),
                                    ('A vendor not yet approved is refused', ['Pending Co'], rj['in10'], 'cannot be ordered from'),
                                    ('A reply-by date in the past is refused', ['Alpha Supply'], rj['yesterday'], 'reply-by date')]:
    status, body = rfq(vendors, reply)
    check(label, status == 422 and text in body, (status, body[:200]))
check('Nothing was sent by the refusals', probe()['rfqs'] == [])
status, _ = rfq(['Alpha Supply', 'Beta Supply'])
rfqs = probe()['rfqs']
check('Logistics asks Alpha and Beta: two requests, each with its own reference', status == 200 and len(rfqs) == 2 and all(f['status'] == 'open' for f in rfqs)
      and rfqs[0]['reference'] != rfqs[1]['reference'] and rfqs[0]['reference'].startswith('RFQ-'), rfqs)
check('Asking Alpha again while it has not answered is refused', 'already asked' in rfq(['Alpha Supply'])[1])
alpha, beta = rfqs
status, body = hotels.get('/procurement?print=rfq&id=%d' % alpha['id'])
check('The request for quotation prints: reference, vendor, quantity, and that it is not an order',
      status == 200 and alpha['reference'] in body and 'Alpha Supply' in body and 'Hard hats' in body and 'not an order' in body and '@page' in body and '/assets/app-icon.svg' in body)

# ── answers ──────────────────────────────────────────────────────────────
answer = lambda f, do, price='', valid='', note='': act(hotels, {'do': do, 'rfq_id': f['id'], 'unit_price': price, 'valid_until': valid, 'note': note})
check('A quotation valid until yesterday is refused', answer(alpha, 'rfq_answer', '12', rj['yesterday'])[0] == 422)
check('Alpha quotes 12, valid ten days', answer(alpha, 'rfq_answer', '12', rj['in10'])[0] == 200 and probe()['rfqs'][0]['status'] == 'answered'
      and probe()['quotes'][-1]['rfq_id'] == alpha['id'] and probe()['quotes'][-1]['unit_price'] == 12)
check('An answered request cannot be answered again', answer(alpha, 'rfq_answer', '11', rj['in10'])[0] == 422)
check('A decline without a reason is refused', answer(beta, 'rfq_decline')[0] == 422)
check('Beta declines, with its reason', answer(beta, 'rfq_decline', note='Out of stock until next month')[0] == 200 and probe()['rfqs'][1]['status'] == 'declined')
act(hotels, {'do': 'quote', 'request_id': rid, 'vendor_name': 'Beta Supply', 'unit_price': '9', 'note': 'Phoned in later'})

# ── listed as received, never ranked ────────────────────────────────────
body = hotels.get('/procurement')[1]
listed = [int(x) for x in re.findall(r'data-quote="(\d+)"', body)]
check('Quotations are listed as they came: the 12 first, the cheaper 9 second', listed == [q['id'] for q in probe()['quotes']] and listed == sorted(listed))
order_form = re.search(r'name="do" value="order"><input type="hidden" name="request_id" value="%d">\s*<input name="vendor_name"[^>]*>' % rid, body)
check('The order form is not filled with any quotation', order_form and 'value=' not in order_form.group(0).split('name="vendor_name"')[1], order_form.group(0) if order_form else None)

# ── an order on a quotation ──────────────────────────────────────────────
beta_quote = probe()['quotes'][-1]['id']
probe('expire', beta_quote)
status, body = act(hotels, {'do': 'order', 'request_id': rid, 'quotation_id': beta_quote})
check('An order on an expired quotation is refused', status == 422 and 'expired' in body, (status, body[:200]))
alpha_quote = probe()['quotes'][0]['id']
check('The order is raised on Alpha\'s quotation: its vendor, its price, 20 x 12 = 240', act(hotels, {'do': 'order', 'request_id': rid, 'quotation_id': alpha_quote})[0] == 200
      and probe()['orders'][-1]['vendor_name'] == 'Alpha Supply' and probe()['orders'][-1]['total'] == 240 and probe()['orders'][-1]['quotation_id'] == alpha_quote, probe()['orders'])
po = probe()['orders'][-1]['id']
order = lambda: probe()['orders'][-1]
check('The budget owner approves revision 0', act(sup, {'do': 'approve', 'purchase_order_id': po})[0] == 200 and order()['status'] == 'approved' and order()['revision'] == 0)
act(hotels, {'do': 'receive', 'purchase_order_id': po, 'quantity': '5', 'received_on': rj['today']})

# ── revisions ────────────────────────────────────────────────────────────
revise = lambda qty, price, why='Crew grew', client=hotels, extra=None: act(client, {'do': 'revise', 'purchase_order_id': po, 'quantity': qty, 'unit_price': price, 'reason': why, **(extra or {})})
check('A supervisor cannot revise an order', revise('30', '12', client=sup)[0] == 403)
for label, args, text in [('A revision without a reason is refused', ('30', '12', ''), 'Say why'),
                          ('Fewer than already arrived is refused', ('4', '12'), 'already arrived'),
                          ('A revision that changes nothing is refused', ('20', '12'), 'Nothing changes')]:
    status, body = revise(*args)
    check(label, status == 422 and text in body, (status, body[:200]))
check('Revised to 30 hard hats: 360, revision 1, waiting for the budget owner again', revise('30', '12')[0] == 200
      and order()['total'] == 360 and order()['revision'] == 1 and order()['status'] == 'awaiting_approval' and order()['approvers'] == 'budget_owner', order())
rv = probe()['revisions'][-1]
check('The revision keeps the order as it was and as it became', json.loads(rv['before_json'])['total'] == 240 and json.loads(rv['after_json'])['total'] == 360
      and json.loads(rv['before_json'])['status'] == 'approved' and rv['reason'] == 'Crew grew')
check('Nothing is received while the revision waits', act(hotels, {'do': 'receive', 'purchase_order_id': po, 'quantity': '1', 'received_on': rj['today']})[0] == 422)
check('The approval of revision 0 does not count for revision 1: the budget owner approves again', act(sup, {'do': 'approve', 'purchase_order_id': po})[0] == 200
      and order()['status'] == 'approved' and [(a['revision'], a['approver']) for a in probe()['approvals'] if a['purchase_order_id'] == po] == [(0, 'budget_owner'), (1, 'budget_owner')])

payroll.get('/accounts-payable')
payroll.post('/accounts-payable', {'do': 'invoice', 'vendor_name': 'Alpha Supply', 'reference': 'AS-1', 'amount': '300', 'due_on': rj['today'], 'purchase_order_id': po})
status, body = revise('30', '9', 'Price dropped')
check('A revision below what is already invoiced is refused', status == 422 and 'already invoiced' in body, (status, body[:200]))
check('Revised to 200 a hat: 6,000 goes to an administrator', revise('30', '200', 'Specialist hats')[0] == 200 and order()['approvers'] == 'admin' and order()['revision'] == 2)
check('The budget owner cannot approve it now', act(sup, {'do': 'approve', 'purchase_order_id': po})[0] == 403)
check('An administrator does', act(admin, {'do': 'approve', 'purchase_order_id': po})[0] == 200 and order()['status'] == 'approved')
status, body = hotels.get('/procurement?print=order&id=%d' % po)
check('The order prints with its revision, total and who authorised it', status == 200 and 'revision 2' in body and '$6,000.00' in body and 'RFQ admin' in body and 'NOT AUTHORISED' not in body)
revise('30', '199', 'Rounded down')
status, body = hotels.get('/procurement?print=order&id=%d' % po)
check('Revised again and not yet approved, it prints as not authorised', 'NOT AUTHORISED' in body and 'revision 3' in body)
body = hotels.get('/procurement')[1]
check('The order shows every revision with its reason', [int(x) for x in re.findall(r'data-revision-row="(\d+)"', body)] == [1, 2, 3] and 'Specialist hats' in body)
check('Another project\'s document does not print here', hotels.get('/procurement?print=order&id=999999')[0] == 404)

status, body = hotels.get('/procurement?lang=fr')
check('Procurement\'s new steps are translated into French', 'Répondu' in body and 'Réviser' in body)
status, body = hotels.get('/procurement?lang=es')
check('and into Spanish', 'Respondida' in body and 'Revisar' in body)
hotels.get('/procurement?lang=en')

json.dump(results, open('work/rfq-http-results.json', 'w'), indent=1)
