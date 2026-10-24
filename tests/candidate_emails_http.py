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

# P5-M104b: rejecting the person from the Candidates list rejects their open applications, and they are told.
a6 = apply('Lea Mail', 'ce-lea@test.invalid')
c6 = int(one("SELECT candidate_id FROM applications WHERE id = %d" % a6)['candidate_id'])
owner.post('/candidates', {'do': 'stage', 'candidate_id': c6, 'stage': 'rejected'}, '/candidates')
check('Rejected from the Candidates list: the open application is rejected too, its refusal waits its 24 hours',
      one("SELECT stage FROM applications WHERE id = %d" % a6)['stage'] == 'rejected' and one("SELECT id FROM candidate_email_log WHERE application_id = %d AND status = 'scheduled'" % a6))

# P5-M104b: a burst of refusals is held - nothing leaves until the owner decides.
burst = []
php_run("""for ($i = 1; $i <= 12; $i++) { q("INSERT INTO candidates (full_name, email, stage) VALUES (?, ?, 'new')", ['Old Applicant ' . $i, 'ce-old' . $i . '@test.invalid']); q('INSERT INTO applications (candidate_id, vacancy_id) VALUES (?, ?)', [(int) db()->lastInsertId(), (int) $argv[1]]); }""", vid)
for row in json.loads(php_run('echo json_encode(rows($argv[1]));', "SELECT a.id, a.candidate_id FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE c.email LIKE 'ce-old%%'")):
    owner.post('/candidates/%s' % row['candidate_id'], {'do': 'stage', 'application_id': row['id'], 'stage': 'rejected'}, '/candidates/%s' % row['candidate_id'])
    burst.append(int(row['id']))
