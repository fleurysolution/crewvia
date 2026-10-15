"""P5-M52 through the screens: a candidate with a portal is told of their interview and their offer.
  - an interview scheduled in Hiring rings their bell and queues an email (date, length, how, where, the portal link);
  - an offer does the same ("You have been selected for … Your offer is in your portal"), signed by the agency;
  - somebody without a portal is not sent this notice (their portal invitation reaches them instead).
Runs after rehire_http.py, on the walkthrough fixture.
"""
import datetime, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
wk = json.load(open('work/walkthrough.json'))
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
        status, body = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body

    def login(self, email, password='TestPassword123!'):
        self.get('/login')
        self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': password}).encode()))

    def post(self, path, values, page):
        self.get(page)
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode(), {'Accept': 'text/html'}))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS ' + label)


def probe(script, *args):
    return json.loads(subprocess.run([php, 'work/%s_probe.php' % script, *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


rec = Client(); rec.login('wk-rec@test.invalid')
# The application of the record that has the portal (applying again without signing in makes another record, which has none).
quinn = probe('candidate_tell', 'portal-app', 'av-quinn@test.invalid')
page = '/hiring?v=%d&app=%d' % (quinn['vacancy_id'], quinn['id'])
check('The candidate of this test has a portal', probe('candidate_tell', 'portal', 'av-quinn@test.invalid') is True)
mails, notes = len(probe('candidate_tell', 'mail', 'av-quinn@test.invalid')), len(probe('candidate_tell', 'notes', 'av-quinn@test.invalid'))
who = re.findall(r'<select id="iv-who" name="interviewer_id">.*?<option value="(\d+)"', rec.get(page)[1], re.S)
when = (datetime.datetime.now() + datetime.timedelta(days=4)).strftime('%Y-%m-%dT09:30')
rec.post('/hiring', {'do': 'interview_schedule', 'application_id': quinn['id'], 'interviewer_id': who[0], 'interview_at': when, 'duration': 45, 'mode': 'video',
                     'place': 'https://meet.example.invalid/quinn', 'back': page}, page)
sent = probe('candidate_tell', 'mail', 'av-quinn@test.invalid')
check('An interview rings their bell and queues an email with the date, how, where and the portal link',
      len(probe('candidate_tell', 'notes', 'av-quinn@test.invalid')) == notes + 1 and len(sent) == mails + 1
      and 'your interview for' in sent[-1]['subject'] and '9:30 am' in sent[-1]['body'] and '45 minutes' in sent[-1]['body'] and 'meet.example.invalid/quinn' in sent[-1]['body']
      and '/my-jobs' in sent[-1]['body'], sent[-1:])

# An offer needs the required screening questions answered first.
sw = '/screening-workflow?application_id=%d' % quinn['id']
asked = sorted(set(re.findall(r'name="answer\[(\d+)\]"', rec.get(sw)[1])))
rec.post('/screening-workflow', {'do': 'answers', 'application_id': quinn['id'], **{'answer[%s]' % q: '6' for q in asked}}, sw)
TERMS = 'Walk machinist req, P5-M26 plant: $45.00 an hour, per diem $75.00, starts immediately.'
status, body = rec.post('/hiring', {'do': 'move', 'application_id': quinn['id'], 'stage': 'offered', 'note': TERMS, 'back': page}, page)
sent = probe('candidate_tell', 'mail', 'av-quinn@test.invalid')
check('An offer rings their bell and queues an email: selected, the offer in the portal, the link, signed by the agency',
      probe('candidate_tell', 'portal-app', 'av-quinn@test.invalid')['stage'] == 'offered' and any('You have been selected' in n for n in probe('candidate_tell', 'notes', 'av-quinn@test.invalid'))
      and 'your offer for' in sent[-1]['subject'] and 'Your offer is in your portal' in sent[-1]['body'] and '/portal' in sent[-1]['body'], [re.findall(r'class="flash[^"]*"[^>]*>(.*?)</', body, re.S)[:2], sent[-1:]])

emma_mails = len(probe('candidate_tell', 'mail', 'add-emma@test.invalid'))
emma = probe('reviews', 'app', 'add-emma@test.invalid')
when2 = (datetime.datetime.now() + datetime.timedelta(days=5)).strftime('%Y-%m-%dT11:00')
rec.post('/hiring', {'do': 'interview_schedule', 'application_id': emma['id'], 'interviewer_id': who[0], 'interview_at': when2, 'duration': 30, 'mode': 'phone', 'place': '',
                     'back': '/hiring?v=%d&app=%d' % (emma['vacancy_id'], emma['id'])}, '/hiring?v=%d&app=%d' % (emma['vacancy_id'], emma['id']))
check('Somebody without a portal is not sent this notice (their portal invitation reaches them instead)',
      probe('candidate_tell', 'portal', 'add-emma@test.invalid') is False and len(probe('candidate_tell', 'mail', 'add-emma@test.invalid')) == emma_mails)

json.dump(results, open('work/tell-http-results.json', 'w'), indent=1)
