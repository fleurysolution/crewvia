"""P5-M42 through the screens: the office adds somebody who applied by email, with what they sent.
  - Hiring's requisition offers Add an applicant, a full form: name, contact, where they live, how and when
    they applied, background, their message, and documents each with what it is (CV, cover letter, diploma...);
  - the person joins the requisition as a new applicant, with every document encrypted on their file and
    their message on the application; a bad file leaves nothing behind; a name and a way to reach them are needed;
  - Candidate documents lists every document with its kind, filters by name, kind and requisition,
    and keeps the job board import as a secondary tool with its steps.
Runs after indeed_import_http.py, on the walkthrough fixture.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
wk = json.load(open('work/walkthrough.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []
CRLF = chr(13) + chr(10)
PDF = ('%PDF-1.4' + chr(10) + 'Synthetic document for the add-applicant test' + chr(10) + '%%EOF').encode()


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                return r.status, r.read().decode(), r.geturl()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode(), ''

    def get(self, path):
        status, body, _ = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body

    def login(self, email, password='TestPassword123!'):
        self.get('/login')
        self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': password}).encode()))

    def add(self, vacancy, fields, files):
        self.get('/hiring?v=%d&tab=add' % vacancy)
        b = '----addapplicant'
        body = b''
        for k, v in [('_csrf', self.token), ('do', 'add_applicant'), ('vacancy_id', vacancy)] + list(fields.items()):
            body += ('--' + b + CRLF + 'Content-Disposition: form-data; name="%s"' % k + CRLF + CRLF + str(v) + CRLF).encode()
        for i, (kind, name, content, mime) in enumerate(files):
            body += ('--' + b + CRLF + 'Content-Disposition: form-data; name="document_kind[%d]"' % i + CRLF + CRLF + kind + CRLF).encode()
            body += ('--' + b + CRLF + 'Content-Disposition: form-data; name="documents[%d]"; filename="%s"' % (i, name) + CRLF + 'Content-Type: ' + mime + CRLF + CRLF).encode() + content + CRLF.encode()
        body += ('--' + b + '--' + CRLF).encode()
        return self.open(urllib.request.Request(base + '/hiring', body, {'Content-Type': 'multipart/form-data; boundary=' + b, 'Accept': 'text/html'}))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS ' + label)


def probe(*args):
    return json.loads(subprocess.run([php, 'work/indeed_import_probe.php', *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


admin = Client(); admin.login('wk-admin@test.invalid')
body = admin.get('/hiring?v=%d' % wk['vA'])[1]
check('An open requisition in Hiring offers Add an applicant', 'id="add-applicant-link"' in body and 'tab=add' in body)
body = admin.get('/hiring?v=%d&tab=add' % wk['vA'])[1]
form = (re.findall(r'<form[^>]*id="add-applicant".*?</form>', body, re.S) or [''])[0]
check('The form asks for everything an applicant needs: name, contact, where they live, how and when they applied, background, their message',
      all('name="%s"' % f in form for f in ('first_name', 'last_name', 'email', 'phone', 'city', 'state', 'zip', 'channel', 'received_on', 'degree', 'years_exp', 'message')), form[:300])
check('and their documents, each with what it is: CV, cover letter, diploma or certificate, other',
      'name="documents[0]"' in form and 'name="documents[5]"' in form and all('value="%s"' % k in form for k in ('cv', 'cover_letter', 'certificate', 'other')) and 'enctype="multipart/form-data"' in form)

status, body, _ = admin.add(wk['vA'], {'first_name': 'Emailed', 'last_name': 'Applicant', 'email': '', 'phone': ''}, [])
check('Without an email or a phone the applicant is not added, and the form keeps what was typed',
      probe('person', 'add-none@test.invalid') is None and 'value="Emailed"' in body and 'Enter an email or a phone number' in body, body[:300])

status, body, _ = admin.add(wk['vA'], {'first_name': 'Bad', 'last_name': 'File', 'email': 'add-bad@test.invalid', 'channel': 'email'},
                            [('cv', 'Bad File CV.pdf', PDF, 'application/pdf'), ('other', 'virus.exe', b'MZ not a document', 'application/octet-stream')])
check('One file that is not a PDF or Word document stops the whole addition: no person, no file left behind',
      probe('person', 'add-bad@test.invalid') is None and 'virus.exe' in body, body[:300])

MESSAGE = 'Hello, I saw your opening for a chemical engineer. Attached are my CV and my diploma.'
status, body, url = admin.add(wk['vA'], {'first_name': 'Emma', 'last_name': 'Mailed', 'email': 'add-emma@test.invalid', 'phone': '+1 555 010 0042',
                                         'city': 'Baton Rouge', 'state': 'LA', 'zip': '70801', 'channel': 'email', 'received_on': '2026-10-12',
                                         'degree': 'B.S. Chemical Engineering', 'years_exp': '7', 'message': MESSAGE},
                              [('cv', 'Emma Mailed CV.pdf', PDF, 'application/pdf'), ('cover_letter', 'Letter.pdf', PDF, 'application/pdf'),
                               ('certificate', 'BS diploma.pdf', PDF, 'application/pdf')])
person = probe('person', 'add-emma@test.invalid')
check('She joins the requisition as a new applicant, with her details', person and person['full_name'] == 'Emma Mailed' and person['city'] == 'Baton Rouge'
      and person['application']['vacancy_id'] == wk['vA'] and person['application']['stage'] == 'new' and person['application']['source'] == 'Applied by email', person)
check('Her message goes on the application, with how and when she applied', person['note'] and 'Applied by email, 2026-10-12' in person['note'] and MESSAGE in person['note'], person and person['note'])
docs = probe('docs', 'add-emma@test.invalid')
check('Her CV, cover letter and diploma are on her file, each marked for what it is, with who added them',
      sorted(d['kind'] for d in docs) == ['certificate', 'cover_letter', 'cv'] and all(d['attached_by'] for d in docs), docs)
check('Stored encrypted', all(not probe('stored', d['storage_name'])['plain_pdf'] and probe('stored', d['storage_name'])['size'] > 0 for d in docs))
check('The office lands on her application in Hiring, the diploma listed', ('app=%d' % person['application']['id']) in url and 'BS diploma.pdf' in body and 'Diploma or certificate' in body, url)

body = admin.get('/resumes')[1]
check('Candidate documents is a list of documents with their kind, not an upload box first',
      'Candidate documents' in body and 'id="documents"' in body and body.find('id="documents"') < body.find('id="attach-bulk"'), body[:200])
check('The job board import is a secondary tool, folded away with its steps', re.search(r'<details[^>]*id="import-board-documents"', body) is not None and 'id="attach-bulk"' in body)
body = admin.get('/resumes?kind=certificate')[1]
table = (re.findall(r'<table id="documents">.*?</table>', body, re.S) or [''])[0]
check('Filtered by kind, only diplomas and certificates show', 'BS diploma.pdf' in table and 'Emma Mailed CV.pdf' not in table, table[:300])
body = admin.get('/resumes?q=Emma+Mailed')[1]
table = (re.findall(r'<table id="documents">.*?</table>', body, re.S) or [''])[0]
check('Searched by name, her three documents show, each leading to her application', table.count('Emma Mailed</a>') == 3 and ('app=%d' % person['application']['id']) in table, table[:300])

json.dump(results, open('work/aa-http-results.json', 'w'), indent=1)
