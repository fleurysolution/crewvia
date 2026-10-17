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
check('The plant starts with every section folded, the scope too', dict(folds).get('scope') == '' and all(dict(folds).get(k) == '' for k in ('shifts', 'project-contacts', 'billing-terms', 'project-form', 'internal-notes')), folds)
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
tasks = sql('SELECT title, assignee_id, due_on FROM project_tasks WHERE job_id = %d ORDER BY id' % int(job['id']))
check('Reaching Mobilising sends the team its tasks: requisitions and recruiting, hotels, travel, payroll, the site',
      len(tasks) >= 6 and any('requisitions' in x['title'] for x in tasks) and any('hotel' in x['title'] for x in tasks) and all(x['due_on'] for x in tasks), tasks)
check('...each to someone on that desk', sum(1 for x in tasks if x['assignee_id']) >= 5, tasks)

# P5-M82: Candidates by requisition - its applicants first come first served, or the database matching it; a message to the people ticked.
def php_q(query):
    subprocess.run([php, '-r', "require 'work/test-app/app/bootstrap.php'; q($argv[1]);", query], check=True)
vac_id = int(vac['id'])
php_q("INSERT INTO candidates (full_name, email, phone, discipline, stage) VALUES ('Early Applicant', 'early@test.invalid', '555-070-7001', 'mechanical', 'new'), ('Late Applicant', 'late@test.invalid', '555-070-7002', 'mechanical', 'new'), ('Pool Match', 'pool@test.invalid', '555-070-7003', 'mechanical', 'new')")
php_q("INSERT INTO applications (candidate_id, vacancy_id, stage, source, created_at) SELECT id, %d, 'new', 'LinkedIn', '2026-10-01 08:00:00' FROM candidates WHERE email = 'late@test.invalid'" % vac_id)
php_q("INSERT INTO applications (candidate_id, vacancy_id, stage, source, created_at) SELECT id, %d, 'new', 'QR code', '2026-09-30 08:00:00' FROM candidates WHERE email = 'early@test.invalid'" % vac_id)
status, body = admin.get('/candidates?vacancy=%d' % vac_id)
check('Candidates shows the applicants of a requisition, first applied first, with where they came from',
      body.find('Early Applicant') != -1 and body.find('Early Applicant') < body.find('Late Applicant') and 'Pool Match' not in body and 'QR code' in body and 'data-pick-all' in body)
status, body = admin.get('/candidates?vacancy=%d&order=newest' % vac_id)
check('...or the newest first', body.find('Late Applicant') < body.find('Early Applicant'))
status, body = admin.get('/candidates?vacancy=%d&pool=match&q=Pool+Match' % vac_id)
status2, body2 = admin.get('/candidates?vacancy=%d&pool=match&q=Applicant' % vac_id)
check('The database matching it: same trade, not already applied', 'Pool Match' in body and 'Early Applicant' not in body2 and 'Late Applicant' not in body2)
ids = ','.join(str(x['id']) for x in sql("SELECT id FROM candidates WHERE email IN ('early@test.invalid','late@test.invalid')"))
status, body = admin.get('/campaigns?ids=%s&vacancy_id=%d&preview=1&channel=email' % (ids, vac_id))
check('The people ticked get one message: the campaign is for exactly them, applicants included', 'data-chosen-people' in body and 'data-count="2"' in body and ('name="ids" value="%s"' % ids) in body)

