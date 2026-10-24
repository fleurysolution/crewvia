"""P5-M103: Jerry's loop, through the screens.
  - the owner fills in a requisition and sends it to recruiting: it is REQUESTED, nowhere public, its link closed;
  - every recruiter gets the notice and an email with the link; the emails leave, and the requisition's row says so;
  - the recruiter opens the link (its project opens), publishes it: it is public, and the owner is emailed;
  - "Create and publish" still publishes at once;
  - an applicant through the public link lands on that requisition;
  - sending the applicant for review emails the manager; the decision emails the recruiter;
  - Email delivery lists those emails with their status.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
php = os.environ.get('PHP_BIN', 'php')
results = []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                return r.status, r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
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


def php_run(code, *args):
    return subprocess.run([php, '-r', "require 'work/test-app/app/bootstrap.php'; " + code, *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout


def one(query):
    rows = json.loads(php_run('echo json_encode(rows($argv[1]));', query))
    return rows[0] if rows else None


capture = os.path.abspath('work/m103-mail.jsonl')
if os.path.exists(capture):
    os.remove(capture)
php_run("q(\"INSERT INTO platform_settings (setting_key, setting_value) VALUES ('mail_capture_path', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)\", [$argv[1]]);", capture)
php_run("q(\"INSERT INTO clients (name) VALUES ('Loop test client')\"); q(\"INSERT INTO jobs (client_id, title, status, site_city, site_state, travel_provided, lodging_provided) VALUES (?, 'Loop test plant', 'active', 'Baytown', 'TX', 1, 1)\", [(int) db()->lastInsertId()]);")
job = one("SELECT id FROM jobs WHERE title = 'Loop test plant'")['id']

jerry = Client(); jerry.login('wk-admin@test.invalid')
jerry.post('/select-project', {'job_id': job, 'then': '/requisitions'}, '/requisitions')
status, body = jerry.post('/requisitions', {'do': 'create', 'send': 'request', 'title': 'Loop Test 1', 'discipline': 'mechanical', 'openings': '2',
                                            'description': 'Test requisition for the loop.'}, '/requisitions')
vac = one("SELECT id, posting_status, requested_by, published_by FROM vacancies WHERE title = 'Loop Test 1'")
check('Send to recruiting creates the requisition as requested, by the owner', vac and vac['posting_status'] == 'requested' and vac['requested_by'] and not vac['published_by'], vac)
vid = int(vac['id'])
public = Client()
status, careers = public.get('/careers')
status_apply, closed = public.get('/apply?id=%d' % vid)
feed = json.loads(public.get('/careers-feed')[1])
check('Requested, it is nowhere public: not on careers, not in the feed, its link takes no application',
      'Loop Test 1' not in careers and vid not in [j['id'] for j in feed['jobs']] and status_apply == 404 and 'id="apply-form"' not in closed)

mails = int(one("SELECT COUNT(*) n FROM mail_outbox WHERE purpose = 'requisition:%d'" % vid)['n'])
rec_mail = one("SELECT m.id FROM mail_outbox m JOIN users u ON u.email = m.recipient WHERE m.purpose = 'requisition:%d' AND u.email = 'wk-rec@test.invalid'" % vid)
check('Every recruiter is emailed (the owner who sent it is not)', mails >= 1 and rec_mail is not None
      and not one("SELECT id FROM mail_outbox WHERE purpose = 'requisition:%d' AND recipient = 'wk-admin@test.invalid'" % vid), mails)
status, body = jerry.get('/requisitions?req=%d' % vid)
check("The requester's own page does not open the edit dialog", 'data-edit-req="%d" data-open-now' % vid not in body)
check('Before delivery, the row says the emails are waiting', 'data-requested' in body and re.search(r'data-mailed="0/%d"' % mails, body) and 'Requested by' in body)
status, body = jerry.get('/email-delivery')
check('Email delivery says emails are waiting, with Send now', re.search(r'id="mail-waiting" data-waiting="[1-9]', body) and 'data-send-now' in body)
php_run("q(\"UPDATE mail_outbox SET created_at = NOW() - INTERVAL 20 MINUTE WHERE purpose = ?\", [$argv[1]]);", 'requisition:%d' % vid)
status, body = jerry.get('/email-delivery')
check('An email waiting 20 minutes is called out: the delivery job may be stopped', 'the delivery job may be stopped' in body and 'class="flash err mt-s" id="mail-waiting"' in body)
for _ in range(200):
    jerry.post('/email-delivery', {'do': 'send_now'}, '/email-delivery')
    status, body = jerry.get('/email-delivery')
    if 'data-waiting="0"' in body:
        break
check('Send now empties the queue at once', 'data-waiting="0"' in body and 'Nothing waiting' in body)
sent = [json.loads(l) for l in open(capture, encoding='utf-8')] if os.path.exists(capture) else []
to_rec = [m for m in sent if 'wk-rec@test.invalid' in json.dumps(m) and 'Loop Test 1' in json.dumps(m)]
check('The email leaves, to the recruiter, with the requisition and its link (opening its edit dialog)', to_rec and '/requisitions?req=%d&open=edit' % vid in json.dumps(to_rec[0]), sent[:2])
status, body = jerry.get('/requisitions')
check('After delivery, the owner sees on the row that the emails were sent, and when', re.search(r'data-mailed="%d/%d"' % (mails, mails), body) and 'sent ' in body)

rec = Client(); rec.login('wk-rec@test.invalid')
status, body = rec.get('/requisitions?req=%d&open=edit' % vid)
check('The recruiter\'s link opens the requisition\'s project, the row stands out, with Publish', status == 200 and 'id="req-%d" class="row-focus"' % vid in body and 'data-publish-req="%d"' % vid in body)
check('The requisition opens with its Edit dialog shown for the recruiter', 'data-edit-req="%d" data-open-now' % vid in body and 'data-edit-open="%d"' % vid in body)
rec.post('/requisitions', {'do': 'edit', 'vacancy_id': vid, 'title': 'Loop Test 1', 'discipline': 'mechanical', 'openings': '3', 'starts_on': '2026-11-16',
                           'shift': 'Days, 12 hours', 'years_experience': '4', 'degree': '', 'description': 'Maintain pumps and compressors on a live plant.',
                           'requirements': 'OSHA 10'}, '/requisitions?req=%d' % vid)
edited = one("SELECT description, shift, openings, years_experience, requirements, starts_on FROM vacancies WHERE id = %d" % vid)
check('The recruiter completes the requisition: description, shift, how many, experience, must-haves, start', edited['description'] == 'Maintain pumps and compressors on a live plant.'
      and edited['shift'] == 'Days, 12 hours' and int(edited['openings']) == 3 and int(edited['years_experience']) == 4 and edited['requirements'] == 'OSHA 10' and edited['starts_on'] == '2026-11-16', edited)
status, body = rec.post('/requisitions', {'do': 'edit', 'vacancy_id': vid, 'title': '', 'description': '', 'openings': '3'}, '/requisitions?req=%d' % vid)
check('An edit without a role or description is refused, nothing changed', one("SELECT title FROM vacancies WHERE id = %d" % vid)['title'] == 'Loop Test 1')
status, body = rec.post('/requisitions', {'do': 'publish', 'vacancy_id': vid}, '/requisitions?req=%d' % vid)
vac = one("SELECT posting_status, published_by FROM vacancies WHERE id = %d" % vid)
check('The recruiter publishes it: it is published, by the recruiter', vac['posting_status'] == 'published' and vac['published_by'])
check('Published, the careers page shows the completed description', 'Maintain pumps and compressors on a live plant.' in public.get('/careers')[1])
check('Published, it is on the careers page and in the feed', 'Loop Test 1' in public.get('/careers')[1] and vid in [j['id'] for j in json.loads(public.get('/careers-feed')[1])['jobs']])
check('The owner is emailed that it is published', one("SELECT m.id FROM mail_outbox m WHERE m.purpose = 'published:%d' AND m.recipient = 'wk-admin@test.invalid'" % vid))
status, body = jerry.get('/requisitions')
check('The row shows the whole trail: requested by, emailed, published by', 'published by ' in body and 'Requested by' in body)

status, body = rec.post('/requisitions', {'do': 'create', 'send': 'publish', 'title': 'Loop Direct 2', 'discipline': 'mechanical', 'openings': '1',
                                          'description': 'Published at once.'}, '/requisitions')
direct = one("SELECT posting_status FROM vacancies WHERE title = 'Loop Direct 2'")
check('Create and publish still publishes at once', direct and direct['posting_status'] == 'published' and 'Loop Direct 2' in public.get('/careers')[1])

# P5-M109: what the client is billed is the owner's, not the recruiter's.
php_run('q("UPDATE vacancies SET pay_rate = 40, bill_rate = 60 WHERE id = ?", [(int) $argv[1]]);', vid)
check("The recruiter does not see the client's bill rate; the owner does", 'billed $' not in rec.get('/requisitions?req=%d' % vid)[1] and 'billed $' in jerry.get('/requisitions?req=%d' % vid)[1])
# P5-M109: a notice followed is a notice read.
status, body = rec.get('/notifications')
nid = re.search(r'href="/notifications\?open=(\d+)"', body)
rec.get('/notifications?open=%s' % (nid[1] if nid else '0'))
check('Following a notice marks it read', nid and one("SELECT read_at FROM notifications WHERE id = %s" % nid[1])['read_at'] is not None, nid)
# An applicant through the public link lands on that requisition.
status, form = public.get('/apply?id=%d' % vid)
public.open(urllib.request.Request(base + '/apply', urllib.parse.urlencode({'_csrf': public.token, 'vacancy_id': vid, 'name': 'Loop Applicant', 'email': 'loop-applicant@test.invalid',
                                                                            'phone': '555-010-3030', 'consent': '1'}).encode(), {'Accept': 'text/html'}))
app = one("SELECT a.id, a.vacancy_id FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE c.email = 'loop-applicant@test.invalid'")
check('An applicant through the public link lands on that requisition', app and int(app['vacancy_id']) == vid, app)
check('and the application says it came through the link', one("SELECT source FROM applications WHERE id = %d" % int(app['id']))['source'] == 'Apply link')
status, body = rec.get('/candidates?vacancy=%d' % vid)
check('and the recruiter sees them on Candidates for that requisition', 'Loop Applicant' in body)

# Sent to the owner for review, decided: both told by email.
jerry_id = one("SELECT id FROM users WHERE email = 'wk-admin@test.invalid'")['id']
php_run('require_once "work/test-app/app/reviews.php"; $e = review_request((int) $argv[1], (int) $argv[2], 4, "Strong fit.", (int) $argv[3]); if ($e) { fwrite(STDERR, $e); exit(1); }',
        app['id'], jerry_id, one("SELECT id FROM users WHERE email = 'wk-rec@test.invalid'")['id'])
review = one("SELECT id FROM application_reviews WHERE application_id = %d" % int(app['id']))
check('Sending the applicant for review emails the manager', one("SELECT id FROM mail_outbox WHERE purpose = 'review:%d' AND recipient = 'wk-admin@test.invalid'" % int(review['id'])))
php_run('require_once "work/test-app/app/reviews.php"; $e = review_decide((int) $argv[1], "validated", "", (int) $argv[2]); if ($e) { fwrite(STDERR, $e); exit(1); }', review['id'], jerry_id)
check('The decision emails the recruiter', one("SELECT id FROM mail_outbox WHERE purpose = 'decision:%d' AND recipient = 'wk-rec@test.invalid'" % int(review['id'])))

status, body = jerry.get('/email-delivery')
check('Email delivery lists the office emails, newest first, with their status', 'id="staff-mail"' in body and 'data-staff-mail="decision:%d"' % int(review['id']) in body and 'Decision on an applicant' in body)
php_run("q(\"DELETE FROM platform_settings WHERE setting_key = 'mail_capture_path'\");")
print(f'requisition_loop_http: {len(results)} checks passed')
