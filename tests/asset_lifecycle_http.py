"""P5-M03 through the screens: categories with class, life and residual;
inspections passed and failed (a failure keeps the item in until one
passes); maintenance plans, one that blocks issue once overdue, done
through a service record that moves the plan on; the service history and
repairs; the in-service date at first issue; retirement and disposal;
straight-line valuation, cost of ownership, gain on disposal; the
valuation report and its CSV; who may see and do what; translations.
"""
import csv, datetime, http.cookiejar, io, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/asset-lifecycle.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS = fx['ids']
TODAY = datetime.date.today()
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
        status, body = self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()))
        assert 'Fatal error' not in body and 'Warning:' not in body, body[:800]
        return status, body


def user(key):
    c = Client()
    c.get('/login')
    status, body = c.post('/login', {'email': 'al-%s@test.invalid' % key, 'password': 'TestPassword123!'})
    assert '<title>Sign in' not in body, key
    c.get('/')
    c.post('/select-project', {'job_id': fx['job']})
    return c


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:600] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    out = subprocess.run([php, 'work/asset_lifecycle_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def item(eid):
    return next(i for i in probe()['items'] if int(i['id']) == eid)


def months(start, end):
    """The same whole-month count as app/asset-lifecycle.php."""
    s, e = datetime.date.fromisoformat(start), end
    if e <= s:
        return 0
    return max(0, (e.year - s.year) * 12 + (e.month - s.month) - (1 if e.day < s.day else 0))


hotels, rec, pay = user('hotels'), user('rec'), user('pay')
D, R, L, N, G, F = fx['detector'], fx['radio'], fx['lift'], fx['nocost'], fx['generator'], fx['formula']


def on_item(c, eid, values):
    c.get('/assets?id=%d' % eid)
    return c.post('/assets', {'equipment_id': eid, **values})


def issue(eid):
    hotels.get('/operations')
    return hotels.post('/operations', {'do': 'issue', 'placement_id': fx['placement'], 'equipment_id': eid, 'issue_condition': 'good'})


def take_back(name):
    """Takes back the item called name, from its own line under Outstanding equipment."""
    body = hotels.get('/operations')[1]
    m = re.search(r'name="issue_id" value="(\d+)"><span>[^<]*' + re.escape(name), body)
    assert m, 'no outstanding line for ' + name
    return hotels.post('/operations', {'do': 'return', 'issue_id': m[1], 'return_condition': 'good', 'return_note': ''})


# ── categories ─────────────────────────────────────────────────────────
hotels.get('/assets')
bad = [('a code with spaces', {'code': 'Lift Gear'}), ('a life of 700 months', {'useful_life_months': '700'}), ('a residual of 95%', {'residual_percent': '95'}),
       ('a code already used', {'code': 'p5_gas'})]
for label, override in bad:
    status, _ = hotels.post('/assets', {'do': 'category_save', 'code': 'p5_lifts', 'label': 'P5 lifting gear', 'asset_class': 'vehicles_plant', 'inspection_days': '180',
                                        'useful_life_months': '120', 'residual_percent': '5', 'is_active': '1', **override})
    check('A category with %s is refused' % label, status == 422)
hotels.post('/assets', {'do': 'category_save', 'code': 'p5_lifts', 'label': 'P5 lifting gear', 'asset_class': 'vehicles_plant', 'inspection_days': '180',
                        'useful_life_months': '120', 'residual_percent': '5', 'is_active': '1'})
cat = next(c for c in probe()['categories'] if c['code'] == 'p5_lifts')
check('Logistics adds a category with its class, inspection interval, useful life and residual share', cat['asset_class'] == 'vehicles_plant' and int(cat['inspection_days']) == 180
      and int(cat['useful_life_months']) == 120 and cat['residual_percent'] == '5.00', cat)
hotels.post('/assets', {'do': 'category_save', 'category_id': cat['id'], 'code': 'p5_lifts', 'label': 'P5 lifting gear', 'asset_class': 'vehicles_plant',
                        'inspection_days': '90', 'useful_life_months': '120', 'residual_percent': '5'})
cat = next(c for c in probe()['categories'] if c['code'] == 'p5_lifts')
check('and changes it, here to every 90 days and out of use', int(cat['inspection_days']) == 90 and int(cat['is_active']) == 0)
rec.get('/assets')
check('A recruiter cannot change categories', rec.post('/assets', {'do': 'category_save', 'code': 'x_rec', 'label': 'Nope', 'asset_class': 'other'})[0] == 403)

# ── inspections ────────────────────────────────────────────────────────
status, _ = on_item(hotels, D, {'do': 'inspection', 'result': 'pass', 'inspected_on': (TODAY + datetime.timedelta(days=1)).isoformat(), 'findings': '', 'next_due': ''})
check('An inspection dated in the future is refused', status == 422)
check('A failed inspection without findings is refused', on_item(hotels, D, {'do': 'inspection', 'result': 'fail', 'inspected_on': TODAY.isoformat(), 'findings': '', 'next_due': ''})[0] == 422)
status, _ = on_item(hotels, D, {'do': 'inspection', 'result': 'pass', 'inspected_on': TODAY.isoformat(), 'findings': 'Bump tested', 'next_due': ''})
check('A pass sets the next inspection from the category: 30 days', status == 200 and item(D)['inspection_due'] == (TODAY + datetime.timedelta(days=30)).isoformat()
      and probe()['inspections'][-1]['result'] == 'pass')
status, _ = on_item(hotels, D, {'do': 'inspection', 'result': 'fail', 'inspected_on': TODAY.isoformat(), 'findings': 'Sensor drifts 20% high', 'next_due': ''})
check('A failure sends the item to repair, with what was found', status == 200 and item(D)['status'] == 'in_repair' and probe()['inspections'][-1]['findings'] == 'Sensor drifts 20% high')
check('and it cannot be issued', issue(D)[0] == 422)
on_item(hotels, D, {'do': 'act', 'action': 'repaired', 'note': 'New sensor head', 'cost': '40', 'next_due': ''})
status, body = issue(D)
check('Back from repair, it still does not go out: the failed inspection stands until one passes', status == 422 and 'failed its last inspection' in body, body[:200])
check('The repair is in the service history with its cost, entered once', any(int(s['equipment_id']) == D and s['kind'] == 'repair' and s['cost'] == '40.00' for s in probe()['services']))
on_item(hotels, D, {'do': 'inspection', 'result': 'pass', 'inspected_on': TODAY.isoformat(), 'findings': 'After repair', 'next_due': ''})
status, body = issue(D)
check('After a pass it goes out', status == 200, body[:200])
check('Failing an inspection on an item that is out is refused: take it back first',
      on_item(hotels, D, {'do': 'inspection', 'result': 'fail', 'inspected_on': TODAY.isoformat(), 'findings': 'Cracked', 'next_due': ''})[0] == 422)
take_back('Gas detector')
check('Taken back', item(D)['status'] == 'available' and not any(e['event'] == 'returned' and int(e['equipment_id']) == N for e in probe()['events']))
issue(N)
check('The first time an item goes out is its in-service date', item(N)['in_service_on'] == TODAY.isoformat())

# ── maintenance ────────────────────────────────────────────────────────
check('A maintenance plan every 0 days is refused', on_item(hotels, D, {'do': 'plan_save', 'task': 'Calibrate the sensor', 'every_days': '0', 'next_due': ''})[0] == 422)
check('and one starting in the past', on_item(hotels, D, {'do': 'plan_save', 'task': 'Calibrate the sensor', 'every_days': '90', 'next_due': '2020-01-01'})[0] == 422)
on_item(hotels, D, {'do': 'plan_save', 'task': 'Calibrate the sensor', 'every_days': '90', 'next_due': '', 'blocks_issue': '1'})
plan = probe()['plans'][-1]
check('A plan every 90 days, first due one interval from today, blocking issue when overdue', plan['task'] == 'Calibrate the sensor' and int(plan['every_days']) == 90
      and plan['next_due'] == (TODAY + datetime.timedelta(days=90)).isoformat() and int(plan['blocks_issue']) == 1, plan)
probe('plan-due', plan['id'], (TODAY - datetime.timedelta(days=3)).isoformat())
status, body = issue(D)
check('Once overdue, the item does not go out', status == 422 and 'Calibrate the sensor' in body, body[:200])
status, body = hotels.get('/assets')
check('and the register lists it as due', 'data-due-plan="%s"' % plan['id'] in body)
base_service = {'do': 'service', 'kind': 'calibration', 'performed_on': TODAY.isoformat(), 'vendor_id': fx['vendor'], 'performed_by': '', 'cost': '85.50',
                'downtime_days': '1', 'reference': 'CAL-1', 'notes': 'Calibrated to span gas', 'plan_id': plan['id']}
check('A service dated in the future is refused', on_item(hotels, D, {**base_service, 'performed_on': (TODAY + datetime.timedelta(days=2)).isoformat()})[0] == 422)
check('and one saying nobody did it', on_item(hotels, D, {**base_service, 'vendor_id': 0, 'performed_by': ''})[0] == 422)
check('and a negative cost', on_item(hotels, D, {**base_service, 'cost': '-1'})[0] == 422)
check('and a plan of another item', on_item(hotels, R, {**base_service, 'vendor_id': fx['vendor']})[0] == 422)
on_item(hotels, D, base_service)
plan = next(p for p in probe()['plans'] if p['id'] == plan['id'])
svc = probe()['services'][-1]
check('The calibration is recorded with its vendor, cost, downtime and reference', svc['kind'] == 'calibration' and int(svc['vendor_id']) == fx['vendor'] and svc['cost'] == '85.50'
      and int(svc['downtime_days']) == 1 and svc['reference'] == 'CAL-1' and int(svc['plan_id']) == int(plan['id']))
check('and moves its plan on 90 days from when it was done', plan['last_done_on'] == TODAY.isoformat() and plan['next_due'] == (TODAY + datetime.timedelta(days=90)).isoformat())
check('so the item goes out again', issue(D)[0] == 200)
take_back('Gas detector')

# ── valuation ──────────────────────────────────────────────────────────
m = min(60, months(fx['months_detector'], TODAY))
acc = round(1080 * m / 60, 2)
status, body = hotels.get('/assets?id=%d' % D)
val = re.search(r'id="asset-valuation" data-valued="(\d)" data-book="([\d.]*)" data-accumulated="([\d.]*)" data-tco="([\d.]*)"', body)
check('The detector is valued straight line: 1,080 over 60 months, %d used: %.2f depreciated, %.2f book' % (m, acc, 1200 - acc),
      val and val[1] == '1' and abs(float(val[3]) - acc) < 0.01 and abs(float(val[2]) - (1200 - acc)) < 0.01, val and val.groups())
check('Its cost of ownership is the price and every service: 1,200 + 40 + 85.50', val and float(val[4]) == 1325.50)
check('A recruiter sees the item without its value or the forms', 'id="asset-valuation"' not in rec.get('/assets?id=%d' % D)[1] and 'id="asset-inspection-form"' not in rec.get('/assets?id=%d' % D)[1])
status, body = hotels.get('/assets?id=%d' % R)
check('A radio past its 12-month life is worth nothing', re.search(r'data-valued="1" data-book="0', body) is not None)
check('A residual value above the cost is refused', on_item(hotels, R, {'do': 'value', 'in_service_on': item(R)['in_service_on'], 'useful_life_months': '12', 'residual_value': '400'})[0] == 422)
check('and an in-service date before it was bought', on_item(hotels, R, {'do': 'value', 'in_service_on': '2000-01-01', 'useful_life_months': '12', 'residual_value': ''})[0] == 422)
on_item(hotels, R, {'do': 'value', 'in_service_on': item(R)['in_service_on'], 'useful_life_months': '12', 'residual_value': '50'})
check('With a residual of 50 it stays worth 50', re.search(r'data-valued="1" data-book="50', hotels.get('/assets?id=%d' % R)[1]) is not None)
check('A client\'s item is not valued by the agency', 'Owned by the client' in hotels.get('/assets?id=%d' % L)[1])
check('nor one without a purchase cost', 'No purchase cost' in hotels.get('/assets?id=%d' % N)[1])

# ── retirement and disposal ────────────────────────────────────────────
dispose = {'do': 'dispose', 'disposal_method': 'sold', 'disposed_on': TODAY.isoformat(), 'proceeds': '3000', 'note': 'Sold to the yard'}
check('An item in use cannot be disposed of', on_item(hotels, G, dispose)[0] == 422)
on_item(hotels, G, {'do': 'act', 'action': 'retire', 'note': 'Too costly to run', 'cost': '', 'next_due': ''})
check('Retiring dates it', item(G)['retired_on'] == TODAY.isoformat())
check('A sale needs what it was sold for', on_item(hotels, G, {**dispose, 'proceeds': ''})[0] == 422)
check('Only a client\'s item is returned to the client', on_item(hotels, G, {**dispose, 'disposal_method': 'returned_to_client', 'proceeds': ''})[0] == 422)
check('Only a sale brings proceeds', on_item(hotels, G, {**dispose, 'disposal_method': 'scrapped'})[0] == 422)
on_item(hotels, G, dispose)
g = item(G)
check('The generator is disposed of: sold for 3,000 today', g['status'] == 'disposed' and g['disposal_method'] == 'sold' and g['disposal_proceeds'] == '3000.00' and g['disposed_on'] == TODAY.isoformat())
check('Disposed is for good: no putting back, no inspection', on_item(hotels, G, {'do': 'act', 'action': 'restore', 'note': '', 'cost': '', 'next_due': ''})[0] == 422
      and on_item(hotels, G, {'do': 'inspection', 'result': 'pass', 'inspected_on': TODAY.isoformat(), 'findings': '', 'next_due': ''})[0] == 422)
mg = min(60, months(item(G)['in_service_on'], TODAY))
book_at_sale = round(5000 - 4500 * mg / 60, 2)
status, body = hotels.get('/assets?id=%d' % G)
gain = re.search(r'id="disposal-gain">([^<]+)<', body)
check('Its book value at the sale was %.2f, so the sale shows a gain of %.2f' % (book_at_sale, 3000 - book_at_sale),
      gain and 'Gain on disposal' in body and abs(float(re.sub(r'[^\d.]', '', gain[1])) - (3000 - book_at_sale)) < 0.01, gain and gain[1])
check('and its lifecycle reads acquired, in service, retired, disposed of', all(s in body for s in ['data-stage="Acquired"', 'data-stage="In service"', 'data-stage="Retired"', 'data-stage="Disposed of"']))
status, body = hotels.get('/assets?id=%d' % D)
check('The detector\'s lifecycle has its inspections, the repair and the calibration', all(s in body for s in ['data-stage="Inspection passed"', 'data-stage="Inspection failed"', 'data-stage="Repair"', 'data-stage="Calibration"']))

# ── the valuation report ───────────────────────────────────────────────
check('Recruiters do not open the valuation', rec.get('/asset-valuation')[0] == 403)
status, body = pay.get('/asset-valuation')
check('Payroll opens it', status == 200 and 'data-item="P5-GD1" data-valued="1"' in body)
row = re.search(r'data-item="P5-GD1" data-valued="1" data-book="([\d.]+)"', body)
check('It shows the detector at the same book value as its page', row and abs(float(row[1]) - (1200 - acc)) < 0.01)
check('and the generator, disposed of, at nothing', re.search(r'data-item="P5-GN1" data-valued="1" data-book="0.00"', body) is not None)
past = (TODAY - datetime.timedelta(days=365)).isoformat()
m12 = min(60, months(fx['months_detector'], TODAY - datetime.timedelta(days=365)))
status, body = pay.get('/asset-valuation?as_of=' + past)
row = re.search(r'data-item="P5-GD1" data-valued="1" data-book="([\d.]+)"', body)
check('A year ago the detector was worth %.2f' % (1200 - round(1080 * m12 / 60, 2)), row and abs(float(row[1]) - (1200 - round(1080 * m12 / 60, 2))) < 0.01)
status, body = pay.get('/asset-valuation?class=safety')
check('Filtered by class, only safety equipment', 'P5-GD1' in body and 'P5-GN1' not in body)
status, text = pay.open(base + '/asset-valuation?csv=1')
rows = list(csv.reader(io.StringIO(text)))
check('The CSV has a header and one line per item', status == 200 and rows[0][:3] == ['tag', 'item', 'category'] and any(r[0] == 'P5-GD1' for r in rows))
check('and a name that looks like a formula is written as text', any(r[0] == 'P5-FX1' and r[1] == "'=SUM(1,1)" for r in rows))

# ── translations ───────────────────────────────────────────────────────
status, body = hotels.get('/asset-valuation?lang=fr')
check('The valuation in French', 'Valorisation des équipements' in body and 'Valeur comptable' in body)
status, body = hotels.get('/assets?id=%d&lang=es' % D)
check('An item in Spanish', 'Historial de servicio' in body and 'Ciclo de vida' in body)
hotels.get('/assets?lang=en')

json.dump(results, open('work/al-http-results.json', 'w'), indent=1)
