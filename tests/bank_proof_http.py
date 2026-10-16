"""P5-M63 through the screens: new bank details come with a proof that payroll compares before accepting them.
  - the employee types the account number twice and attaches a voided cheque or a bank letter (photo or PDF);
    without the proof, or with two numbers that differ, nothing is sent;
  - payroll sees the proof beside the full numbers; a recruiter cannot open it; each look is recorded;
  - accepting needs the box "I compared the numbers with the proof".
Runs after project_manager_http.py (menu_crawl_db.php created the payroll account), on the walkthrough fixture.
"""
import base64, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
php = os.environ.get('PHP_BIN', 'php')
results = []
CRLF = chr(13) + chr(10)
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token, self.ctype = None, None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                self.ctype = r.headers.get('Content-Type', '')
                return r.status, r.read()
        except urllib.error.HTTPError as e:
            self.ctype = e.headers.get('Content-Type', '')
            return e.code, e.read()

    def get(self, path, raw=False):
        status, body = self.open(base + path)
        if raw:
            return status, body
        text = body.decode('utf-8', 'replace')
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', text)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in text and 'Warning:' not in text and 'Notice:' not in text and 'Deprecated:' not in text, text[:800]
        return status, text

    def login(self, email, password='TestPassword123!'):
        self.get('/login')
        self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': password}).encode()))

    def post(self, path, values, page):
        self.get(page)
        s, b = self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode(), {'Accept': 'text/html'}))
        return s, b.decode('utf-8', 'replace')

    def post_files(self, path, values, files, page):
        self.get(page)
        b = '----bankproof'
        body = b''.join(('--' + b + CRLF + 'Content-Disposition: form-data; name="%s"' % k + CRLF + CRLF + str(v) + CRLF).encode() for k, v in [('_csrf', self.token)] + list(values.items()))
        for k, (name, data, ctype) in files.items():
            body += ('--' + b + CRLF + 'Content-Disposition: form-data; name="%s"; filename="%s"' % (k, name) + CRLF + 'Content-Type: ' + ctype + CRLF + CRLF).encode() + data + CRLF.encode()
        body += ('--' + b + '--' + CRLF).encode()
        s, out = self.open(urllib.request.Request(base + path, body, {'Content-Type': 'multipart/form-data; boundary=' + b, 'Accept': 'text/html'}))
        return s, out.decode('utf-8', 'replace')


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS ' + label)


def requests():
    out = subprocess.run([php, 'work/bank_proof_probe.php'], check=True, capture_output=True, text=True, encoding='utf-8').stdout
    return json.loads(out)


worker = Client(); worker.login('wk-w@test.invalid')
GOOD = {'do': 'self_bank', 'account_holder': 'Walk Worker', 'bank_name': 'Proof Bank', 'routing_number': '021000021', 'account_number': '987654321012', 'account_number_confirm': '987654321012'}
before = requests()
status, body = worker.post_files('/employee-folder', GOOD, {}, '/employee-folder')
check('Without a proof attached, nothing is sent', 'voided cheque' in body and requests() == before, body[:200])
status, body = worker.post_files('/employee-folder', {**GOOD, 'account_number_confirm': '987654321013'}, {'bank_proof': ('cheque.png', PNG, 'image/png')}, '/employee-folder')
check('With two account numbers that differ, nothing is sent', 'differ' in body and requests() == before, body[:200])
status, body = worker.post_files('/employee-folder', GOOD, {'bank_proof': ('cheque.txt', b'not a picture', 'text/plain')}, '/employee-folder')
check('A file that is not a photo or a PDF is refused', 'photo (JPEG or PNG) or a PDF' in body and requests() == before, body[:200])
status, body = worker.post_files('/employee-folder', GOOD, {'bank_proof': ('cheque.png', PNG, 'image/png')}, '/employee-folder')
mine = [r for r in requests() if r['last_four'] == '1012' and r['status'] == 'pending']
check('With the proof, they wait for payroll, the proof stored encrypted', len(mine) == 1 and mine[0]['proof_mime'] == 'image/png' and mine[0]['proof_on_disk_plain'] is False, (status, mine))
rid = mine[0]['id'] if mine else 0

payroll = Client(); payroll.login('mc-payroll@test.invalid')
status, body = payroll.get('/change-requests?reveal=%d' % rid)
check('Payroll sees the full numbers and the proof beside them', '987654321012' in body and ('data-proof="%d"' % rid) in body and ('/change-requests?proof=%d' % rid) in body, body[:300])
status, raw = payroll.get('/change-requests?proof=%d' % rid, raw=True)
check('The proof opens in the browser, as it was sent', status == 200 and raw == PNG and payroll.ctype.startswith('image/png'), (status, payroll.ctype))
rec = Client(); rec.login('wk-rec@test.invalid')
status, raw = rec.get('/change-requests?proof=%d' % rid, raw=True)
check('A recruiter cannot open it', status in (403, 404) and raw != PNG, status)
status, body = payroll.post('/change-requests', {'do': 'bank', 'request_id': rid, 'decision': 'approve', 'note': ''}, '/change-requests?reveal=%d' % rid)
check('Accepting without ticking "compared with the proof" is refused', [r for r in requests() if r['id'] == rid][0]['status'] == 'pending' and 'Compare the account' in body, body[:200])
status, body = payroll.post('/change-requests', {'do': 'bank', 'request_id': rid, 'decision': 'approve', 'note': '', 'matched': '1'}, '/change-requests?reveal=%d' % rid)
r = [r for r in requests() if r['id'] == rid][0]
check('Ticked, they are accepted, and every look at the proof is recorded', r['status'] == 'approved' and r['proof_views'] >= 1, r)

json.dump(results, open('work/bank-proof-results.json', 'w'), indent=1)
