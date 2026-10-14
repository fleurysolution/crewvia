"""P5-M33 through the screens: identity documents, and the W-4 signed in person.
  - a passport scanned by the coordinator is an identity document: the office opens it while it waits for validation;
  - validated, only the worker, an administrator and the readers an administrator authorizes open it; refusals are logged;
  - other documents are as before;
  - the coordinator makes a one-time code (and QR); the worker signs their W-4 with it from their own portal; it works once.
Runs after clock_in_http.py, on the walkthrough fixture and identity_docs_db.php's people.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request, uuid

base = 'http://127.0.0.1:8097'
wk = json.load(open('work/walkthrough.json'))
fx = json.load(open('work/identity_docs.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []
PDF = b'%PDF-1.4\nSynthetic identity scan for the test\n%%EOF'


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

    def fetch(self, path):
        return self.open(base + path)

    def login(self, email, password='TestPassword123!'):
        self.get('/login')
        self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': password}).encode()))

    def post(self, path, values, page=None):
        self.get(page or path)
        status, raw = self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()))
        return status, raw.decode('utf-8', 'replace')

    def upload(self, values):
        self.get('/proofs')
        boundary = 'Boundary' + uuid.uuid4().hex
        body = bytearray()
        for k, v in {'_csrf': self.token, 'do': 'upload', **values}.items():
            body.extend(('--' + boundary + '\r\nContent-Disposition: form-data; name="' + k + '"\r\n\r\n' + str(v) + '\r\n').encode())
        body.extend(('--' + boundary + '\r\nContent-Disposition: form-data; name="proof"; filename="scan.pdf"\r\nContent-Type: application/pdf\r\n\r\n').encode())
        body.extend(PDF)
        body.extend(('\r\n--' + boundary + '--\r\n').encode())
        status, raw = self.open(urllib.request.Request(base + '/proofs', bytes(body), headers={'Content-Type': 'multipart/form-data; boundary=' + boundary}))
        return status, raw.decode('utf-8', 'replace')


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    return json.loads(subprocess.run([php, 'work/identity_docs_probe.php', *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout)


def flash(body):
    return ' '.join(re.sub(r'<[^>]+>|\s+', ' ', f).strip() for f in re.findall(r'class="flash[^"]*"[^>]*>(.*?)</p>', body, re.S))


def said(body):
    return flash(body) if '<html' in body.lower() else body.strip()


coord = Client(); coord.login('wk-rec@test.invalid')
rec2 = Client(); rec2.login('id-rec2@test.invalid')
admin = Client(); admin.login('wk-admin@test.invalid')
payroll = Client(); payroll.login('id-payroll@test.invalid')
worker = Client(); worker.login('id-worker@test.invalid')
W = fx['worker']

# ── the scan ────────────────────────────────────────────────────────────
coord.upload({'candidate_id': W, 'document_type': 'Passport'})
coord.upload({'candidate_id': W, 'document_type': 'Safety certificate'})
docs = {d['document_type']: d for d in probe('docs', W)}
passport, cert = docs['Passport'], docs['Safety certificate']
check('A passport is stored as an identity document; a safety certificate is not', int(passport['restricted']) == 1 and int(cert['restricted']) == 0, docs)
status, raw = coord.fetch('/proofs?download=%d' % passport['id'])
check('While it waits for validation, the coordinator opens it to check it', status == 200 and raw == PDF, status)
check('and so does the office', rec2.fetch('/proofs?download=%d' % passport['id'])[0] == 200)
body = coord.get('/proofs')[1]
check('Documents marks it as an identity document', 'data-restricted="%d"' % passport['id'] in body)

# ── validated: the coordinator loses access ─────────────────────────────
coord.post('/proofs', {'do': 'review', 'document_id': passport['id'], 'status': 'approved', 'review_note': 'Matches the person in front of me.'}, '/proofs')
coord.post('/proofs', {'do': 'review', 'document_id': cert['id'], 'status': 'approved', 'review_note': ''}, '/proofs')
r0 = probe('refused')
status, raw = coord.fetch('/proofs?download=%d' % passport['id'])
check('Validated, the coordinator who checked it can no longer open it', status == 403 and b'restricted since it was validated' in raw, (status, raw[:200]))
check('nor anyone else in the office', rec2.fetch('/proofs?download=%d' % passport['id'])[0] == 403)
check('and each refusal is logged', probe('refused') == r0 + 2)
body = coord.get('/proofs')[1]
check('Documents says so in place of the download', 'data-locked="%d"' % passport['id'] in body and '/proofs?download=%d"' % passport['id'] not in body)
check('A validated document that proves nothing about identity still opens as before', coord.fetch('/proofs?download=%d' % cert['id'])[0] == 200)
status, raw = admin.fetch('/proofs?download=%d' % passport['id'])
check('An administrator (the VP) opens it', status == 200 and raw == PDF)
check('and so does the worker, their own', worker.fetch('/proofs?download=%d' % passport['id'])[0] == 200)

# ── authorized readers ──────────────────────────────────────────────────
check('Payroll, not authorized, cannot open it', payroll.fetch('/proofs?download=%d' % passport['id'])[0] in (403, 404))
body = admin.get('/proofs')[1]
check('The administrator sees who may open identity documents', 'id="identity-readers"' in body and 'data-reader="%d" data-allowed="0"' % fx['payroll'] in body)
status, body = coord.post('/proofs', {'do': 'identity_reader', 'user_id': fx['rec2'], 'allowed': '1'}, '/proofs')
check('Only an administrator can authorize: a recruiter who tries changes nothing', status == 403 and rec2.fetch('/proofs?download=%d' % passport['id'])[0] == 403, status)
admin.post('/proofs', {'do': 'identity_reader', 'user_id': fx['payroll'], 'allowed': '1'}, '/proofs')
check('Authorized, payroll opens it', payroll.fetch('/proofs?download=%d' % passport['id'])[0] == 200)
admin.post('/proofs', {'do': 'identity_reader', 'user_id': fx['payroll'], 'allowed': '0'}, '/proofs')
check('Withdrawn, it no longer can', payroll.fetch('/proofs?download=%d' % passport['id'])[0] in (403, 404))

# ── the W-4 signed in person ────────────────────────────────────────────
coord.upload({'candidate_id': W, 'document_type': 'W4'})
w4doc = [d for d in probe('docs', W) if d['document_type'] == 'W4'][-1]
body = coord.get('/employment?candidate_id=%d' % W)[1]
check('Employment offers the coordinator a signing code for the worker in front of them', 'id="signing"' in body and 'value="signing_code"' in body)
status, body = coord.post('/employment', {'do': 'signing_code', 'candidate_id': W}, '/employment?candidate_id=%d' % W)
m = re.search(r'id="signing-code">(\d{4}) (\d{4})<', body)
code = m[1] + m[2] if m else ''
check('The code is shown once, with its QR code, valid 15 minutes; only its hash is kept',
      m and 'class="signing-qr" data-code="%s"' % code in body and probe('codes', W)[-1]['hash_length'] == 64 and probe('codes', W)[-1]['minutes'] == 15, body[:400])
check('and not shown again', 'id="signing-code"' not in coord.get('/employment?candidate_id=%d' % W)[1])
check('Staff cannot sign for the worker', coord.post('/employment', {'do': 'w4_in_person', 'candidate_id': W, 'code': code, 'document_id': w4doc['id'], 'attest': '1'}, '/employment?candidate_id=%d' % W)[0] in (403, 200) and probe('w4', W)['w4_status'] != 'submitted')
body = worker.get('/employment?sign=' + code)[1]
check('The QR code opens the worker\'s own portal with the code filled in', 'id="sign"' in body and 'value="%s"' % code in body)
wrong = str((int(code) + 1) % 100000000).zfill(8)
status, body = worker.post('/employment', {'do': 'w4_in_person', 'code': wrong, 'document_id': w4doc['id'], 'attest': '1'}, '/employment')
check('A wrong code is refused', 'That code is not valid' in said(body) and probe('w4', W)['w4_status'] != 'submitted', said(body))
c0 = probe('notices', wk['ids']['rec'])['count']
status, body = worker.post('/employment', {'do': 'w4_in_person', 'code': code, 'document_id': w4doc['id'], 'attest': '1'}, '/employment')
w4 = probe('w4', W)
check('With the code, the W-4 is signed in person: submitted, with the time and the witness',
      w4['w4_status'] == 'submitted' and int(w4['w4_document_id']) == w4doc['id'] and w4['w4_in_person_at'] and int(w4['w4_witness_id']) == wk['ids']['rec'], (w4, said(body)))
check('The coordinator is told', probe('notices', wk['ids']['rec'])['count'] == c0 + 1 and 'signed their W-4 in front of you' in probe('notices', wk['ids']['rec'])['last'])
status, body = worker.post('/employment', {'do': 'w4_in_person', 'code': code, 'document_id': w4doc['id'], 'attest': '1'}, '/employment')
check('The code works once', 'That code is not valid' in said(body) and probe('codes', W)[-1]['outcome'] == 'signed', said(body))
body = coord.get('/employment?candidate_id=%d' % W)[1]
check('Employment shows it signed in person, and who witnessed it', 'data-w4-in-person="1"' in body and 'witnessed by Walk rec' in body)

json.dump(results, open('work/id-http-results.json', 'w'), indent=1)