# P5-M83: a recruiter sends an applicant for review; they cannot move them past it alone. The owner decides.
pool = one("SELECT id FROM candidates WHERE email = 'pool@test.invalid'")
rec.post('/candidates', {'do': 'stage', 'candidate_id': pool['id'], 'stage': 'screening', 'back': ''}, '/candidates')
check('A recruiter cannot move someone to Screening without a review', one('SELECT stage FROM candidates WHERE id = %s' % pool['id'])['stage'] != 'screening' and '/candidates/%s' % pool['id'] in rec.url, rec.url)
owner_id = one("SELECT id FROM users WHERE email = 'wk-admin@test.invalid'")['id']
status, body = rec.get('/candidates/%s' % pool['id'])
check('The person\'s page offers Send for review, the contact card compact', 'id="send-review"' in body and 'id="text-panel-fold"' in body and 'id="contact-preferences-fold"' in body)
rec.post('/candidates/%s' % pool['id'], {'do': 'send_review', 'application_id': '0', 'vacancy_id': vac_id, 'reviewer_id': owner_id, 'rating': '4', 'recommendation': 'Eight years on refinery turnarounds.'}, '/candidates/%s' % pool['id'])
rv = one("SELECT r.status, r.reviewer_id FROM application_reviews r JOIN applications a ON a.id = r.application_id WHERE a.candidate_id = %s" % pool['id'])
check('Sent for review: it goes to the managers, the person is In review', rv and rv['status'] == 'pending' and one('SELECT role FROM users WHERE id = %s' % rv['reviewer_id'])['role'] == 'admin'
      and one('SELECT stage FROM candidates WHERE id = %s' % pool['id'])['stage'] == 'screening', rv)
status, body = admin.get('/reviews?all=1')
check('...and it is on Waiting on your decision', 'Pool Match' in body)
status, body = rec.get('/candidates')
check('A recruiter opens Candidates on the people still to contact', re.search(r'class="on" data-tab="new"', body) is not None)

# P5-M84: the owner approves - the applicant goes to the project's HR coordinator with a task and the portal invitation; HR sets the group.
rec_id = one("SELECT id FROM users WHERE email = 'wk-rec@test.invalid'")['id']
php_q("UPDATE jobs SET coordinator_id = %s WHERE id = (SELECT job_id FROM vacancies WHERE id = %d)" % (rec_id, vac_id))
rid = one("SELECT r.id FROM application_reviews r JOIN applications a ON a.id = r.application_id WHERE a.candidate_id = %s AND r.status = 'pending'" % pool['id'])['id']
admin.post('/reviews', {'do': 'decide', 'review_id': rid, 'decision': 'validated', 'note': ''}, '/reviews?id=%s' % rid)
task = one("SELECT assignee_id, title FROM project_tasks WHERE title LIKE 'Onboard Pool Match%'")
check('Approved: the applicant goes to the project\'s HR coordinator with an onboarding task', task and int(task['assignee_id']) == int(rec_id), task)
note = one("SELECT message FROM notifications WHERE user_id = %s AND message LIKE 'Pool Match approved%%' ORDER BY id DESC LIMIT 1" % rec_id)
check('The recruiter is told it is approved and with HR, nothing more to do', note is not None and 'HR' in note['message'], note)
check('The person leaves the recruiter\'s review list', one('SELECT stage FROM candidates WHERE id = %s' % pool['id'])['stage'] == 'offered')
status, body = rec.get('/candidates/%s' % pool['id'])
check('The person\'s page says approved and handed to HR', 'handed to HR for onboarding' in body)
php_q("INSERT INTO placements (candidate_id, job_id, status, start_date) VALUES (%s, (SELECT job_id FROM vacancies WHERE id = %d), 'confirmed', CURDATE())" % (pool['id'], vac_id))
plc = one('SELECT id FROM placements WHERE candidate_id = %s ORDER BY id DESC LIMIT 1' % pool['id'])['id']
sup = one("SELECT id FROM users WHERE role = 'supervisor' AND is_active = 1 ORDER BY id LIMIT 1")
gst, gbody = admin.post('/placements/%s' % plc, {'do': 'group', 'crew_group': 'Group 1', 'supervisor_id': sup['id'] if sup else '', 'leads_group': '1'}, '/placements/%s' % plc)
g = one('SELECT crew_group, leads_group, supervisor_id FROM assignment_details WHERE placement_id = %s' % plc)
check('HR puts the person in a group, under its supervisor, leading it', g and g['crew_group'] == 'Group 1' and int(g['leads_group']) == 1, (g, gst, re.findall(r'class="flash[^"]*"[^>]*>([^<]*)', gbody), admin.url, gbody[:300] if gst != 200 else ''))
status, body = admin.get('/manning')
check('Manning shows the group, and the one who leads it needs a vehicle', 'Group 1' in body and ('data-needs-vehicle="%s"' % plc) in body)

