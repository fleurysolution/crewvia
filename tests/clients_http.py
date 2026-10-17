"""P5-M69 through the screens: a client is added first, then chosen when a project is taken on.
  - the owner adds a client with its address, contact, billing email and terms;
  - the same name twice is refused;
  - Projects chooses the client from the list, and the project is that client's;
  - the client's page lists its projects; a recruiter does not open Clients;
  - choosing a project in the top bar keeps you on the screen you are on.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
php = os.environ.get('PHP_BIN', 'php')
results = []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token, self.url = None, None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                self.url = r.url
                return r.status, r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            self.url = e.url
            return e.code, e.read().decode('utf-8', 'replace')

    def get(self, path):
        status, text = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', text)
        if m:
            self.token = m[1]
        bad = [w for w in ('Fatal error', 'Warning:', 'Notice:', 'Deprecated:') if w in text]
        assert not bad, text[max(0, text.find(bad[0]) - 200):text.find(bad[0]) + 600] if bad else ''
        return status, text

    def login(self, email):
        self.get('/login')
        self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': 'TestPassword123!'}).encode()))

    def post(self, path, values, page):
        self.get(page)
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode(), {'Accept': 'text/html'}))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:900] if detail else '')
    results.append(label)
    print('PASS ' + label, flush=True)


def sql(query):
    out = subprocess.run([php, '-r', "require 'work/test-app/app/bootstrap.php'; echo json_encode(rows($argv[1]));", query], check=True, capture_output=True, text=True, encoding='utf-8').stdout
    return json.loads(out)


admin = Client(); admin.login('wk-admin@test.invalid')
status, body = admin.get('/clients')
check('Clients opens for the owner, with the form to add one', status == 200 and 'name="do" value="create"' in body)
NEW = {'do': 'create', 'name': 'Demo Client Corp', 'address': '1 Plant Road', 'city': 'Gary', 'state': 'in', 'zip': '46402', 'contact_name': 'Pat Contact',
       'contact_email': 'pat@test.invalid', 'contact_phone': '555-0100', 'billing_email': 'ap@test.invalid', 'payment_terms_days': '45'}
status, body = admin.post('/clients', NEW, '/clients')
c = sql("SELECT id, name, city, state, zip, billing_email, payment_terms_days FROM clients WHERE name = 'Demo Client Corp'")
check('The client is added with its address, billing email and terms; next, a project for it', len(c) == 1 and c[0]['state'] == 'IN' and c[0]['billing_email'] == 'ap@test.invalid'
      and int(c[0]['payment_terms_days']) == 45 and 'client_id=%s' % c[0]['id'] in admin.url, (c, admin.url))
cid = int(c[0]['id'])
status, body = admin.post('/clients', {**NEW, 'name': 'demo client corp'}, '/clients')
check('The same name twice is refused', 'already has this name' in body and len(sql("SELECT id FROM clients WHERE LOWER(name) = 'demo client corp'")) == 1)
status, body = admin.get('/projects?client_id=%d' % cid)
check('Projects chooses the client from the list, the new one chosen', re.search(r'<option value="%d" selected>Demo Client Corp</option>' % cid, body) and 'href="/clients#new"' in body)
admin.post('/projects', {'client_id': cid, 'title': 'P5-M69 demo plant', 'site_city': 'Gary', 'site_state': 'IN', 'site_lat': '41.6036', 'site_lng': '-87.3378', 'site_radius_m': '500'}, '/projects')
j = sql("SELECT id, client_id, site_lat, site_lng, site_radius_m FROM jobs WHERE title = 'P5-M69 demo plant'")
check('The project taken on is that client\'s, with its site position and radius given at once (P5-M70)', len(j) == 1 and int(j[0]['client_id']) == cid
      and float(j[0]['site_lat']) == 41.6036 and float(j[0]['site_lng']) == -87.3378 and int(j[0]['site_radius_m']) == 500, j)
status, body = admin.get('/job')
check('The new project opens on its plant, with what the client is billed (P5-M70)', status == 200 and 'id="billing-terms"' in body and 'name="ot_mode"' in body)
status, saved = admin.post('/job', {'do': 'billing_terms', 'ot_mode': 'multiplier', 'ot_multiplier': '1.5', 'per_diem_mode': 'cost', 'lodging_mode': 'cost_plus', 'lodging_markup_pct': '10',
                    'travel_mode': 'cost', 'transport_mode': 'per_day', 'transport_rate': '12'}, '/job')
bt = sql('SELECT ot_mode, per_diem_mode, lodging_mode, lodging_markup_pct, transport_rate FROM job_billing_terms WHERE job_id = %d' % int(j[0]['id']))
check('What the client is billed is saved from the plant', len(bt) == 1 and bt[0]['ot_mode'] == 'multiplier' and bt[0]['lodging_mode'] == 'cost_plus'
      and float(bt[0]['lodging_markup_pct']) == 10 and float(bt[0]['transport_rate']) == 12 and 'Saved: the next invoices bill these terms.' in saved, bt)
admin.post('/job', {'do': 'billing_terms', 'ot_mode': 'rate', 'ot_rate': ''}, '/job')
check('Overtime at its own rate with no rate is refused, the terms kept', sql('SELECT ot_mode FROM job_billing_terms WHERE job_id = %d' % int(j[0]['id']))[0]['ot_mode'] == 'multiplier')
status, body = admin.get('/clients?id=%d' % cid)
check('The client\'s page lists its projects', ('data-client-project="%s"' % j[0]['id']) in body and 'P5-M69 demo plant' in body)
admin.post('/clients', {**NEW, 'do': 'update', 'client_id': cid, 'payment_terms_days': '30'}, '/clients?id=%d' % cid)
check('The client is updated', int(sql('SELECT payment_terms_days FROM clients WHERE id = %d' % cid)[0]['payment_terms_days']) == 30)
status, body = admin.get('/requisitions')
m = re.search(r'<form method="post" action="/select-project" class="workbar-project">.*?name="then" value="([^"]+)"', body, re.S)
check('The working-project bar keeps you on the screen you are on', m and m[1] == '/requisitions', m and m[1])
rec = Client(); rec.login('wk-rec@test.invalid')
status, body = rec.get('/clients')
check('A recruiter does not open Clients', status == 403, status)
