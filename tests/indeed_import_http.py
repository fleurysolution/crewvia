"""P5-M36 through the screens: an Indeed applicant export imported as applicants to a requisition.
  - the columns map themselves (name, email, phone, candidate location, relevant experience, education, source);
  - "City, ST" becomes a city and a state; experience and education go on the file; the source says Indeed;
  - the board's status and screening answers go on the application, a failed match is flagged;
  - someone already on file is held for review, not changed.
Runs after the walkthrough chain, on its fixture.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
wk = json.load(open('work/walkthrough.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []
HEAD = ['name', 'email', 'phone', 'status', 'candidate location', 'relevant experience', 'education', 'job title', 'job location', 'date', 'interest level', 'source',
        'Qualification 1', 'Qualification 1 Answer', 'Qualification 1 Match', 'Qualification 2', 'Qualification 2 Answer', 'Qualification 2 Match']
ROWS = [
    ['Indeed Test One', 'in-one@test.invalid', '+1 555 010 0001', 'Reviewed', 'Grand Forks, ND', 'CNC Machinist - Test Aircraft (2023-Present)', 'High school', 'CNC Machinist', 'United States', '10/14/2026', '', 'Sponsored Job Link',
     'How many years of CNC lathe experience do you have?', '5', 'Yes', 'What percentage of the time are you willing to travel for work?', '100%', 'Yes'],
    ['Indeed Test Two', 'in-two@test.invalid', '+1 555 010 0002', 'Awaiting Review', 'El Paso, TX', '', '', 'CNC Machinist', 'United States', '10/14/2026', '', 'Indeed',
     'How many years of CNC lathe experience do you have?', '3', 'Yes', 'What percentage of the time are you willing to travel for work?', '25%', 'No'],
    ['Indeed Test Again', 'wk-import-1@test.invalid', '+1 555 010 0003', 'Reviewed', 'Austin, TX', '', '', 'CNC Machinist', 'United States', '10/14/2026', '', 'Indeed',
     'How many years of CNC lathe experience do you have?', '2', 'Yes', '', '', ''],
]


def csv_text():
    import csv, io
    out = io.StringIO()
    w = csv.writer(out)
    w.writerow(HEAD)
    w.writerows(ROWS)
    return out.getvalue()


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
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()))

    def upload(self, path, page):
        self.get(page)
        b = '----indeed'
        body = ('--%s\r\nContent-Disposition: form-data; name="_csrf"\r\n\r\n%s\r\n--%s\r\nContent-Disposition: form-data; name="do"\r\n\r\npreview\r\n'
                '--%s\r\nContent-Disposition: form-data; name="sheet"; filename="CONFIDENTIAL_CNC_candidates.csv"\r\nContent-Type: text/csv\r\n\r\n%s\r\n--%s--\r\n'
                % (b, self.token, b, b, csv_text(), b)).encode()
        return self.open(urllib.request.Request(base + path, body, {'Content-Type': 'multipart/form-data; boundary=' + b}))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    return json.loads(subprocess.run([php, 'work/indeed_import_probe.php', *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


admin = Client(); admin.login('wk-admin@test.invalid')
status, body = admin.upload('/imports', '/imports')
sel = dict(re.findall(r'name="mapping\[([a-z_]+)\]">(?:(?!</select>).)*?<option value="(\d+)" selected', body, re.S))
check('An Indeed export maps itself: name, email, phone, location, experience, education, source',
      sel == {'full_name': '0', 'email': '1', 'phone': '2', 'location': '4', 'experience': '5', 'education': '6', 'source': '11'}, sel)
mapping = {'mapping[%s]' % k: v for k, v in sel.items()}
admin.post('/imports', {'do': 'commit', 'target': 'applicants', 'vacancy_id': wk['vA'], **mapping}, '/imports')
one = probe('person', 'in-one@test.invalid')
check('"Grand Forks, ND" becomes the city and the state; the source says Indeed and how they came',
      one and one['city'] == 'Grand Forks' and one['state'] == 'ND' and one['source'] == 'Indeed (Sponsored Job Link)', one)
check('Their experience and education are on their file', one['resume_text'] == 'Relevant experience: CNC Machinist - Test Aircraft (2023-Present)\nEducation: High school', one)
check('They are an applicant to the requisition, from Indeed', one['application'] and one['application']['vacancy_id'] == wk['vA'] and one['application']['source'] == 'Indeed', one)
check('The board\'s status and screening answers are on the application',
      one['note'].startswith('Status on the board: Reviewed') and '- How many years of CNC lathe experience do you have? 5' in one['note'] and 'does not meet' not in one['note'], one['note'])
two = probe('person', 'in-two@test.invalid')
check('An answer the board marked as not a match is flagged on the application', '- What percentage of the time are you willing to travel for work? 25% (does not meet)' in two['note'], two['note'])
batch = probe('batch')
check('and in the import\'s review list', any(r['note'].startswith('Imported. Does not meet on the board: What percentage') for r in batch), batch)
check('Someone already on file is held for review, their record unchanged', any(r['status'] == 'duplicate_review' for r in batch) and probe('person', 'wk-import-1@test.invalid')['source'] == 'Spreadsheet import', batch)

# ── P5-M37: the office attaches the CV and cover letter the board holds ──
def attach(client, cid, back, kind, name, content, mime):
    client.get(back)
    b = '----attach'
    crlf = chr(13) + chr(10)
    head = ''.join('--' + b + crlf + 'Content-Disposition: form-data; name="%s"' % k + crlf + crlf + str(v) + crlf
                   for k, v in (('_csrf', client.token), ('do', 'attach'), ('candidate_id', cid), ('kind', kind), ('back', back)))
    body = (head + '--' + b + crlf + 'Content-Disposition: form-data; name="resume"; filename="%s"' % name + crlf + 'Content-Type: ' + mime + crlf + crlf).encode() + content + (crlf + '--' + b + '--' + crlf).encode()
    return client.open(urllib.request.Request(base + '/resumes', body, {'Content-Type': 'multipart/form-data; boundary=' + b}))


PDF = ('%PDF-1.4' + chr(10) + 'Synthetic CV for the Indeed test' + chr(10) + '%%EOF').encode()
back = '/hiring?v=%d&app=%d' % (wk['vA'], one['application']['id'])
body = admin.get(back)[1]
check('Hiring offers to attach a CV, a cover letter or another document to the applicant', 'id="attach-document"' in body and 'value="cover_letter"' in body)
attach(admin, one['id'], back, 'cv', 'Indeed Test One CV.pdf', PDF, 'application/pdf')
attach(admin, one['id'], back, 'cover_letter', 'Cover letter.pdf', PDF, 'application/pdf')
docs = probe('docs', 'in-one@test.invalid')
check('Both are on their file, marked CV and cover letter, with who attached them', [d['kind'] for d in docs] == ['cv', 'cover_letter'] and all(d['attached_by'] for d in docs), docs)
st = probe('stored', docs[0]['storage_name'])
check('Stored encrypted, not as the PDF', st['size'] > 0 and not st['plain_pdf'], st)
body = admin.get(back)[1]
check('Hiring lists them with what they are', 'Indeed Test One CV.pdf' in body and 'Cover letter</span>' in body)
status, body = attach(admin, one['id'], back, 'other', 'notes.txt', b'plain text', 'text/plain')
check('A file that is not a PDF or a Word document is refused', len(probe('docs', 'in-one@test.invalid')) == 2, status)

json.dump(results, open('work/ii-http-results.json', 'w'), indent=1)