# P5-M84: the HR role does onboarding; Project coordinator is the hotels desk's name.
php_q("INSERT INTO users (name, email, password_hash, role, must_change_pw) SELECT 'HR Tester', 'wk-hr@test.invalid', password_hash, 'hr', 0 FROM users WHERE email = 'wk-rec@test.invalid'")
hr = Client(); hr.login('wk-hr@test.invalid')
check('HR signs in and lands on Onboarding (no loop on /home)', hr.url.endswith('/onboarding'), hr.url)
status, body = hr.get('/home')
check('Today sends HR to Onboarding', status == 200 and hr.url.endswith('/onboarding'), (status, hr.url))
status, body = hr.get('/onboarding')
check('HR opens Onboarding', status == 200 and 'Fatal' not in body, status)
nav = re.sub(r'<div class="nav-marks" data-nav-marks>.*?</div>', '', re.search(r'<nav[^>]*>(.*?)</nav>', body, re.S)[1], flags=re.S)
daily = re.findall(r'<a href="(/[^"]*)"', re.search(r'<div class="nav-daily">(.*?)</div>', nav, re.S)[1])
check('HR\'s menu starts with its own day: hiring, onboarding, then tax forms', daily[:4] == ['/activity', '/hiring', '/onboarding', '/tax-forms'], daily)
status, body = admin.get('/people')
check('Team offers HR (onboarding) and Project coordinator', 'HR (onboarding)' in body and 'Project coordinator' in body)

# P5-M85: HR first, then travel to the project coordinator; an expedited approval starts both. Flight arrivals.
hrtask = one("SELECT id FROM project_tasks WHERE title LIKE 'Onboard Pool Match%'")
check('Not expedited: no travel task while HR works', one("SELECT COUNT(*) n FROM project_tasks WHERE title LIKE 'Book the flight and hotel for Pool Match%'")['n'] in (0, '0'))
rec.post('/my-tasks', {'task_id': hrtask['id'], 'status': 'done', 'note': 'Background check and drug test clear.'}, '/my-tasks?task=%s' % hrtask['id'])
tt = one("SELECT assignee_id FROM project_tasks WHERE title LIKE 'Book the flight and hotel for Pool Match%'")
check('HR done: the project coordinator gets the flight and hotel to book', tt is not None and (tt['assignee_id'] is None or one('SELECT role FROM users WHERE id = %s' % tt['assignee_id'])['role'] in ('hotels', 'admin')), tt)
php_q("INSERT INTO candidates (full_name, email, phone, discipline, stage) VALUES ('Fast Track', 'fast@test.invalid', '555-070-7004', 'mechanical', 'new')")
php_q("INSERT INTO applications (candidate_id, vacancy_id, stage, source) SELECT id, %d, 'screening', 'Referral' FROM candidates WHERE email = 'fast@test.invalid'" % vac_id)
fast_app = one("SELECT a.id FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE c.email = 'fast@test.invalid'")['id']
php_q("INSERT INTO application_reviews (application_id, requested_by, reviewer_id, rating, recommendation) VALUES (%s, %s, %s, 5, 'Needed on Monday.')" % (fast_app, rec_id, owner_id))
frid = one('SELECT id FROM application_reviews WHERE application_id = %s' % fast_app)['id']
admin.post('/reviews', {'do': 'decide', 'review_id': frid, 'decision': 'validated', 'note': '', 'expedite': '1'}, '/reviews?id=%s' % frid)
check('Expedited: HR and travel start together', one("SELECT COUNT(*) n FROM project_tasks WHERE title LIKE 'Onboard Fast Track%'")['n'] in (1, '1')
      and one("SELECT COUNT(*) n FROM project_tasks WHERE title LIKE 'Book the flight and hotel for Fast Track%'")['n'] in (1, '1'))
