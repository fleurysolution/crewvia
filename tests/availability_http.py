"""P5-M45 through the screens: an applicant from a job poster's QR code says when they can start and gets a portal on the spot.
  - the public application page (what the QR code opens) shows the facts first (pay) and asks when they can start;
  - after applying: their availability is on their record, and they are offered to create their portal now;
    the link activates a portal (no email needed), which then shows their availability and lets them change it;
  - Hiring shows the recruiter what the applicant said, in the list and on the application;
  - an interview scheduled for them appears in their portal;
  - somebody applying again with an email that has an account is asked to sign in; nothing is merged.
Runs after reviews_db.php, on the walkthrough fixture.
"""
import datetime, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
wk = json.load(open('work/walkthrough.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []
CRLF = chr(13) + chr(10)
PASSWORD = 'Portal-Test-Pass-2026'


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                return r.status, r.read().decode('utf-8', 'replace'), r.geturl()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), ''

    def get(self, path):
        status, body, url = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body

    def login(self, email, password='TestPassword123!'):
        self.get('/login')
        return self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': password}).encode()))

    def post(self, path, values, page):
        self.get(page)
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode(), {'Accept': 'text/html'}))

    def apply(self, vacancy, fields):
        self.get('/apply?id=%d' % vacancy)
        b = '----applyform'
        body = b''
        for k, v in [('_csrf', self.token), ('vacancy_id', vacancy)] + list(fields.items()):
            body += ('--' + b + CRLF + 'Content-Disposition: form-data; name="%s"' % k + CRLF + CRLF + str(v) + CRLF).encode()
        body += ('--' + b + '--' + CRLF).encode()
        return self.open(urllib.request.Request(base + '/apply?id=%d' % vacancy, body, {'Content-Type': 'multipart/form-data; boundary=' + b, 'Accept': 'text/html'}))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS ' + label)


