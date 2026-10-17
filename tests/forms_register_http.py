"""P5-M68 through the screens: the forms register, for the owner and an auditor.
  - every W-4 and I-9 signed, who signed in front of whom, who accepted it, Section 2, E-Verify;
  - a CSV export with no Social Security number;
  - each form says who did what with it;
  - a recruiter does not open the register.
Runs after tax_forms_http.py, on its forms.
"""
import http.cookiejar, re, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
results = []
SSN = '212551234'


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token, self.headers = None, {}

    def get(self, path):
        try:
            with self.opener.open(base + path) as r:
                self.headers = r.headers
                status, text = r.status, r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            status, text = e.code, e.read().decode('utf-8', 'replace')
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', text)
        if m:
            self.token = m[1]
        bad = [w for w in ('Fatal error', 'Warning:', 'Notice:', 'Deprecated:') if w in text]
        assert not bad, text[max(0, text.find(bad[0]) - 200):text.find(bad[0]) + 600] if bad else ''
        return status, text

    def login(self, email):
        self.get('/login')
        self.opener.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': 'TestPassword123!'}).encode()))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:900] if detail else '')
    results.append(label)
    print('PASS ' + label, flush=True)


admin = Client(); admin.login('wk-admin@test.invalid')
status, body = admin.get('/forms-register')
rows = re.findall(r'<tr data-register-form="(\d+)"(.*?)</tr>', body, re.S)
walk = [r for r in rows if 'Walk onsite' in r[1]]
check('The owner sees every W-4 and I-9 signed, with who signed in front of whom', status == 200 and len(walk) >= 3
      and all('in person, in front of Walk rec' in r[1] for r in walk), [r[1][:300] for r in walk][:2])
i9 = [r for r in walk if 'I-9' in r[1] and 'Accepted' in r[1]]
check('The I-9: accepted, Section 2 by whom and its due date, the E-Verify case, the copies', i9 and 'Walk, Recruiter, HR coordinator' in i9[0][1] and 'due ' in i9[0][1]
      and '2026290123456AB' in i9[0][1], [r[1][:600] for r in i9][:1])
check('No Social Security number on the register', SSN not in body)
status, body = admin.get('/forms-register?form=w4')
check('Filtered to the W-4s, only W-4s', 'I-9 · ' not in body and 'W-4 · ' in body)
status, csv = admin.get('/forms-register?csv=1')
check('The export is a CSV with the witness, Section 2 and E-Verify, and no Social Security number',
      status == 200 and 'text/csv' in admin.headers.get('Content-Type', '') and csv.splitlines()[0].replace(chr(34), '').startswith('Form id,Person,Form,Status,Signed by') and 'Walk rec' in csv
      and '2026290123456AB' in csv and SSN not in csv, csv[:300])
fid = walk[0][0]
status, body = admin.get('/tax-forms?id=%s' % fid)
check('Each form says who did what with it, and when', 'id="access-log"' in body and 'signed in person' in body and 'opened' in body)
rec = Client(); rec.login('wk-rec@test.invalid')
status, body = rec.get('/forms-register')
check('A recruiter does not open the register', status == 403, status)