php_q("INSERT INTO travel (placement_id, direction, mode, carrier, reference, depart_from, arrive_at, depart_time, arrive_time, pickup_needed, status) VALUES (%s, 'inbound', 'flight', 'United', 'UA123', 'IAH', 'ORD', DATE_ADD(NOW(), INTERVAL 2 DAY), DATE_ADD(NOW(), INTERVAL 2 DAY), 1, 'booked')" % plc)
status, body = admin.get('/arrivals?all=1')
check('Flight arrivals lists who lands when and where, with the pickup to arrange', status == 200 and 'Pool Match' in body and 'UA123' in body and 'ORD' in body and 'to arrange' in body)

# P5-M85: the requisition names its hiring managers; a review goes to them only.
admin.post('/requisitions', {'do': 'managers', 'vacancy_id': vac_id, 'managers[]': [owner_id]}, '/requisitions')
check('A requisition names its hiring managers', [int(x['user_id']) for x in sql('SELECT user_id FROM vacancy_managers WHERE vacancy_id = %d' % vac_id)] == [int(owner_id)])
php_q("INSERT INTO candidates (full_name, email, phone, discipline, stage) VALUES ('Managed Applicant', 'managed@test.invalid', '555-070-7005', 'mechanical', 'contacted')")
php_q("INSERT INTO applications (candidate_id, vacancy_id, stage, source) SELECT id, %d, 'new', 'LinkedIn' FROM candidates WHERE email = 'managed@test.invalid'" % vac_id)
ma = one("SELECT c.id AS cid, a.id AS aid FROM candidates c JOIN applications a ON a.candidate_id = c.id WHERE c.email = 'managed@test.invalid'")
last = int(one('SELECT COALESCE(MAX(id), 0) n FROM notifications')['n'])
rec.post('/candidates/%s' % ma['cid'], {'do': 'send_review', 'application_id': ma['aid'], 'rating': '4', 'recommendation': 'Good fit.'}, '/candidates/%s' % ma['cid'])
mr = one('SELECT reviewer_id FROM application_reviews WHERE application_id = %s' % ma['aid'])
told = [int(x['user_id']) for x in sql('SELECT user_id FROM notifications WHERE id > %d' % last)]
check("The review goes to the requisition's hiring manager, and only they are told", mr and int(mr['reviewer_id']) == int(owner_id) and told and set(told) == {int(owner_id)}, (mr, told))
status, body = admin.get('/reviews')
check('Their Waiting on your decision has it', 'Managed Applicant' in body)

# P5-M84 fix: running the upgrade again keeps an HR person's role.
subprocess.run([php, 'work/test-app/install/upgrade.php'], check=True, capture_output=True)
check('Upgrading again keeps the HR role', one("SELECT role FROM users WHERE email = 'wk-hr@test.invalid'")['role'] == 'hr')