def probe(*args):
    return json.loads(subprocess.run([php, 'work/availability_probe.php', *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


# P5-M102: the airport is asked only when the job pays the travel - this one does (earlier tests may have edited the project).
subprocess.run([php, '-r', "require 'work/test-app/app/bootstrap.php'; q('UPDATE jobs SET travel_provided = 1 WHERE id = (SELECT job_id FROM vacancies WHERE id = ?)', [(int) $argv[1]]);", str(wk['vA'])], check=True)
visitor = Client()
status, body = visitor.get('/apply?id=%d' % wk['vA'])
check('The page a job poster\'s QR code opens shows the facts first, then asks when they can start',
      'id="job-facts"' in body and 'an hour' in body and 'name="availability"' in body and 'name="available_from"' in body and 'id="apply-form"' in body, body[:300])
check('and the airport they fly from (P5-M98)', 'name="home_airport"' in body)

start = (datetime.date.today() + datetime.timedelta(days=10)).isoformat()
status, body, url = visitor.apply(wk['vA'], {'name': 'Quinn Poster', 'email': 'av-quinn@test.invalid', 'phone': '+1 555 010 0077', 'availability': 'from', 'available_from': start, 'home_airport': 'IAH - Houston Bush, Houston TX', 'consent': '1'})
quinn = probe('candidate', 'av-quinn@test.invalid')
check('The airport given on applying is kept on their record', quinn and quinn['home_airport'] == 'IAH - Houston Bush, Houston TX', quinn)
check('Applying records when they can start, in their own words', quinn and quinn['availability'] == 'from' and quinn['available_from'] == start and quinn['application'] > 0, quinn)
link = (re.findall(r'href="(/activate\?token=[a-f0-9]+)"', body) or [''])[0]
check('Right after applying, they are offered to create their portal on the spot', 'id="portal-offer"' in body and 'Create my portal' in body and link, body[:400])

status, body = visitor.get(link)
check('The link opens the portal activation, with their name', 'Quinn Poster' in body and 'name="password"' in body)
visitor.post('/activate', {'token': link.split('=')[1], 'password': PASSWORD}, link)
check('Their portal is created, linked to their record', probe('account', 'av-quinn@test.invalid') == {'role': 'worker', 'candidate_id': quinn['id']}, probe('account', 'av-quinn@test.invalid'))

quinnc = Client(); quinnc.login('av-quinn@test.invalid', PASSWORD)
body = quinnc.get('/my-jobs')[1]
check('Not placed yet, the portal calls itself theirs as a candidate, and saves availability as soon as it is chosen (P5-M49)',
      'Candidate portal' in body and 'Worker portal</small>' not in body and 'data-autosave' in body, re.findall(r'<small>[^<]*</small>', body)[:2])
check('Their portal shows what they said and lets them change it', 'id="availability"' in body and 'Available from' in body and ('value="%s"' % start) in body, body[:300])

rec = Client(); rec.login('wk-rec@test.invalid')
body = rec.get('/candidates?vacancy=%d' % wk['vA'])[1]
check('Candidates shows the recruiter when the applicant can start', ('data-row-availability="%d"' % quinn['id']) in body)

status, body, url = quinnc.post('/my-jobs', {'do': 'availability', 'availability': 'available', 'available_from': ''}, '/my-jobs')
check('They change it: available now', probe('candidate', 'av-quinn@test.invalid')['availability'] == 'available')
body = rec.get('/hiring?v=%d&app=%d' % (wk['vA'], quinn['application']))[1]
check('and the recruiter sees it on the application, with the day it was said', 'id="candidate-availability"' in body and 'Available now' in body and 'said on' in body)
status, body, url = quinnc.post('/my-jobs', {'do': 'availability', 'availability': 'from', 'available_from': 'soon'}, '/my-jobs')
check('A start date that is not a date is refused, what they said kept', probe('candidate', 'av-quinn@test.invalid')['availability'] == 'available' and 'Give the date you can start' in body)

when = (datetime.datetime.now() + datetime.timedelta(days=3)).strftime('%Y-%m-%dT14:00')
interviewer = re.findall(r'<select id="iv-who" name="interviewer_id">.*?<option value="(\d+)"', rec.get('/hiring?v=%d&app=%d' % (wk['vA'], quinn['application']))[1], re.S)
rec.post('/hiring', {'do': 'interview_schedule', 'application_id': quinn['application'], 'interviewer_id': interviewer[0], 'interview_at': when, 'duration': 30, 'mode': 'phone', 'place': ''},
         '/hiring?v=%d&app=%d' % (wk['vA'], quinn['application']))
body = quinnc.get('/my-jobs')[1]
check('An interview scheduled for them appears in their portal', 'id="my-interviews"' in body and '2:00 pm' in body, re.findall(r'id="my-interviews".{0,300}', body, re.S)[:1])

again = Client()
status, body, url = again.apply(wk['vB'], {'name': 'Quinn Poster', 'email': 'av-quinn@test.invalid', 'availability': 'available', 'consent': '1'})
check('Applying again with the same email: asked to sign in, nothing merged, no second portal',
      'You already have a portal' in body and '/activate?token=' not in body and probe('account', 'av-quinn@test.invalid')['candidate_id'] == quinn['id'])

sam = Client()
status, body, url = sam.apply(wk['vA'], {'name': 'Sam Later', 'email': 'av-sam@test.invalid', 'availability': 'available', 'consent': '1'})
samc = probe('candidate', 'av-sam@test.invalid')
check("Somebody who leaves the portal for later is offered it, and nothing is emailed yet", 'Create my portal' in body and probe('outbox', 'av-sam@test.invalid') == 0)
rec.post('/hiring', {'do': 'move', 'application_id': samc['application'], 'stage': 'screening', 'note': 'Screening.', 'back': '/hiring?v=%d' % wk['vA']}, '/hiring?v=%d&app=%d' % (wk['vA'], samc['application']))
check("Moved to Screening, they still get the activation email: the link offered on the page does not stop it", probe('outbox', 'av-sam@test.invalid') == 1, probe('outbox', 'av-sam@test.invalid'))

json.dump(results, open('work/av-http-results.json', 'w'), indent=1)
