"""P5-M53 through the screens: the offer letter, the candidate's answer, the office's countersignature.
  - the general conditions are written once, in Settings;
  - the portal shows the whole letter: the agency, the position and site, the terms, the job description, the conditions;
  - the candidate may decline it (with a reason the office reads);
  - signing does not hire: the office is told, Hiring marks it "to countersign" and shows what was signed;
  - one countersignature hires them: the assignment starts, they are told, their portal becomes their employee portal.
Runs after candidate_tell_http.py, on the walkthrough fixture (Quinn's offer from that test).
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
wk = json.load(open('work/walkthrough.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []
QUINN, PASSWORD = 'av-quinn@test.invalid', 'Portal-Test-Pass-2026'
CONDITIONS = 'Employment is at will. This offer is contingent on a background check and a drug test.'


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


admin = Client(); admin.login('wk-admin@test.invalid')
rec = Client(); rec.login('wk-rec@test.invalid')
quinn = Client(); quinn.login(QUINN, PASSWORD)

admin.post('/settings', {'do': 'workspace', 'offer_conditions': CONDITIONS}, '/settings')
check('The general conditions of every offer are written once, in Settings', CONDITIONS in admin.get('/settings')[1])

apps = probe('offer_flow', 'apps', QUINN)
offer = apps[0]
check('Quinn holds an offer (from the previous test)', offer['stage'] == 'offered', apps)

# Declining: a second offer on the other requisition.
page2 = '/hiring?v=%d&tab=matches' % wk['vB']
rec.post('/hiring', {'do': 'add', 'vacancy_id': wk['vB'], 'candidate_id': offer['candidate_id'], 'back': page2}, page2)
second = [a for a in probe('offer_flow', 'apps', QUINN) if a['vacancy_id'] == wk['vB']][0]
sw = '/screening-workflow?application_id=%d' % second['id']
asked = sorted(set(re.findall(r'name="answer\[(\d+)\]"', rec.get(sw)[1])))
rec.post('/screening-workflow', {'do': 'answers', 'application_id': second['id'], **{'answer[%s]' % q: '6' for q in asked}}, sw)
p2 = '/hiring?v=%d&app=%d' % (wk['vB'], second['id'])
status, body = rec.post('/hiring', {'do': 'move', 'application_id': second['id'], 'stage': 'offered', 'note': 'Second shift offer, night premium.', 'back': p2}, p2)
check('A second offer is made', probe('offer_flow', 'offer', second['id'])['stage'] == 'offered', re.findall(r'class="flash[^"]*"[^>]*>(.*?)</', body, re.S)[:1])

status, body = quinn.get('/portal')
letter = re.search(r'<article class="offer-letter" id="offer-letter-%d">(.*?)</article>' % offer['id'], body, re.S)
check('The portal shows the whole offer letter: agency, position and site, terms, the job, the general conditions',
      letter and 'Offer of employment' in letter[1] and 'Walk machinist req' in letter[1] and 'Pay: $45.00 an hour' in letter[1]
      and 'The job' in letter[1] and 'Synthetic.' in letter[1] and CONDITIONS in letter[1] and 'Print or save as PDF' in letter[1], body[-1500:])
check('and offers to accept or to decline it', ('id="offer-accept-%d"' % offer['id']) in body and ('id="offer-decline-%d"' % offer['id']) in body)

status, body = quinn.post('/portal', {'do': 'decline_offer', 'application_id': second['id'], 'reason': 'I took a day job nearer home.'}, '/portal')
check('Declining needs the box ticked', probe('offer_flow', 'offer', second['id'])['stage'] == 'offered')
quinn.post('/portal', {'do': 'decline_offer', 'application_id': second['id'], 'reason': 'I took a day job nearer home.', 'confirm': '1'}, '/portal')
st = probe('offer_flow', 'offer', second['id'])
check('The candidate declines the offer: withdrawn, with their reason, and the office is told',
      st['stage'] == 'withdrawn' and any('Offer declined by the candidate. I took a day job nearer home.' in (n or '') for n in st['events'])
      and probe('offer_flow', 'staff-notes', 'declined the offer for') > 0, st)

# P5-M54: a third application, with an interview to come, that signing will withdraw.
v3 = probe('offer_flow', 'open-vacancy', wk['vA'], wk['vB'])
p3 = '/hiring?v=%d&tab=matches' % v3
rec.post('/hiring', {'do': 'add', 'vacancy_id': v3, 'candidate_id': offer['candidate_id'], 'back': p3}, p3)
third = [a for a in probe('offer_flow', 'apps', QUINN) if a['vacancy_id'] == v3][0]
p3 = '/hiring?v=%d&app=%d' % (v3, third['id'])
who = re.findall(r'<select id="iv-who" name="interviewer_id">.*?<option value="(\d+)"', rec.get(p3)[1], re.S)
import datetime
rec.post('/hiring', {'do': 'interview_schedule', 'application_id': third['id'], 'interviewer_id': who[0], 'duration': 30, 'mode': 'phone', 'place': '',
                     'interview_at': (datetime.datetime.now() + datetime.timedelta(days=6)).strftime('%Y-%m-%dT10:00'), 'back': p3}, p3)
check('(a third application, with an interview to come)', third['stage'] == 'new' and probe('offer_flow', 'closed-items', third['id'])['interviews'] == ['scheduled'])

status, body = quinn.get('/portal')
nav = re.findall(r'href="(/[a-z-]+)"', body)
check("A candidate's menu holds what a candidate does: no pay, attendance, time off or HR screens",
      '/my-jobs' in nav and not ({'/my-payslips', '/attendance', '/timeoff', '/hr-records', '/benefits'} & set(nav)) and 'data-card="payslips"' not in body, sorted(set(nav)))
status, body = quinn.get('/attendance')
check('and those screens are refused, not only hidden', 'That part of the portal opens once you are hired.' in body and 'Welcome, Quinn Poster' in body)
check('The letter says, before signing, that signing withdraws the other applications', ('id="offer-others-%d"' % offer['id']) in quinn.get('/portal')[1])

status, body = quinn.post('/portal', {'do': 'accept_offer', 'application_id': offer['id'], 'consent': '1', 'signer_name': 'Quinn Poster'}, '/portal')
st3 = probe('offer_flow', 'offer', third['id'])
check('Signing withdraws the other applications in progress, saying why, and cancels their interviews',
      st3['stage'] == 'withdrawn' and any('the candidate signed the offer for Walk machinist req' in (n or '') for n in st3['events'])
      and probe('offer_flow', 'closed-items', third['id'])['interviews'] == ['cancelled'], [st3, probe('offer_flow', 'closed-items', third['id'])])
st = probe('offer_flow', 'offer', offer['id'])
check('Signing does not hire: still Offered, signed, waiting for the countersignature, no assignment yet',
      st['stage'] == 'offered' and st['signed'] and st['signed']['signer_name'] == 'Quinn Poster' and st['signed']['countersigned_at'] is None and st['placements'] == 0, st)
check('What is signed is the whole letter, the conditions included', CONDITIONS in st['signed']['offer_text'] and 'Synthetic.' in st['signed']['offer_text'])
check('The candidate reads that the office is countersigning it; nothing left to sign',
      'The office is reviewing and countersigning it' in body and ('id="offer-accept-%d"' % offer['id']) not in body and 'Candidate portal' in body)
check('The office is told to countersign', probe('offer_flow', 'staff-notes', 'signed the offer for') > 0)

page = '/hiring?v=%d&app=%d' % (offer['vacancy_id'], offer['id'])
check('Hiring marks it "Signed: to countersign" in the list', ('data-row-countersign="%d"' % offer['id']) in rec.get('/hiring?v=%d' % offer['vacancy_id'])[1])
status, body = rec.get(page)
check('and shows what the candidate signed, with the countersignature form', 'id="countersign"' in body and 'id="countersign-form"' in body and CONDITIONS in body)

rec.post('/hiring', {'do': 'countersign', 'application_id': offer['id'], 'countersigner_name': 'Walk Recruiter', 'back': page}, page)
check('Countersigning needs the review box ticked', probe('offer_flow', 'offer', offer['id'])['stage'] == 'offered')

mails = len(probe('candidate_tell', 'mail', QUINN))
status, body = rec.post('/hiring', {'do': 'countersign', 'application_id': offer['id'], 'countersigner_name': 'Walk Recruiter', 'reviewed': '1', 'back': page}, page)
st = probe('offer_flow', 'offer', offer['id'])
sent = probe('candidate_tell', 'mail', QUINN)
check('One countersignature hires them: Hired, countersigned by name, the assignment started',
      st['stage'] == 'accepted' and st['signed']['countersigner_name'] == 'Walk Recruiter' and st['signed']['countersigned_at'] and st['placements'] == 1, st)
check('They are told in their portal and by email', len(sent) == mails + 1 and 'you are hired for' in sent[-1]['subject'] and 'countersigned: you are hired' in sent[-1]['body'], sent[-1:])
status, body = quinn.get('/portal')
check('Their portal is now their employee portal', 'Employee portal' in body and 'Candidate portal' not in body)
nav = re.findall(r'href="(/[a-z-]+)"', body)
check('with the employee screens in the menu, and open', '/my-payslips' in nav and '/attendance' in nav and 'That part of the portal opens' not in quinn.get('/attendance')[1])

json.dump(results, open('work/offer-http-results.json', 'w'), indent=1)