# P5-M86: the recruiter's checklist - a template linked to the requisition, answered before the review.
rec.post('/checklists', {'do': 'save', 'name': 'Forklift driver', 'questions': 'Has a current forklift certification\nCan lift 50 lb'}, '/checklists')
tpl = one("SELECT id FROM checklist_templates WHERE name = 'Forklift driver'")
check('A checklist template is written once, its questions one per line', tpl and int(one('SELECT COUNT(*) n FROM checklist_items WHERE template_id = %s' % tpl['id'])['n']) == 2)
admin.post('/requisitions', {'do': 'managers', 'vacancy_id': vac_id, 'managers[]': [owner_id], 'checklists[]': [tpl['id']]}, '/requisitions')
check('The requisition links the template', [int(x['template_id']) for x in sql('SELECT template_id FROM vacancy_checklists WHERE vacancy_id = %d' % vac_id)] == [int(tpl['id'])])
php_q("INSERT INTO candidates (full_name, email, phone, discipline, stage) VALUES ('Checked Applicant', 'checked@test.invalid', '555-070-7006', 'mechanical', 'contacted')")
php_q("INSERT INTO applications (candidate_id, vacancy_id, stage, source) SELECT id, %d, 'new', 'QR code' FROM candidates WHERE email = 'checked@test.invalid'" % vac_id)
ck = one("SELECT c.id AS cid, a.id AS aid FROM candidates c JOIN applications a ON a.candidate_id = c.id WHERE c.email = 'checked@test.invalid'")
status, body = rec.get('/candidates/%s' % ck['cid'])
check('The person\'s page: the next step on top, then applied for and assignment, contact, the checklist',
      'data-applied-for="%s"' % ck['aid'] in body and 'data-assignment-none' in body and body.index('id="send-review"') < body.index('id="person-summary"') < body.index('id="contact"') < body.index('id="checklist"'))
rec.post('/candidates/%s' % ck['cid'], {'do': 'send_review', 'application_id': ck['aid'], 'rating': '4', 'recommendation': 'Good.'}, '/candidates/%s' % ck['cid'])
check('No review before the checklist is done', not sql('SELECT id FROM application_reviews WHERE application_id = %s' % ck['aid']))
items = [x['id'] for x in sql('SELECT id FROM checklist_items WHERE template_id = %s ORDER BY position' % tpl['id'])]
check('An unanswered checklist is open', ('id="checklist-%s" data-fold-forget open' % ck['aid']) in body)
rec.post('/candidates/%s' % ck['cid'], {'do': 'checklist', 'application_id': ck['aid'], 'answer[%s]' % items[0]: 'met', 'answer[%s]' % items[1]: 'not_met'}, '/candidates/%s' % ck['cid'])
status, body = rec.get('/candidates/%s' % ck['cid'])
check('Once saved, the checklist folds away', ('id="checklist-%s" data-fold-forget>' % ck['aid']) in body)
rec.post('/candidates/%s' % ck['cid'], {'do': 'send_review', 'application_id': ck['aid'], 'rating': '4', 'recommendation': 'Good.'}, '/candidates/%s' % ck['cid'])
check('A question not met keeps them from the review', not sql('SELECT id FROM application_reviews WHERE application_id = %s' % ck['aid']))
rec.post('/candidates/%s' % ck['cid'], {'do': 'checklist', 'application_id': ck['aid'], 'answer[%s]' % items[0]: 'met', 'answer[%s]' % items[1]: 'met', 'note[%s]' % items[1]: 'Lifts 60 lb'}, '/candidates/%s' % ck['cid'])
rec.post('/candidates/%s' % ck['cid'], {'do': 'send_review', 'application_id': ck['aid'], 'rating': '4', 'recommendation': 'Good.'}, '/candidates/%s' % ck['cid'])
check('All met: they go for review', bool(sql('SELECT id FROM application_reviews WHERE application_id = %s' % ck['aid'])) and one('SELECT note FROM checklist_answers WHERE item_id = %s AND application_id = %s' % (items[1], ck['aid']))['note'] == 'Lifts 60 lb')

# P5-M87: the recruiter does not place; the owner sending goes to the owner's review; approval assigns to the job unless full.
status, body = rec.post('/candidates/%s' % ck['cid'], {'do': 'place', 'start_date': ''}, '/candidates/%s' % ck['cid'])
check('A recruiter cannot place someone on a job', not sql('SELECT id FROM placements WHERE candidate_id = %s' % ck['cid']))
php_q("INSERT INTO candidates (full_name, email, phone, discipline, stage) VALUES ('Owner Pick', 'ownerpick@test.invalid', '555-070-7007', 'mechanical', 'contacted')")
php_q("INSERT INTO applications (candidate_id, vacancy_id, stage, source) SELECT id, %d, 'new', 'Referral' FROM candidates WHERE email = 'ownerpick@test.invalid'" % vac_id)
op = one("SELECT c.id AS cid, a.id AS aid FROM candidates c JOIN applications a ON a.candidate_id = c.id WHERE c.email = 'ownerpick@test.invalid'")
for it in items:
    php_q("INSERT INTO checklist_answers (application_id, item_id, answer) VALUES (%s, %s, 'met')" % (op['aid'], it))
