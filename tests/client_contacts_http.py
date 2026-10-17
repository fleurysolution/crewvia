"""P5-M71 through the screens: a client's contacts, the one who receives the invoices, and who is on each project.
  - the contact given with a new client is its main point of contact; more are added, edited, made main, removed;
  - the contacts ticked as receiving the invoices are where invoices go;
  - the plant chooses its client contacts and the one in charge, shown on the plant and on the client;
  - the site address is kept, and looking an address up is for the staff who set up projects.
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
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}, doseq=True).encode(), {'Accept': 'text/html'}))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:900] if detail else '')
    results.append(label)
    print('PASS ' + label, flush=True)


def sql(query):
    out = subprocess.run([php, '-r', "require 'work/test-app/app/bootstrap.php'; echo json_encode(rows($argv[1]));", query], check=True, capture_output=True, text=True, encoding='utf-8').stdout
    return json.loads(out)




def one(query):
    r = sql(query)
    return r[0] if r else None


admin = Client(); admin.login('wk-admin@test.invalid')
NEW = {'do': 'create', 'name': 'Contacts Client Inc', 'city': 'Gary', 'state': 'IN', 'contact_name': 'Morgan Main', 'contact_title': 'Hiring manager',
       'contact_email': 'morgan@test.invalid', 'contact_phone': '555-0110', 'payment_terms_days': '30'}
admin.post('/clients', {**NEW, 'name': 'No Reach Client', 'contact_email': '', 'contact_phone': ''}, '/clients')
check('A main contact with neither email nor phone is refused', not sql("SELECT id FROM clients WHERE name = 'No Reach Client'"))
admin.post('/clients', NEW, '/clients')
cid = int(one("SELECT id FROM clients WHERE name = 'Contacts Client Inc'")['id'])
main = sql('SELECT * FROM client_contacts WHERE client_id = %d' % cid)
check('The contact given with the client is its main point of contact', len(main) == 1 and int(main[0]['is_main']) == 1 and main[0]['title'] == 'Hiring manager', main)
status, body = admin.get('/clients?id=%d' % cid)
check('The client shows its contacts, the main one marked', 'id="contacts"' in body and 'data-main-contact' in body and 'Morgan Main' in body)
admin.post('/clients', {'do': 'contact_add', 'client_id': cid, 'cc_name': 'Sam Site', 'cc_title': 'Site manager', 'cc_phone': '555-0111'}, '/clients?id=%d' % cid)
admin.post('/clients', {'do': 'contact_add', 'client_id': cid, 'cc_name': 'Alex Payable', 'cc_title': 'Accounts payable', 'cc_invoices': '1'}, '/clients?id=%d' % cid)
check('A contact receiving the invoices needs an email', not sql("SELECT id FROM client_contacts WHERE name = 'Alex Payable'"))
admin.post('/clients', {'do': 'contact_add', 'client_id': cid, 'cc_name': 'Alex Payable', 'cc_title': 'Accounts payable', 'cc_email': 'ap@test.invalid', 'cc_invoices': '1'}, '/clients?id=%d' % cid)
c = sql('SELECT name, is_main, receives_invoices FROM client_contacts WHERE client_id = %d ORDER BY id' % cid)
check('More contacts are added to the client', [x['name'] for x in c] == ['Morgan Main', 'Sam Site', 'Alex Payable'] and int(c[2]['receives_invoices']) == 1, c)
check('The invoice email is the contact receiving the invoices', one('SELECT billing_email FROM clients WHERE id = %d' % cid)['billing_email'] == 'ap@test.invalid')
status, body = admin.get('/clients?id=%d' % cid)
check('The client form says where invoices go, chosen in the contacts', 'data-invoice-to' in body and 'Alex Payable &lt;ap@test.invalid&gt;' in body and 'name="billing_email"' not in body.split('id="new"')[0])
sam = int(one("SELECT id FROM client_contacts WHERE name = 'Sam Site'")['id'])
admin.post('/clients', {'do': 'contact_main', 'client_id': cid, 'contact_id': sam}, '/clients?id=%d' % cid)
row = one('SELECT contact_name, contact_phone FROM clients WHERE id = %d' % cid)
check('Another contact is made the main point of contact, and the client shows them', row['contact_name'] == 'Sam Site' and row['contact_phone'] == '555-0111'
      and int(one("SELECT COUNT(*) n FROM client_contacts WHERE client_id = %d AND is_main = 1" % cid)['n']) == 1, row)
admin.post('/clients', {'do': 'contact_update', 'client_id': cid, 'contact_id': sam, 'cc_name': 'Sam Site', 'cc_title': 'Plant manager', 'cc_phone': '555-0111', 'cc_main': '1'}, '/clients?id=%d' % cid)
check('A contact is changed', one('SELECT title FROM client_contacts WHERE id = %d' % sam)['title'] == 'Plant manager')
oc = int(one("SELECT id FROM clients WHERE id <> %d ORDER BY id LIMIT 1" % cid)['id'])
admin.post('/clients', {'do': 'contact_main', 'client_id': oc, 'contact_id': sam}, '/clients?id=%d' % oc)
check("Another client's contact cannot be changed from here", int(one('SELECT is_main FROM client_contacts WHERE id = %d' % sam)['is_main']) == 1)

# The project chooses its contacts, and the one in charge.
admin.post('/projects', {'client_id': cid, 'title': 'P5-M71 contacts plant', 'site_address': '1 N Broadway', 'site_city': 'Gary', 'site_state': 'IN'}, '/projects')
job = one("SELECT id, site_address FROM jobs WHERE title = 'P5-M71 contacts plant'")
check('The site address is kept on the project', job and job['site_address'] == '1 N Broadway', job)
status, body = admin.get('/job')
check('The plant lists the client\'s contacts to choose from', 'id="project-contacts"' in body and body.count('data-project-contact=') == 3 and 'data-geo-address' in body and 'site-address.js' in body)
morgan = int(one("SELECT id FROM client_contacts WHERE name = 'Morgan Main'")['id'])
ap = int(one("SELECT id FROM client_contacts WHERE name = 'Alex Payable'")['id'])
admin.post('/job', {'do': 'project_contacts', 'contacts[]': [sam, ap], 'in_charge': sam}, '/job')
jc = sql('SELECT contact_id, in_charge FROM job_contacts WHERE job_id = %d ORDER BY contact_id' % int(job['id']))
check('The project has its contacts, the site manager in charge', [(int(x['contact_id']), int(x['in_charge'])) for x in jc] == sorted([(sam, 1), (ap, 0)]), jc)
status, body = admin.get('/job')
check('The plant shows who is in charge at the client', re.search(r'data-in-charge>.*?Sam Site', body, re.S) is not None)
status, body = admin.get('/clients?id=%d' % cid)
check("The client's projects say who is in charge of each", 'in charge: Sam Site' in body)
admin.post('/job', {'do': 'project_contacts', 'contacts[]': [morgan], 'in_charge': ap}, '/job')
jc = sql('SELECT contact_id, in_charge FROM job_contacts WHERE job_id = %d ORDER BY contact_id' % int(job['id']))
check('The one in charge is on the project even if not ticked', sorted((int(x['contact_id']), int(x['in_charge'])) for x in jc) == sorted([(morgan, 0), (ap, 1)]), jc)
admin.post('/clients', {'do': 'contact_remove', 'client_id': cid, 'contact_id': ap}, '/clients?id=%d' % cid)
check('A removed contact leaves the projects, and stops receiving the invoices', not sql('SELECT * FROM job_contacts WHERE contact_id = %d' % ap)
      and not sql('SELECT id FROM client_contacts WHERE id = %d' % ap))

# The address lookup is for the staff who set up projects.
status, body = admin.get('/geocode?address=x')
check('A too short address is not looked up', status == 404 and json.loads(body)['found'] is False)
rec = Client(); rec.login('wk-rec@test.invalid')
status, body = rec.get('/geocode?address=1+N+Broadway+Gary+IN')
check('A recruiter does not look up addresses', status == 403, status)

# A discipline not on the list is added beside the scope line, and chosen at once.
status, body = admin.post('/job', {'do': 'add_discipline', 'discipline_label': 'Millwright'}, '/job')
check('A new discipline is added from the scope line and chosen', 'millwright' in admin.url and re.search(r'<option value="millwright"\s+selected>Millwright</option>', body)
      and one("SELECT label FROM disciplines WHERE slug = 'millwright'")['label'] == 'Millwright', admin.url)
admin.get('/job')
req = urllib.request.Request(base + '/job', urllib.parse.urlencode({'_csrf': admin.token, 'do': 'add_discipline', 'discipline_label': 'Shirt printer'}).encode(), {'Accept': 'application/json'})
status, body = admin.open(req)
got = json.loads(body)
check('Added without leaving the line: the answer gives the new discipline to choose', status == 200 and got['ok'] and got['slug'] == 'shirt_printer', got)
req = urllib.request.Request(base + '/job', urllib.parse.urlencode({'_csrf': admin.token, 'do': 'add_discipline', 'discipline_label': '  '}).encode(), {'Accept': 'application/json'})
status, body = admin.open(req)
check('A discipline needs a name', status == 422 and json.loads(body)['ok'] is False)
