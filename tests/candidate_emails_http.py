"""P5-M104: the four emails every applicant receives, through the screens.
  - received: at once, to an applicant with no portal;
  - under review: when the recruiter moves the application to screening - once;
  - retained: when the manager approves - and no "under review" after it;
  - not retained: 24 hours after the rejection; a rejection undone in the meantime is never told;
  - each is written on the applicant's record; the agency edits a text, previews it, switches one off.
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


def mails(app_id):
    return {r['purpose'].split(':')[1]: r for r in json.loads(php_run('echo json_encode(rows($argv[1]));',
            "SELECT purpose, recipient FROM mail_outbox WHERE purpose LIKE 'candidate:%%:%d'" % app_id))}


php_run("q(\"INSERT INTO clients (name) VALUES ('Applicant mail client')\"); q(\"INSERT INTO jobs (client_id, title, status, site_city, site_state) VALUES (?, 'Applicant mail plant', 'active', 'Baytown', 'TX')\", [(int) db()->lastInsertId()]); q(\"INSERT INTO vacancies (job_id, title, description) VALUES (?, 'Mail Welder', 'Welders.')\", [(int) db()->lastInsertId()]);")
vid = int(one("SELECT id FROM vacancies WHERE title = 'Mail Welder'")['id'])


def apply(name, email):
    c = Client()
    c.get('/apply?id=%d' % vid)
    c.open(urllib.request.Request(base + '/apply', urllib.parse.urlencode({'_csrf': c.token, 'vacancy_id': vid, 'name': name, 'email': email, 'consent': '1'}).encode(), {'Accept': 'text/html'}))
    return int(one("SELECT a.id FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE c.email = '%s'" % email)['id'])


a1 = apply('Rosa Mail', 'ce-rosa@test.invalid')
m = mails(a1)
check('Received: at once, to the applicant, with no portal', 'received' in m and m['received']['recipient'] == 'ce-rosa@test.invalid' and len(m) == 1, m)
cid = int(one("SELECT candidate_id FROM applications WHERE id = %d" % a1)['candidate_id'])
check('It is written on the applicant\'s record', one("SELECT id FROM candidate_events WHERE candidate_id = %d AND event_type = 'email sent'" % cid)
      and one("SELECT id FROM candidate_email_log WHERE application_id = %d AND template_key = 'received' AND status = 'sent' AND template_fingerprint IS NOT NULL" % a1))

owner = Client(); owner.login('wk-admin@test.invalid')
owner.post('/candidates/%d' % cid, {'do': 'stage', 'application_id': a1, 'stage': 'screening'}, '/candidates/%d' % cid)
owner.post('/candidates/%d' % cid, {'do': 'stage', 'application_id': a1, 'stage': 'interview'}, '/candidates/%d' % cid)
m = mails(a1)
check('Under review: when the application is moved to screening, and only once', 'in_review' in m and int(one("SELECT COUNT(*) n FROM mail_outbox WHERE purpose = 'candidate:in_review:%d'" % a1)['n']) == 1, m)
owner.post('/candidates/%d' % cid, {'do': 'stage', 'application_id': a1, 'stage': 'offered', 'note': 'Offer: $40 an hour, 60 hours.'}, '/candidates/%d' % cid)
check('Retained: when the offer is made', 'retained' in mails(a1))

# Not retained: waits 24 hours; undone in the meantime, never told.
a2 = apply('Theo Mail', 'ce-theo@test.invalid')
c2 = int(one("SELECT candidate_id FROM applications WHERE id = %d" % a2)['candidate_id'])
owner.post('/candidates/%d' % c2, {'do': 'stage', 'application_id': a2, 'stage': 'rejected'}, '/candidates/%d' % c2)
check('Not retained is not sent at once: it waits its 24 hours', 'not_retained' not in mails(a2)
      and one("SELECT id FROM candidate_email_log WHERE application_id = %d AND template_key = 'not_retained' AND status = 'scheduled' AND send_after > NOW() + INTERVAL 23 HOUR" % a2))
php_run('require_once "work/test-app/app/candidate-emails.php"; ce_due();')
check('Before the 24 hours, the delivery job sends nothing', 'not_retained' not in mails(a2))
php_run("q(\"UPDATE candidate_email_log SET send_after = NOW() - INTERVAL 1 MINUTE WHERE application_id = ?\", [(int) $argv[1]]);", a2)
php_run('require_once "work/test-app/app/candidate-emails.php"; ce_due();')
check('After the 24 hours, the refusal is sent', 'not_retained' in mails(a2) and one("SELECT id FROM candidate_email_log WHERE application_id = %d AND template_key = 'not_retained' AND status = 'sent'" % a2))

a3 = apply('Ivy Mail', 'ce-ivy@test.invalid')
c3 = int(one("SELECT candidate_id FROM applications WHERE id = %d" % a3)['candidate_id'])
owner.post('/candidates/%d' % c3, {'do': 'stage', 'application_id': a3, 'stage': 'rejected'}, '/candidates/%d' % c3)
owner.post('/candidates/%d' % c3, {'do': 'stage', 'application_id': a3, 'stage': 'new'}, '/candidates/%d' % c3)
php_run("q(\"UPDATE candidate_email_log SET send_after = NOW() - INTERVAL 1 MINUTE WHERE application_id = ?\", [(int) $argv[1]]);", a3)
php_run('require_once "work/test-app/app/candidate-emails.php"; ce_due();')
check('A rejection undone within 24 hours is never told', 'not_retained' not in mails(a3) and one("SELECT id FROM candidate_email_log WHERE application_id = %d AND status = 'cancelled'" % a3))

# The agency edits, previews, switches off.
status, body = owner.get('/candidate-emails')
check('Emails to applicants lists the four, with when each is sent and a preview', status == 200 and all('data-template="%s"' % k in body for k in ('received', 'in_review', 'retained', 'not_retained'))
      and 'data-preview="received"' in body and 'Maria' in body)
owner.post('/candidate-emails', {'do': 'save', 'template_key': 'received', 'subject': 'Thanks {first_name} - {job_title}', 'body': 'Hello {first_name}, we got it. {agency}', 'enabled': '1'}, '/candidate-emails')
a4 = apply('Nina Mail', 'ce-nina@test.invalid')
subject = one("SELECT subject FROM candidate_email_log WHERE application_id = %d AND template_key = 'received'" % a4)
check('The edited text is what the next applicant receives', subject and subject['subject'] == 'Thanks Nina - Mail Welder', subject)
owner.post('/candidate-emails', {'do': 'save', 'template_key': 'received', 'subject': 'Thanks {first_name} - {job_title}', 'body': 'Hello {first_name}, we got it. {agency}'}, '/candidate-emails')
a5 = apply('Omar Mail', 'ce-omar@test.invalid')
check('Switched off, it is not sent', 'received' not in mails(a5))
owner.post('/candidate-emails', {'do': 'reset', 'template_key': 'received'}, '/candidate-emails')
owner.post('/candidate-emails', {'do': 'save', 'template_key': 'received', 'subject': 'We received your application: {job_title}', 'body': 'Hello {first_name}.', 'enabled': '1'}, '/candidate-emails')
owner.post('/candidate-emails', {'do': 'locale', 'locale': 'fr'}, '/candidate-emails')
status, body = owner.get('/candidate-emails')
check('The language is chosen: French texts by default', 'Nous avons reçu votre candidature' in body)
owner.post('/candidate-emails', {'do': 'locale', 'locale': 'en'}, '/candidate-emails')
rec = Client(); rec.login('wk-rec@test.invalid')
status, _ = rec.get('/candidate-emails')
check('A recruiter does not edit them (the owner does)', status == 403)
print(f'candidate_emails_http: {len(results)} checks passed')