held = int(one("SELECT COUNT(*) n FROM candidate_email_log WHERE template_key = 'not_retained' AND status = 'held'")['n'])
check('Twelve rejections at once: the whole burst is held, including the first ones', held >= 12 and not one("SELECT id FROM candidate_email_log WHERE application_id IN (%s) AND status <> 'held'" % ','.join(map(str, burst))), held)
check('and the owner is told', one("SELECT id FROM notifications WHERE target = '/candidate-emails#held'"))
php_run("q(\"UPDATE candidate_email_log SET send_after = NOW() - INTERVAL 1 MINUTE WHERE status = 'held'\");")
php_run('require_once "work/test-app/app/candidate-emails.php"; ce_due();')
check('Held, nothing leaves even after 24 hours', not one("SELECT id FROM mail_outbox WHERE purpose IN (%s)" % ','.join("'candidate:not_retained:%d'" % b for b in burst)))
status, body = owner.get('/email-delivery')
check('Email delivery shows the held refusals', 'id="refusals-held"' in body)
status, body = owner.get('/candidate-emails')
check('Emails to applicants asks to decide: do not send them, or send them', 'id="held"' in body and 'data-held-cancel' in body and 'data-held-send' in body)
owner.post('/candidate-emails', {'do': 'held_cancel'}, '/candidate-emails')
check('Do not send them: cancelled, never sent', not one("SELECT id FROM candidate_email_log WHERE status = 'held'")
      and one("SELECT id FROM candidate_email_log WHERE application_id = %d AND status = 'cancelled'" % burst[0]))

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
# P5-M105b: a rehearsal - the office's emails go to the test inbox too; the test inbox itself is delivered as it is.
owner.post('/email-delivery', {'do': 'routing', 'test_to': 'solutionsfleury+{first}@gmail.com', 'reply_to': '', 'test_mode': '1', 'office_mail_test': '1'}, '/email-delivery')
route = json.loads(php_run('require_once "work/test-app/app/mail-routing.php"; echo json_encode([mail_route("wk-rec@test.invalid"), mail_route("solutionsfleury+demo@gmail.com"), mail_route("ce-rosa@test.invalid")]);'))
check("Rehearsal: an office colleague's email goes to the test inbox", route[0]['redirected'] and route[0]['to'].startswith('solutionsfleury+'), route[0])
check('Rehearsal: an address that is already the test inbox is delivered as it is', not route[1]['redirected'] and route[1]['to'] == 'solutionsfleury+demo@gmail.com', route[1])
check('Rehearsal: candidates still go to the test inbox', route[2]['redirected'], route[2])
status, body = owner.get('/email-delivery')
check('Email delivery says a rehearsal is on', 'id="office-mode"' in body)
check('A banner on every page says the rehearsal is on', 'id="rehearsal-on"' in owner.get('/candidates')[1])
owner.post('/email-delivery', {'do': 'routing', 'test_to': 'solutionsfleury+{first}@gmail.com', 'reply_to': '', 'test_mode': '1', 'office_mail_test': '1', 'office_notice_test': '1'}, '/email-delivery')
php_run('require_once "work/test-app/app/notices.php"; notify_user((int) val("SELECT id FROM users WHERE email = ?", ["wk-rec@test.invalid"]), "Rehearsal notice", "/x");')
held_notice = one("SELECT held FROM notifications WHERE message = 'Rehearsal notice'")
rec_page = rec.get('/notifications')[1]
check("Rehearsal with notices: a real colleague's notice is held back by the database, never shown", held_notice and int(held_notice['held']) == 1 and 'Rehearsal notice' not in rec_page, held_notice)
health = json.loads(php_run('require_once "work/test-app/app/operations.php"; echo json_encode(ops_health(true));'))
check('System health warns while a rehearsal is on', health.get('rehearsal', [''])[0] == 'warn', health.get('rehearsal'))
php_run("q(\"UPDATE platform_settings SET setting_value = DATE_FORMAT(NOW() - INTERVAL 2 DAY, '%Y-%m-%d %H:%i:%s') WHERE setting_key = 'office_mail_test_since'\");")
status, body = owner.get('/candidates')
check('A rehearsal left on for more than a day turns the banner red', 'class="flash err" id="rehearsal-on"' in body and 'the office receives none of its emails' in body)
owner.post('/email-delivery', {'do': 'routing', 'test_to': 'solutionsfleury+{first}@gmail.com', 'reply_to': '', 'test_mode': '1'}, '/email-delivery')
route = json.loads(php_run('require_once "work/test-app/app/mail-routing.php"; echo json_encode(mail_route("wk-rec@test.invalid"));'))
check('Rehearsal off: office staff get their own emails again', not route['redirected'], route)
status, body = owner.get('/email-delivery')
check('Switched off, Email delivery shows what the rehearsal kept from real people', 'id="rehearsal-held"' in body and re.search(r'data-held-notices="[1-9]', body) and 'Rehearsal notice' in body)
php_run("q(\"UPDATE notifications SET held = 0 WHERE held = 1\");")
# P5-M107: a staging copy past its deletion date fails System health; before it, a warning; gone, ok.
watch = json.loads(php_run('require_once "work/test-app/app/operations.php"; $d = sys_get_temp_dir() . "/crewvia-staging-watch-test"; @mkdir($d); $out = []; foreach (["2000-01-01", "2999-01-01"] as $by) { $GLOBALS["config"]["staging_watch"] = ["path" => $d, "delete_by" => $by]; $out[] = ops_health(true)["staging_copy"][0]; } rmdir($d); $out[] = ops_health(true)["staging_copy"][0]; echo json_encode($out);'))
check('A staging copy past its deletion date fails System health, warns before, is ok once gone', watch == ['fail', 'warn', 'ok'], watch)
# P5-M107: an installation with outbound_disabled sends nothing - no email, no text, no phone notification.
out = json.loads(php_run('require_once "work/test-app/app/campaigns.php"; require_once "work/test-app/app/twilio.php"; require_once "work/test-app/app/push.php"; $GLOBALS["config"]["outbound_disabled"] = true; echo json_encode([cm_mail("x@test.invalid", "s", "b", []), tw_configured(), push_send(["endpoint" => "https://example.invalid/x", "id" => 0])[0]]);'))
check('outbound_disabled: no email, no text, no phone notification can leave', out == [False, False, 0], out)
print(f'candidate_emails_http: {len(results)} checks passed')
