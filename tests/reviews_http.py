"""P5-M44 through the screens: the recruiter passes a screened applicant to a manager, who decides.
  - Hiring: Pass to the manager, with who, a fit from 1 to 5 and a recommendation; refused without them or to oneself; once at a time;
  - the manager (a supervisor, not on the recruiting desk) sees only what was passed to them on Candidate reviews: the dossier, the
    recruiter's word, the answers with their points, the applicant's documents (theirs only); never Hiring;
  - another interview is asked with a reason and scheduled in the same step; the application moves to Interview;
  - the interviewer records a scorecard (a rating, a verdict, what they saw); the recruiter sees it all in Hiring;
  - validated by another manager; every step tells the next person in the app.
Runs after own_scoring_db.php, on the walkthrough fixture.
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
                return r.status, r.read()
        except urllib.error.HTTPError as e:
            return e.code, e.read()

    def get(self, path):
        status, raw = self.open(base + path)
        body = raw.decode('utf-8', 'replace')
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
        status, raw = self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode(), {'Accept': 'text/html'}))
        return status, raw.decode('utf-8', 'replace')


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS ' + label)


def probe(*args):
    return json.loads(subprocess.run([php, 'work/reviews_probe.php', *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


def uid(email):
    return int(subprocess.run([php, '-r', 'require "work/test-app/app/bootstrap.php"; echo (int) val("SELECT id FROM users WHERE email = ?", [$argv[1]]);', email],
                              check=True, capture_output=True, text=True).stdout)


rec = Client(); rec.login('wk-rec@test.invalid')
sup = Client(); sup.login('wk-sup@test.invalid')
adm = Client(); adm.login('wk-admin@test.invalid')
one = probe('app', 'in-one@test.invalid')
page = '/hiring?v=%d&app=%d' % (one['vacancy_id'], one['id'])
body = rec.get(page)[1]
check('Hiring offers to pass a screened applicant to a manager: who, a fit from 1 to 5, a recommendation',
      'id="pass-to-manager"' in body and 'name="reviewer_id"' in body and 'name="rating"' in body and 'name="recommendation"' in body)

status, body = rec.post('/hiring', {'do': 'review_request', 'application_id': one['id'], 'reviewer_id': uid('wk-sup@test.invalid'), 'rating': 4, 'recommendation': ' ', 'back': page}, page)
check('Without a recommendation it is not passed', probe('review', one['id']) is None and 'Write your recommendation' in body)
status, body = rec.post('/hiring', {'do': 'review_request', 'application_id': one['id'], 'reviewer_id': uid('wk-rec@test.invalid'), 'rating': 4, 'recommendation': 'Me', 'back': page}, page)
check('Nor to oneself', probe('review', one['id']) is None and 'somebody else' in body)

REC = 'Five years on Haas lathes, travels 100%. Ask about night shifts.'
status, body = rec.post('/hiring', {'do': 'review_request', 'application_id': one['id'], 'reviewer_id': uid('wk-sup@test.invalid'), 'rating': 4, 'recommendation': REC, 'back': page}, page)
review = probe('review', one['id'])
check('Passed to the manager, who is told in the app', review and review['status'] == 'pending' and int(review['rating']) == 4
      and any('Indeed Test One to review' in n for n in probe('notes', 'wk-sup@test.invalid')), [review, probe('notes', 'wk-sup@test.invalid')])
check('Hiring shows it with the manager, and offers to take it back', 'data-review-status="pending"' in body and 'Take it back' in body, body[:200])
status, body = rec.post('/hiring', {'do': 'review_request', 'application_id': one['id'], 'reviewer_id': uid('wk-admin@test.invalid'), 'rating': 5, 'recommendation': 'Again', 'back': page}, page)
check('Only one manager at a time', probe('review', one['id'])['id'] == review['id'])
body = rec.get('/candidates?vacancy=%d' % one['vacancy_id'])[1]
check('The applicant list on Candidates shows who is with the manager', 'data-row-review="pending"' in body)

body = sup.get('/activity')[1]
check("The supervisor's menu offers Candidate reviews", 'href="/reviews"' in body)
check('The manager\'s Activity says an applicant waits for their review', 'Applicant waiting for your review' in body)
status, body = sup.get('/hiring')
check('A supervisor still cannot open Hiring', 'id="jobs-filter"' not in body and 'Indeed Test One' not in body, status)
body = sup.get('/reviews')[1]
check('Candidate reviews lists what was passed to them', 'Indeed Test One' in body and 'Fit 4/5' in body and ('/reviews?id=%d' % review['id']) in body)
body = sup.get('/reviews?id=%d' % review['id'])[1]
check("The dossier: what the recruiter says, the applicant's answers, the documents",
      REC in body and 'id="dossier-answers"' in body and 'How many years of CNC lathe experience' in body and '/resumes?download=' in body)
doc = probe('doc', 'in-one@test.invalid')
status, raw = sup.open(base + '/resumes?download=%d' % doc)
check('The manager opens the applicant\'s CV', status == 200 and raw.startswith(b'%PDF'), status)
status, raw = sup.open(base + '/resumes?download=%d' % probe('doc', 'add-emma@test.invalid'))
check('but not the documents of anybody not passed to them', status == 404, status)
status, body = sup.get('/resumes')
check('nor the list of every document', 'id="documents"' not in body and 'Emma Mailed' not in body, status)

status, body = sup.post('/reviews', {'do': 'decide', 'review_id': review['id'], 'decision': 'interview', 'note': ''}, '/reviews?id=%d' % review['id'])
check('Another interview needs a reason', probe('review', one['id'])['status'] == 'pending' and 'Say why' in body)
when = (datetime.datetime.now() + datetime.timedelta(days=2)).strftime('%Y-%m-%dT10:00')
status, body = sup.post('/reviews', {'do': 'decide', 'review_id': review['id'], 'decision': 'interview', 'note': 'Ask about night shifts and the Fanuc controls.',
                                     'interview_at': when, 'duration': 45, 'mode': 'video', 'place': 'https://meet.example.invalid/one', 'interviewer_id': uid('wk-sup@test.invalid')},
                        '/reviews?id=%d' % review['id'])
iv = probe('interview', one['id'])
check('The manager asks for another interview and schedules it in the same step; the application moves to Interview',
      probe('review', one['id'])['status'] == 'interview' and iv and iv['status'] == 'scheduled' and iv['mode'] == 'video' and probe('app', 'in-one@test.invalid')['stage'] == 'interview',
      [probe('review', one['id']), iv])
check('The recruiter is told', any('Another interview asked' in n for n in probe('notes', 'wk-rec@test.invalid')), probe('notes', 'wk-rec@test.invalid'))

body = sup.get('/reviews')[1]
check('The interviewer finds it under Interviews to hold', 'id="my-interviews"' in body and ('/reviews?interview=%d' % iv['id']) in body)
status, body = sup.post('/reviews', {'do': 'interview_record', 'interview_id': iv['id'], 'status': 'done', 'rating': 4, 'verdict': '', 'feedback': 'Good'}, '/reviews?interview=%d' % iv['id'])
check('A held interview needs a rating and a verdict', probe('interview', one['id'])['status'] == 'scheduled' and 'verdict' in body)
status, body = sup.post('/reviews', {'do': 'interview_record', 'interview_id': iv['id'], 'status': 'done', 'rating': 4, 'verdict': 'hire',
                                     'feedback': 'Knows Fanuc controls well, fine with nights.'}, '/reviews?interview=%d' % iv['id'])
iv = probe('interview', one['id'])
check('The scorecard is recorded: 4/5, hire', iv['status'] == 'done' and int(iv['rating']) == 4 and iv['verdict'] == 'hire', iv)
body = rec.get(page)[1]
check('Hiring shows the interview, its scorecard and the manager\'s decision', '4/5 · Hire' in body and 'Knows Fanuc controls well' in body and 'data-review-status="interview"' in body)

status, body = rec.post('/hiring', {'do': 'review_request', 'application_id': one['id'], 'reviewer_id': uid('wk-admin@test.invalid'), 'rating': 5,
                                    'recommendation': 'Interview went well; ready for an offer.', 'back': page}, page)
second = probe('review', one['id'])
status, body = adm.post('/reviews', {'do': 'decide', 'review_id': second['id'], 'decision': 'validated', 'note': ''}, '/reviews?id=%d' % second['id'])
check('Passed again after the interview, the owner validates it', probe('review', one['id'])['status'] == 'validated', probe('review', one['id']))
body = rec.get('/hiring?v=%d&stage=all' % one['vacancy_id'])[1]
check('and the list says so', 'data-row-review="validated"' in body)
body = sup.get('/reviews?id=%d' % second['id'])[1]
check('A review given to somebody else is not the supervisor\'s to see', 'Interview went well' not in body)

json.dump(results, open('work/rv-http-results.json', 'w'), indent=1)
