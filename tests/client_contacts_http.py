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

# P5-M72: the scope line - what the person is paid, then what the client is billed; "Add a trade" folded once there is a line.
status, body = admin.get('/job')
check('With no line yet, the trade form is open, overtime at 40 hours and time and a half', re.search(r'<details class="card-foot" id="add-trade" open>', body)
      and 'name="overtime_after" min="0"\n                 step="1" value="40"' in body and 'value="1.5"' in body)
admin.post('/job', {'do': 'add_line', 'role_title': 'Pipefitter', 'discipline': 'mechanical', 'quantity': '2', 'pay_rate': '38', 'bill_rate': '50', 'bill_by': 'markup', 'markup': '40',
                    'overtime_after': '38', 'overtime_multiplier': '1.5'}, '/job')
line = one("SELECT pay_rate, bill_rate, overtime_after FROM job_order_lines WHERE job_id = %d AND role_title = 'Pipefitter'" % int(job['id']))
check('Billed by a markup, the markup sets the bill rate; overtime after 38 hours as the contract says', line and float(line['bill_rate']) == 53.2 and int(line['overtime_after']) == 38, line)
admin.post('/job', {'do': 'add_line', 'role_title': 'Welder', 'discipline': 'mechanical', 'quantity': '1', 'pay_rate': '40', 'bill_rate': '60', 'bill_by': 'rate', 'markup': '50'}, '/job')
check('Billed by a rate, the rate typed is kept', float(one("SELECT bill_rate FROM job_order_lines WHERE job_id = %d AND role_title = 'Welder'" % int(job['id']))['bill_rate']) == 60)
status, body = admin.get('/job')
check('Once the scope has lines, adding a trade is a button that opens the form', '<details class="card-foot" id="add-trade">' in body and 'data-add-trade' in body and 'scope-line.js' in body)

# P5-M73: the plant folded into sections; the strike switch at the top; bookmarks and recent screens in the menu.
status, body = admin.get('/job')
folds = re.findall(r'<details class="card fold[^"]*" id="([^"]+)"( open)?', body)
check('The plant is folded into sections, the scope open', dict(folds).get('scope') == ' open' and all(dict(folds).get(k) == '' for k in ('shifts', 'project-contacts', 'billing-terms', 'project-form', 'internal-notes')), folds)
check('The strike switch is at the top of the plant', body.find('id="strike-switch"') != -1 and body.find('id="strike-switch"') < body.find('id="scope"'))
admin.post('/job', {'do': 'strike', 'strike_live': '1'}, '/job')
check('The strike is declared live from the top', int(one('SELECT strike_live FROM jobs WHERE id = %d' % int(job['id']))['strike_live']) == 1)
admin.post('/job', {'title': 'P5-M71 contacts plant', 'status': 'planning', 'strike_live': '1', 'ends_unknown': '1'}, '/job')
check('Saving the project keeps the strike as it is', int(one('SELECT strike_live FROM jobs WHERE id = %d' % int(job['id']))['strike_live']) == 1)
admin.post('/job', {'do': 'strike', 'strike_live': '0'}, '/job')
check('The strike is stood down from the top', int(one('SELECT strike_live FROM jobs WHERE id = %d' % int(job['id']))['strike_live']) == 0)
status, body = admin.get('/clients?id=%d' % cid)
check('With contacts, adding one is a button that opens the form', '<details class="mt" id="add-contact">' in body and 'data-add-contact' in body)
uidr = one("SELECT id FROM users WHERE email = 'wk-admin@test.invalid'")['id']
check('A screen opened is listed under Recent in the menu', 'data-recent>Clients</a>' in body and one("SELECT COUNT(*) n FROM user_nav_marks WHERE user_id = %s AND kind = 'recent' AND path = '/clients'" % uidr)['n'] in (1, '1'))
status, body = admin.post('/nav-mark', {'path': '/clients'}, '/clients')
check('A screen is bookmarked from the menu, and listed there', 'data-bookmark>&#9733; Clients</a>' in body, admin.url)
admin.post('/nav-mark', {'path': '/clients'}, '/clients')
check('The bookmark is removed again', one("SELECT COUNT(*) n FROM user_nav_marks WHERE user_id = %s AND kind = 'bookmark'" % uidr)['n'] in (0, '0'))
for p in ('/projects', '/clients', '/job', '/hotels', '/billing', '/hours', '/approvals'):
    admin.get(p)
check('Recent keeps the last five screens', int(one("SELECT COUNT(*) n FROM user_nav_marks WHERE user_id = %s AND kind = 'recent'" % uidr)['n']) <= 5)
rec2 = Client(); rec2.login('wk-rec@test.invalid')
rec2.post('/nav-mark', {'path': '/clients'}, '/requisitions')
check('A screen outside the person\'s menu cannot be bookmarked', one("SELECT COUNT(*) n FROM user_nav_marks um JOIN users u ON u.id = um.user_id WHERE u.email = 'wk-rec@test.invalid' AND um.kind = 'bookmark'")['n'] in (0, '0'))
status, body = admin.get('/projects')
check('Projects shows the projects first; a new one is a button that opens the form', body.find('id="new-project"') > body.find('class="card tight"') > 0
      and re.search(r'<details class="card fold" id="new-project" data-fold-forget>', body) and 'data-new-project' in body)
status, body = admin.get('/projects?client_id=%d' % cid)
check('Coming from a client, the new project form is open', re.search(r'id="new-project" data-fold-forget open>', body))
status, body = admin.get('/clients')
check('Clients shows the clients first; adding one is a button', re.search(r'<details class="card fold" id="new" data-fold-forget>', body) and 'data-new-client' in body)