admin.post('/candidates/%s' % op['cid'], {'do': 'send_review', 'application_id': op['aid'], 'rating': '5', 'recommendation': 'My pick.'}, '/candidates/%s' % op['cid'])
orv = one('SELECT id, reviewer_id FROM application_reviews WHERE application_id = %s' % op['aid'])
check('The owner sending, as the hiring manager, goes to the owner\'s own review', orv and int(orv['reviewer_id']) == int(owner_id), orv)
status, body = admin.get('/reviews?id=%s' % orv['id'])
check('The decision offers to assign to the job, with how full it is', 'id="assign"' in body and 'placed so far' in body)
admin.post('/reviews', {'do': 'decide', 'review_id': orv['id'], 'decision': 'validated', 'note': '', 'assign': '1'}, '/reviews?id=%s' % orv['id'])
check('Approved and assigned: an offered placement on the requisition', one("SELECT status FROM placements WHERE candidate_id = %s" % op['cid'])['status'] == 'offered')
php_q("UPDATE vacancies SET openings = 1 WHERE id = %d" % vac_id)
ckr = one("SELECT id FROM application_reviews WHERE application_id = %s AND status = 'pending'" % ck['aid'])
status, body = admin.get('/reviews?id=%s' % ckr['id'])
check('A full requisition offers no more assigning', 'the requisition is full' in body and re.search(r'name="assign" value="1" disabled', body) is not None)

# P5-M88: hiring managers are marked on Team; a requisition offers only them.
admin.post('/people', {'do': 'hiring_manager', 'user_id': owner_id, 'on': '1'}, '/people')
admin.post('/people', {'do': 'hiring_manager', 'user_id': sup['id'] if sup else owner_id, 'on': '1'}, '/people')
status, body = admin.get('/people')
check('Team marks who is a hiring manager, whatever their role', 'data-hiring-manager="%s"' % owner_id in body and 'Hiring manager ✓' in body)
status, body = admin.get('/requisitions')
offered = set(re.findall(r'name="managers\[\]" value="(\d+)"', body))
check('A requisition offers only the hiring managers, side by side', offered and offered <= {str(owner_id), str(sup['id'] if sup else owner_id)} and 'class="pick-grid"' in body, offered)

# P5-M90: an edit of a scope line is kept - what changed, why, who and when.
pline = one("SELECT * FROM job_order_lines WHERE job_id = %d AND role_title = 'Pipefitter'" % int(job['id']))
edit = {'do': 'save_line', 'line_id': pline['id'], 'role_title': 'Pipefitter', 'discipline': pline['discipline'], 'quantity': str(int(pline['quantity']) + 20),
        'pay_rate': pline['pay_rate'] or '', 'bill_rate': pline['bill_rate'] or '', 'overtime_after': pline['overtime_after'] or '', 'overtime_multiplier': pline['overtime_multiplier'] or ''}
admin.post('/job', edit, '/job?line=%s' % pline['id'])
check('A changed line needs a reason', int(one('SELECT quantity FROM job_order_lines WHERE id = %s' % pline['id'])['quantity']) == int(pline['quantity']))
admin.post('/job', {**edit, 'change_reason': 'Client asked for 20 more, PO 4471'}, '/job?line=%s' % pline['id'])
h = one('SELECT quantity_before, quantity_after, note, changes, created_by FROM scope_change_orders WHERE line_id = %s' % pline['id'])
check('The edit is kept: from what to what, why and who', h and int(h['quantity_after']) == int(pline['quantity']) + 20 and 'quantity: %s -> %s' % (pline['quantity'], int(pline['quantity']) + 20) in h['changes']
      and h['note'] == 'Client asked for 20 more, PO 4471' and int(h['created_by']) == int(owner_id), h)
