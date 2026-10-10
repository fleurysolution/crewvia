"""P3-M06 through the screens: proposing and approving vendors, every reason
an order to a vendor is refused, approval by threshold (budget owner,
administrator, both and never one person twice), an order past a budget
line needing an administrator, an order split across projects and its
invoice landing on each project's costs and Class, suspending, the tiers,
roles and translations.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
vj = json.load(open('work/vend.json'))
php = os.environ.get('PHP_BIN', 'php')
ids = vj['ids']
A, B = vj['job_a'], vj['job_b']
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


def probe():
    return json.loads(subprocess.run([php, 'work/vendors_probe.php', str(A), str(B)], check=True, capture_output=True, text=True).stdout)


def vendor(name):
    return next((v for v in probe()['vendors'] if v['name'] == name), None)


def vend(client, values):
    client.get('/vendors')
    return client.post('/vendors', values)


def propose(client, name, cats, w9=True, insurance=None):
    values = {'do': 'save', 'name': name, 'categories[]': cats, 'insurance_expires': insurance or vj['in_year']}
    if w9:
        values['w9_on_file'] = '1'
    return vend(client, values)


def proc(client, values, job=A):
    client.post('/select-project', {'job_id': job})
    client.get('/procurement')
    return client.post('/procurement', values)


def order(client, rid, vendor_name, price, job=A, shares=None):
    values = {'do': 'order', 'request_id': rid, 'vendor_name': vendor_name, 'unit_price': str(price)}
    if shares:
        values['share_job[]'] = [str(j) for j, _ in shares]
        values['share_amount[]'] = [str(a) for _, a in shares]
    return proc(client, values, job)


def last_order():
    return probe()['orders'][-1]


def decide(client, oid, do='approve', job=A, note=''):
    return proc(client, {'do': do, 'purchase_order_id': oid, 'note': note}, job)


people = {}
for key in ('admin', 'admin2', 'hotels', 'super', 'payroll', 'recruiter'):
    people[key] = Client()
    check('P3-M06 %s login' % key, people[key].login('vend-%s@test.invalid' % key)[0] == 200)
admin, admin2, hotels, sup, payroll, recruiter = (people[k] for k in ('admin', 'admin2', 'hotels', 'super', 'payroll', 'recruiter'))

reqs = {}


def new_request(title, qty, category='safety_equipment', job=A):
    proc(hotels, {'do': 'request', 'category': category, 'title': title, 'quantity': str(qty), 'unit_id': vj['unit']}, job)
    body = hotels.get('/procurement')[1]
    rid = max(int(x) for x in re.findall(r'name="do" value="order"><input type="hidden" name="request_id" value="(\d+)"', body))
    reqs[title] = rid
    return rid


# ── vendors ──────────────────────────────────────────────────────────────
check('A recruiter cannot see vendors', recruiter.get('/vendors')[0] == 403)
check('A vendor that supplies nothing is refused', vend(hotels, {'do': 'save', 'name': 'Empty Co'})[0] == 422)
check('Logistics proposes Harness Supply for safety equipment; it waits for approval',
      propose(hotels, 'Harness Supply', ['safety_equipment'])[0] == 200 and vendor('Harness Supply')['status'] == 'pending')
check('The same name twice is refused', propose(hotels, 'Harness Supply', ['other'])[0] == 422)
r1 = new_request('Harnesses', 10)
status, body = order(hotels, r1, 'Harness Supply', 80)
check('An order to a vendor not yet approved is refused', status == 422 and 'cannot be ordered from' in body, (status, body[:200]))
status, body = order(hotels, r1, 'Nobody Inc', 80)
check('An order to a vendor not registered is refused', status == 422 and 'not a registered vendor' in body, (status, body[:200]))
check('Logistics cannot approve a vendor', vend(hotels, {'do': 'approve', 'vendor_id': vendor('Harness Supply')['id']})[0] == 403)
check('An administrator approves it', vend(admin, {'do': 'approve', 'vendor_id': vendor('Harness Supply')['id']})[0] == 200 and vendor('Harness Supply')['status'] == 'approved')
propose(admin, 'Admin Pick', ['other'])
status, body = vend(admin, {'do': 'approve', 'vendor_id': vendor('Admin Pick')['id']})
check('Whoever proposed a vendor cannot approve it', status == 422 and 'does not approve it' in body)
propose(hotels, 'Lapsed Co', ['safety_equipment'], insurance=vj['ago'])
propose(hotels, 'No Paper Co', ['safety_equipment'], w9=False)
for n in ('Lapsed Co', 'No Paper Co'):
    vend(admin, {'do': 'approve', 'vendor_id': vendor(n)['id']})
check('An order to a vendor whose insurance expired is refused', 'insurance expired' in order(hotels, r1, 'Lapsed Co', 80)[1])
check('An order to a vendor with no W-9 is refused', 'no W-9 on file' in order(hotels, r1, 'No Paper Co', 80)[1])
rv = new_request('Van rental', 1, 'vehicle')
check('An order for something the vendor is not approved for is refused', 'not approved to supply' in order(hotels, rv, 'Harness Supply', 500)[1])

# ── thresholds and the budget ────────────────────────────────────────────
check('An order of 800 to the approved vendor: the budget owner\'s', order(hotels, r1, 'Harness Supply', 80)[0] == 200
      and last_order()['approvers'] == 'budget_owner' and last_order()['over_budget'] == 0, last_order())
o1 = last_order()['id']
check('The budget owner approves it alone', decide(sup, o1)[0] == 200 and last_order()['status'] == 'approved')
r2 = new_request('More harnesses', 10)
order(hotels, r2, 'Harness Supply', 70)
o2 = last_order()
check('700 more with 200 left on the equipment budget: over budget, so an administrator approves too', o2['over_budget'] == 1 and o2['approvers'] == 'budget_owner', o2)
status, body = decide(sup, o2['id'])
check('The budget owner approves; it still waits for an administrator', status == 200 and last_order()['status'] == 'awaiting_approval' and 'Waiting for an administrator' in sup.get('/procurement')[1])
check('The budget owner cannot approve it a second time', decide(sup, o2['id'])[0] == 403)
check('An administrator completes it', decide(admin, o2['id'])[0] == 200 and last_order()['status'] == 'approved'
      and sorted(a['approver'] for a in probe()['approvals'] if a['purchase_order_id'] == o2['id']) == ['admin', 'budget_owner'])

r3 = new_request('Gas detectors', 100, job=B)
order(hotels, r3, 'Harness Supply', 60, job=B)
o3 = last_order()
check('6,000 is an administrator\'s', o3['approvers'] == 'admin', o3)
check('The budget owner of another project cannot approve it', decide(sup, o3['id'], job=B)[0] in (403, 404) and last_order()['status'] == 'awaiting_approval')
check('One administrator approves it alone', decide(admin, o3['id'], job=B)[0] == 200 and last_order()['status'] == 'approved')
r4 = new_request('Site cabins', 300, job=B)
order(hotels, r4, 'Harness Supply', 100, job=B)
o4 = last_order()
check('30,000 needs both', o4['approvers'] == 'both', o4)
decide(admin, o4['id'], job=B)
check('The same administrator cannot give the second approval', decide(admin, o4['id'], job=B)[0] == 403 and last_order()['status'] == 'awaiting_approval')
check('A second administrator gives it', decide(admin2, o4['id'], job=B)[0] == 200 and last_order()['status'] == 'approved')

# ── split across projects ────────────────────────────────────────────────
r5 = new_request('Radios', 4)
status, body = order(hotels, r5, 'Harness Supply', 250, shares=[(A, 600), (B, 300)])
check('Shares that do not add up to the total are refused', status == 422 and 'add up to' in body, (status, body[:200]))
check('1,000 of radios split 600 to project A and 400 to project B', order(hotels, r5, 'Harness Supply', 250, shares=[(A, 600), (B, 400)])[0] == 200
      and sorted((s['job_id'], s['amount']) for s in probe()['shares'] if s['purchase_order_id'] == last_order()['id']) == sorted([(A, 600), (B, 400)]))
o5 = last_order()['id']
decide(sup, o5); decide(admin, o5)
check('Approved by both, being over A\'s budget', last_order()['status'] == 'approved')
payroll.post('/select-project', {'job_id': A})
payroll.get('/accounts-payable')
payroll.post('/accounts-payable', {'do': 'invoice', 'vendor_name': 'Harness Supply', 'reference': 'HS-RADIO-1', 'amount': '1000', 'due_on': vj['today'], 'purchase_order_id': o5})
bill = next(b for b in probe()['bills'] if b['purchase_order_id'] == o5)
payroll.get('/accounts-payable')
payroll.post('/accounts-payable', {'do': 'approve', 'invoice_id': bill['id']})


def equipment(job):
    payroll.post('/select-project', {'job_id': job})
    body = payroll.get('/project-costs')[1]
    return float(re.search(r'data-line="equipment" data-actual="([\d.-]+)"', body).group(1))


check('The radio invoice costs project A 600 and project B 400', equipment(A) == 600 and equipment(B) == 400, (equipment(A), equipment(B)))
payroll.get('/accounting')
payroll.post('/accounting', {'do': 'export', 'through': vj['today']})
lines = [l for l in probe()['lines'] if l['source'] == 'vendor_invoice:%d:bill' % bill['id']]
check('In QuickBooks the bill\'s cost goes 600 to project A\'s Class and 400 to B\'s, against 1,000 owed',
      sorted((l['class'], l['debit']) for l in lines if l['debit'] > 0) == sorted([('P3-M06 project A', 600), ('P3-M06 project B', 400)])
      and sum(l['credit'] for l in lines) == 1000, lines)

# ── suspending, and the tiers ────────────────────────────────────────────
vend(admin, {'do': 'suspend', 'vendor_id': vendor('Harness Supply')['id'], 'reason': ''})
check('Suspending needs a reason', vendor('Harness Supply')['status'] == 'approved')
vend(admin, {'do': 'suspend', 'vendor_id': vendor('Harness Supply')['id'], 'reason': 'Late deliveries'})
r6 = new_request('Spare harnesses', 1)
check('A suspended vendor cannot be ordered from', vendor('Harness Supply')['status'] == 'suspended' and 'cannot be ordered from' in order(hotels, r6, 'Harness Supply', 80)[1])
vend(admin, {'do': 'reinstate', 'vendor_id': vendor('Harness Supply')['id'], 'reason': 'Deliveries back on time'})
check('Reinstated, it can be', order(hotels, r6, 'Harness Supply', 80)[0] == 200)
check('Its history: proposed, approved, suspended, reinstated', [e['event'] for e in probe()['events'] if e['name'] == 'Harness Supply'] == ['proposed', 'approve', 'suspend', 'reinstate'])
check('Logistics cannot change the tiers', vend(hotels, {'do': 'thresholds', 'up_to[]': ['1000', ''], 'approvers[]': ['budget_owner', 'admin']})[0] == 403)
check('Tiers without an open last tier are refused', vend(admin, {'do': 'thresholds', 'up_to[]': ['1000'], 'approvers[]': ['budget_owner']})[0] == 422)
check('Tiers out of order are refused', vend(admin, {'do': 'thresholds', 'up_to[]': ['2000', '1000', ''], 'approvers[]': ['budget_owner', 'admin', 'both']})[0] == 422)
check('An administrator sets 1,000 for the budget owner and an administrator above',
      vend(admin, {'do': 'thresholds', 'up_to[]': ['1000', ''], 'approvers[]': ['budget_owner', 'admin']})[0] == 200
      and [t['approvers'] for t in probe()['tiers']] == ['budget_owner', 'admin'])
r7 = new_request('Fall arrest kits', 12, job=B)
order(hotels, r7, 'Harness Supply', 100, job=B)
check('An order of 1,200 is now an administrator\'s', last_order()['approvers'] == 'admin')
vend(admin, {'do': 'thresholds', 'up_to[]': ['5000', '25000', ''], 'approvers[]': ['budget_owner', 'admin', 'both']})

status, body = admin.get('/vendors?lang=fr')
check('Vendors is translated into French', 'Fournisseurs agréés' in body and 'Qui approuve une commande' in body)
status, body = admin.get('/vendors?lang=es')
check('Vendors is translated into Spanish', 'Proveedores aprobados' in body)
admin.get('/vendors?lang=en')

json.dump(results, open('work/vend-http-results.json', 'w'), indent=1)