# P5-M74: requisitions one per line, the QR code on demand; a form opened by a button has a Cancel.
status, body = admin.get('/requisitions')
check('With no requisition yet, the form to create one is open, with Cancel', re.search(r'<details class="card fold" id="new-requisition" data-fold-forget open>', body)
      and 'data-fold-close' in body and 'Create the requisition' in body)
admin.post('/requisitions', {'do': 'create', 'order_line_id': one("SELECT id FROM job_order_lines WHERE job_id = %d AND role_title = 'Pipefitter'" % int(job['id']))['id'],
                             'title': 'P5-M74 Pipefitter', 'discipline': 'mechanical', 'openings': '2', 'description': 'Pipefitters for the plant.'}, '/requisitions')
status, body = admin.get('/requisitions')
vac = one("SELECT id FROM vacancies WHERE title = 'P5-M74 Pipefitter'")
check('A requisition is one line: its trade, its pay, how far it is filled, Copy link and the QR code on demand', vac is not None
      and re.search(r'<tr data-requisition="%s">' % vac['id'], body) and 'data-copy-link="' in body and '/apply?id=%s"' % vac['id'] in body
      and '<details class="req-more' in body and '<details class="card fold" id="new-requisition" data-fold-forget>' in body, vac)
for page in ('/job', '/projects', '/clients?id=%d' % cid):
    status, body = admin.get(page)
    check('Cancel closes the form opened by a button on ' + page.split('?')[0], 'data-fold-close' in body)

# P5-M76: the hotels of a project, each with its block.
admin.post('/hotels', {'do': 'add_hotel', 'name': 'P5-M76 Inn', 'city': 'Gary', 'state': 'IN', 'nightly_rate': '119', 'client_night_rate': '140', 'rooms_held': '20', 'block_starts': '2026-10-20', 'block_ends': '2026-12-20'}, '/hotels')
inn = one("SELECT id FROM hotels WHERE name = 'P5-M76 Inn'")
jh = one('SELECT * FROM job_hotels WHERE job_id = %d AND hotel_id = %s' % (int(job['id']), inn['id'])) if inn else None
check('A hotel added with a project open is that project\'s, with its block, dates, cost and price per night', jh and int(jh['rooms_held']) == 20 and float(jh['client_night_rate']) == 140 and jh['block_starts'] == '2026-10-20', jh)
status, body = admin.get('/hotels')
check('Hotels lists the project\'s hotels with rooms held, taken and left', re.search(r'<tr data-project-hotel="%s">' % inn['id'], body) and 'id="link-hotel"' in body and 'id="hotel-directory"' in body)
admin.post('/hotels', {'do': 'update_link', 'hotel_id': inn['id'], 'rooms_held': '25', 'nightly_rate': '115', 'client_night_rate': ''}, '/hotels')
jh = one('SELECT rooms_held, nightly_rate, client_night_rate FROM job_hotels WHERE job_id = %d AND hotel_id = %s' % (int(job['id']), inn['id']))
check('The project\'s block is changed', int(jh['rooms_held']) == 25 and float(jh['nightly_rate']) == 115 and jh['client_night_rate'] is None, jh)
admin.post('/hotels', {'do': 'unlink_hotel', 'hotel_id': inn['id']}, '/hotels')
check('A hotel nobody is booked in comes off the project', one('SELECT COUNT(*) n FROM job_hotels WHERE job_id = %d AND hotel_id = %s' % (int(job['id']), inn['id']))['n'] in (0, '0'))

# P5-M77: the site of each project is kept on its client, and offered for its next project.
site = one("SELECT * FROM client_sites WHERE client_id = %d AND address = '1 N Broadway'" % cid)
check('The project\'s site is kept on its client', site is not None and site['city'] == 'Gary', site)
status, body = admin.get('/projects?client_id=%d' % cid)
check('A new project for that client is offered its sites', 'id="p-saved-site"' in body and '1 N Broadway' in body and 'client-sites.js' in body)
status, body = admin.get('/clients?id=%d' % cid)
check('The client lists its sites, with a new project there', re.search(r'<tr data-client-site="%s">' % site['id'], body) and 'site=%s#new-project' % site['id'] in body)
admin.post('/clients', {'do': 'site_remove', 'client_id': cid, 'site_id': site['id']}, '/clients?id=%d' % cid)
check('A site is taken off the client', not sql('SELECT id FROM client_sites WHERE id = %s' % site['id']))

# P5-M78 fix: Advance moves the stage and never saves an empty project form over the project.
admin.post('/job', {'do': 'save_project', 'title': 'P5-M71 contacts plant', 'status': 'planning', 'description': 'Pipefitting support for the plant.', 'site_city': 'Gary', 'site_state': 'IN', 'ends_unknown': '1'}, '/job')
before = one('SELECT description, site_city, lifecycle_stage FROM jobs WHERE id = %d' % int(job['id']))
status, body = admin.post('/job', {'do': 'advance'}, '/job')
after = one('SELECT description, site_city, lifecycle_stage FROM jobs WHERE id = %d' % int(job['id']))
check('Advance keeps the project as written', before['description'] == 'Pipefitting support for the plant.' and after['description'] == before['description'] and after['site_city'] == 'Gary', (before, after))
check('...and moves it to Mobilising once its signals are met', after['lifecycle_stage'] == 'mobilising' and 'Moved to Mobilising' in body, (after, re.findall(r'class="flash[^"]*"[^>]*>([^<]*)', body)))
status, body = admin.post('/job', {'do': 'something-else', 'description': ''}, '/job')
check('An unknown action is refused, never saved as the project', 'Unknown action.' in body and one('SELECT description FROM jobs WHERE id = %d' % int(job['id']))['description'] == 'Pipefitting support for the plant.')