status, body = admin.get('/job')
check('The scope lists its changes', 'id="change-orders"' in body and 'PO 4471' in body)

# P5-M91: from a requisition, its applicants a click away.
status, body = admin.get('/requisitions')
check('A requisition\'s applicant count opens its applicants on Candidates', ('href="/candidates?vacancy=%d"' % vac_id) in body)

# P5-M92: the onboarding task opens the person's forms; a supervisor named HR of the project signs its people in.
hand = sql('SELECT h.candidate_id, t.link FROM hiring_handoffs h JOIN project_tasks t ON t.id = h.hr_task_id')
check('The onboarding task opens the person\'s forms', hand and all(x['link'] == '/tax-forms?person=%s' % x['candidate_id'] for x in hand), hand)
sup_user = one("SELECT id, email FROM users WHERE role = 'supervisor' AND is_active = 1 ORDER BY id LIMIT 1")
php_q('UPDATE jobs SET coordinator_id = %d WHERE id = %d' % (int(sup_user['id']), int(job['id'])))
site = Client(); site.login(sup_user['email'])
status, body = site.get('/tax-forms?person=%s' % ck['cid'])
check('A supervisor named HR of the project opens the forms of its people', status == 200 and 'Signature, in person' in body and '/change-requests' not in body, status)
other = one("SELECT c.id FROM candidates c WHERE NOT EXISTS (SELECT 1 FROM applications a JOIN vacancies v ON v.id = a.vacancy_id WHERE a.candidate_id = c.id AND v.job_id = %d) AND NOT EXISTS (SELECT 1 FROM placements p WHERE p.candidate_id = c.id AND p.job_id = %d) LIMIT 1" % (int(job['id']), int(job['id'])))
status, body = site.get('/tax-forms?person=%s' % other['id'])
check('but not the people of other projects', status == 403, status)
status, body = site.get('/change-requests')
check('nor the office\'s screens', status in (302, 403) or 'Change requests' not in body, status)
php_q('UPDATE jobs SET coordinator_id = NULL WHERE id = %d' % int(job['id']))
rv = one('SELECT id FROM application_reviews WHERE application_id = %s ORDER BY id LIMIT 1' % ck['aid'])
status, body = admin.get('/reviews?id=%s' % rv['id'])
check('The person who decides sees the recruiter\'s checklist answers', 'id="dossier-checklist"' in body and 'Lifts 60 lb' in body and '2 of 2 met' in body)
check('and how to reach them: phone and email', 'id="dossier-contact"' in body and 'checked@test.invalid' in body and '555-070-7006' in body)

# P5-M94: in Hiring, an approved person shows every step to the first day, who has it, and where it stands.
status, body = admin.get('/hiring?v=%d&app=%s' % (vac_id, op['aid']))
check('Hiring shows an approved person\'s steps to the first day', status == 200 and 'id="onboarding-progress"' in body
      and all('data-step="%s"' % k in body for k in ('approved', 'assigned', 'hr', 'portal', 'drug', 'background', 'w4', 'i9', 'travel', 'arrived')))
check('and none of the recruiting: no review, interviews, screener questions or stage moves', 'id="pass-to-manager"' not in body and 'id="schedule-interview"' not in body
      and 'id="manager-review"' not in body and 'id="interviews"' not in body and 'id="screener"' not in body and 'id="move-form"' not in body)
status, body = admin.get('/hiring?v=%d&app=%s&go=checks' % (vac_id, op['aid']))
check('A step opens its screen on the requisition\'s project', status == 200 and admin.url.endswith('/checks'), admin.url)
