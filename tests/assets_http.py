"""Assets through the screens: register, register from an order, issue and
return with condition, repairs, inspections, loss, retirement, the
client-owned and overdue-inspection refusals, roles and translations.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
ax = json.load(open('work/assets.json'))
php = os.environ.get('PHP_BIN', 'php')
tag = ax['tag']
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
    return json.loads(subprocess.run([php, 'work/assets_probe.php', tag], check=True, capture_output=True, text=True).stdout)


def item(asset_tag):
    return next((i for i in probe()['items'] if i['asset_tag'] == asset_tag), None)


def open_issue(equipment_id):
    return next((i for i in probe()['issues'] if int(i['equipment_id']) == equipment_id and i['returned'] in (0, '0')), None)


def events(equipment_id):
    return [e for e in probe()['events'] if int(e['equipment_id']) == equipment_id]


def assets_post(client, values):
    client.get('/assets')
    return client.post('/assets', values)


def act(client, equipment_id, action, **extra):
    client.get('/assets?id=%d' % equipment_id)
    return client.post('/assets', {'do': 'act', 'equipment_id': equipment_id, 'action': action, **extra})


def ops(client, values):
    client.get('/operations')
    return client.post('/operations', values)


hotels = Client(); check('Assets hotels login', hotels.login('asset-hotels@test.invalid')[0] == 200)
recruiter = Client(); check('Assets recruiter login', recruiter.login('asset-recruiter@test.invalid')[0] == 200)
payroll = Client(); check('Assets payroll login', payroll.login('asset-payroll@test.invalid')[0] == 200)
for c in (hotels, recruiter, payroll):
    c.post('/select-project', {'job_id': ax['job']})
p1, p2 = ax['placements']

# ── who may do what ──────────────────────────────────────────────────────
check('Payroll cannot open the asset register', payroll.get('/assets')[0] == 403)
status, body = recruiter.get('/assets')
check('A recruiter sees the register without the forms', status == 200 and 'id="asset-list"' in body and 'id="asset-register"' not in body)
check('A recruiter cannot register an item', assets_post(recruiter, {'do': 'register', 'name': 'Nope', 'asset_tag': tag + '-X'})[0] == 403)

# ── registering ──────────────────────────────────────────────────────────
for label, values in [('A one-letter name is refused', {'name': 'H', 'asset_tag': tag + '-H1'}),
                      ('A tag already on another item is refused', {'name': 'Harness', 'asset_tag': tag + '-C'}),
                      ('A negative cost is refused', {'name': 'Harness', 'asset_tag': tag + '-H1', 'purchase_cost': '-5'}),
                      ('A client owner that does not exist is refused', {'name': 'Harness', 'asset_tag': tag + '-H1', 'owner_type': 'client', 'owner_client_id': '999999'})]:
    status, body = assets_post(hotels, {'do': 'register', **values})
    check(label, status == 422, (status, body[:200]))
status, _ = assets_post(hotels, {'do': 'register', 'name': 'Harness', 'asset_tag': tag + '-H1', 'category_id': ax['fall'], 'serial_number': 'SN-1', 'purchase_cost': '199.99'})
h1 = item(tag + '-H1')
check('Logistics registers a harness; its first inspection is due in 180 days', status == 200 and h1 and h1['status'] == 'available' and h1['inspection_due'] == ax['in_180'], h1)
check('Registering is in its history', [e['event'] for e in events(int(h1['id']))] == ['registered'])
h1 = int(h1['id'])

# ── from a purchase order ────────────────────────────────────────────────
status, body = assets_post(hotels, {'do': 'from_po', 'purchase_order_id': ax['room_order'], 'name': 'Rooms', 'tag_prefix': tag + '-R', 'count': '1'})
check('A lodging order cannot become assets', status == 422, (status, body[:200]))
status, body = assets_post(hotels, {'do': 'from_po', 'purchase_order_id': ax['harness_order'], 'name': 'Full-body harness', 'category_id': ax['fall'], 'tag_prefix': tag + '-PO', 'count': '3'})
check('Three cannot be registered when two were received', status == 422 and '2 item(s) received' in body, (status, body[:200]))
status, body = assets_post(hotels, {'do': 'from_po', 'purchase_order_id': ax['harness_order'], 'name': 'Full-body harness', 'category_id': ax['fall'], 'tag_prefix': tag + '-PO', 'count': '2'})
po1, po2 = item(tag + '-PO-001'), item(tag + '-PO-002')
check('The two received are registered, numbered, at the order\'s unit price', status == 200 and po1 and po2
      and float(po1['purchase_cost']) == 245.5 and int(po1['purchase_order_id']) == ax['harness_order'], (po1, po2))
check('Nothing is left to register on that order', assets_post(hotels, {'do': 'from_po', 'purchase_order_id': ax['harness_order'], 'name': 'Full-body harness',
      'tag_prefix': tag + '-PO', 'count': '1'})[0] == 422)
po1 = int(po1['id'])

# ── issuing ──────────────────────────────────────────────────────────────
status, body = hotels.get('/operations')
check('Operations lists only what can be issued: not the other client\'s radio, not the overdue detector',
      tag + '-H1' in body and tag + '-C' not in body and tag + '-G' not in body)
status, body = ops(hotels, {'do': 'issue', 'placement_id': p1, 'equipment_id': ax['client_item']})
check('Another client\'s item is refused on this project', status == 422 and 'another client' in body, (status, body[:200]))
status, body = ops(hotels, {'do': 'issue', 'placement_id': p1, 'equipment_id': ax['overdue_item']})
check('An item past its inspection is refused', status == 422 and 'past its inspection' in body, (status, body[:200]))
status, _ = ops(hotels, {'do': 'issue', 'placement_id': p1, 'equipment_id': h1, 'issue_condition': 'worn'})
i = open_issue(h1)
check('The harness goes out worn, and who issued it is kept', status == 200 and i and i['issue_condition'] == 'worn' and i['issued_by'], i)
check('The same harness cannot go to a second worker', ops(hotels, {'do': 'issue', 'placement_id': p2, 'equipment_id': h1})[0] == 422)
status, body = recruiter.get('/assets?id=%d' % h1)
check('Its page shows it issued, with the worker', 'data-state="issued"' in body and 'Asset worker one' in body)
check('An issued item cannot be sent for repair', act(hotels, h1, 'repair', note='Frayed strap')[1].startswith('Take the item back'))
status, _ = act(hotels, h1, 'inspect', note='Checked on site')
check('It can be inspected while out; the next is 180 days on', status == 200 and item(tag + '-H1')['inspection_due'] == ax['in_180'])

# ── returning ────────────────────────────────────────────────────────────
status, body = ops(hotels, {'do': 'return', 'issue_id': i['id'], 'return_condition': 'damaged', 'return_note': ''})
check('Returned damaged with no note is refused', status == 422, (status, body[:200]))
status, _ = ops(hotels, {'do': 'return', 'issue_id': i['id'], 'return_condition': 'damaged', 'return_note': 'Strap cut on rebar'})
r = next(x for x in probe()['issues'] if x['id'] == i['id'])
check('Returned damaged, it goes to repair; condition and receiver are kept', status == 200 and item(tag + '-H1')['status'] == 'in_repair'
      and r['return_condition'] == 'damaged' and r['returned_by'], r)
check('An item in repair cannot be issued', ops(hotels, {'do': 'issue', 'placement_id': p2, 'equipment_id': h1})[0] == 422)
status, _ = act(hotels, h1, 'repaired', note='New strap', cost='40')
check('Back from repair it is available, and the repair cost is in its history', status == 200 and item(tag + '-H1')['status'] == 'available'
      and any(e['event'] == 'repaired' and float(e['cost']) == 40 for e in events(h1)))

ops(hotels, {'do': 'issue', 'placement_id': p2, 'equipment_id': po1})
i2 = open_issue(po1)
status, _ = ops(hotels, {'do': 'return', 'issue_id': i2['id'], 'return_condition': 'lost', 'return_note': 'Left at the previous site'})
check('Returned lost, it is marked lost', status == 200 and item(tag + '-PO-001')['status'] == 'lost' and events(po1)[-1]['event'] == 'lost')
check('A lost item cannot be issued', ops(hotels, {'do': 'issue', 'placement_id': p2, 'equipment_id': po1})[0] == 422)
check('Found again, it is available', act(hotels, po1, 'restore', note='Found in the gang box')[0] == 200 and item(tag + '-PO-001')['status'] == 'available')
check('Retiring without a reason is refused', act(hotels, po1, 'retire', note='')[0] == 422)
check('Retired with a reason', act(hotels, po1, 'retire', note='Past its service life')[0] == 200 and item(tag + '-PO-001')['status'] == 'retired')
check('A retired item cannot come back from repair', act(hotels, po1, 'repaired')[0] == 422)
check('A recruiter cannot record a repair', act(recruiter, h1, 'repair', note='Not mine')[0] == 403)

# ── inspections ──────────────────────────────────────────────────────────
check('A next inspection in the past is refused', act(hotels, ax['overdue_item'], 'inspect', next_due='2020-01-01')[0] == 422)
status, _ = act(hotels, ax['overdue_item'], 'inspect', note='Bump tested')
check('Inspected, the detector is due again in 30 days and can be issued', status == 200 and item(tag + '-G')['inspection_due'] == ax['in_30']
      and ops(hotels, {'do': 'issue', 'placement_id': p2, 'equipment_id': ax['overdue_item']})[0] == 200)

# ── the register ─────────────────────────────────────────────────────────
status, body = recruiter.get('/assets?state=issued&q=' + tag)
check('Filtered on issued, the register shows the detector and not the retired harness',
      'data-tag="%s-G"' % tag in body and 'data-tag="%s-PO-001"' % tag not in body)
status, body = recruiter.get('/assets?state=retired&q=' + tag)
check('Filtered on retired, it shows the retired harness', 'data-tag="%s-PO-001"' % tag in body and 'data-tag="%s-G"' % tag not in body)
status, body = hotels.get('/assets?id=%d' % h1)
check('The harness history reads in order: registered, issued, inspected, returned, repaired',
      [e['event'] for e in events(h1)] == ['registered', 'issued', 'inspect', 'returned', 'repaired'] and 'Back from repair' in body)

status, body = hotels.get('/assets?lang=fr')
check('Assets is translated into French', 'Matériel' in body and 'Enregistrer un article' in body)
status, body = hotels.get('/assets?id=%d&lang=es' % h1)
check('An item page is translated into Spanish', 'Historial' in body and 'Volvió de reparación' in body)
hotels.get('/assets?lang=en')

json.dump(results, open('work/assets-http-results.json', 'w'), indent=1)
