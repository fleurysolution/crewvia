"""P5-M43 through the screens: Crewvia's own scoring, set by the office.
  - the Indeed import recorded each board question as the requisition's own question, from the job board, not scored yet;
  - Screening questions sets graded rules: full marks at 10 years for a number, points per answer for a choice;
  - the applicants are re-ranked by those rules (5 years and 100% travel: 70; 3 years and 25%: 18), not by the board;
  - Hiring shows what each answer earned.
Runs after indeed_import_http.py and board_scores_db.php, on the walkthrough fixture.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

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
                return r.status, r.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode()

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

    def post(self, path, values, page=None):
        self.get(page or path)
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode(), {'Accept': 'text/html'}))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS ' + label)


def probe(*args):
    return json.loads(subprocess.run([php, 'work/own_scoring_probe.php', *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


admin = Client(); admin.login('wk-admin@test.invalid')
admin.post('/select-project', {'job_id': wk['job']}, '/projects')
qs = {q['question']: q for q in probe('questions', wk['vA'])}
lathe = qs.get('How many years of CNC lathe experience do you have?')
travel = qs.get('What percentage of the time are you willing to travel for work?')
check('The import made each board question a question of the requisition, from the job board, with no weight yet',
      lathe and travel and lathe['origin'] == 'board' and lathe['answer_type'] == 'number' and travel['answer_type'] == 'choice'
      and int(lathe['weight']) == 0 and '100%' in travel['choices'] and '25%' in travel['choices'], qs.keys())
check('and the applicants\' board answers are recorded against them', probe('answer', 'in-one@test.invalid', lathe['id']) == {'answer': '5', 'from_board': 1})

body = admin.get('/screening-questions?vacancy=%d' % wk['vA'])[1]
check('Screening questions shows them as from the job board, not scored yet, with a full-marks rule to set',
      'From the job board' in body and 'Not scored yet' in body and 'name="full_marks_at"' in body and 'name="choice_points"' in body)

status, body = admin.post('/screening-questions', {'do': 'update', 'question_id': lathe['id'], 'vacancy_id': wk['vA'], 'weight': 60, 'knockout_answer': '', 'full_marks_at': '10'},
                          '/screening-questions?vacancy=%d' % wk['vA'])
status, body = admin.post('/screening-questions', {'do': 'update', 'question_id': travel['id'], 'vacancy_id': wk['vA'], 'weight': 40, 'knockout_answer': '',
                                                   'choice_points': '100% = 100\n75% = 60\n50% = 30\n25% = 0'}, '/screening-questions?vacancy=%d' % wk['vA'])
check('The office sets the rules, and the page states them', 'Full marks from 10; part marks below' in body and '100% 100 · 75% 60 · 50% 30 · 25% 0' in body, re.findall(r'data-rule="%s">(.*?)</div>' % travel['id'], body, re.S))
check('Crewvia\'s rules score the applicants, not the board: 5 years and 100% travel make 70, 3 years and 25% make 18',
      probe('score', 'in-one@test.invalid') == 70.0 and probe('score', 'in-two@test.invalid') == 18.0, [probe('score', 'in-one@test.invalid'), probe('score', 'in-two@test.invalid')])

status, body = admin.post('/screening-questions', {'do': 'update', 'question_id': travel['id'], 'vacancy_id': wk['vA'], 'weight': 40, 'choice_points': '100% = 250'},
                          '/screening-questions?vacancy=%d' % wk['vA'])
check('Points above 100 are refused, the rule unchanged', 'from 0 to 100' in body and probe('score', 'in-one@test.invalid') == 70.0)

app = probe('application', 'in-one@test.invalid')
body = admin.get('/hiring?v=%d&app=%d' % (wk['vA'], app))[1]
check('Hiring shows what each answer earned', '30 of 60 points' in body and '40 of 40 points' in body, re.findall(r'data-earned="\d+">([^<]*)', body))

json.dump(results, open('work/own-http-results.json', 'w'), indent=1)
