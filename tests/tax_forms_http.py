"""P5-M64 through the screens: the W-4, Section 1 of the I-9 and the direct deposit, filled in the onboarding tasks.
  - the office ties a requirement to its form; the worker's task then holds the form, not a note;
  - the Social Security number and the document numbers are masked fields typed twice; a mismatch, an
    invalid number or a signature that is not the worker's name sends nothing, and the form comes back
    filled, less the numbers;
  - the form is stored encrypted (no number readable in storage), with the hash of what was signed;
  - payroll opens a W-4 in full, the office an I-9 while it waits; a recruiter never a W-4; each look recorded;
  - accepting needs the box ("entered in ADP", "Section 1 complete"); sending back needs the reason, and the
    worker fills it again; the task follows the form and cannot be approved by hand;
  - the Direct deposit task holds the bank form, typed twice with the proof, and closes when payroll accepts.
Runs after project_manager_http.py (menu_crawl_db.php created the payroll account), on the walkthrough fixture.
"""
import base64, html, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
php = os.environ.get('PHP_BIN', 'php')
node = os.environ.get('NODE_BIN', 'node')
EMP_W4 = {'employer_name_address': 'RSS Test Inc., 1 Test Way, Gary, IN 46402', 'first_day': '2026-11-02', 'ein': '12-3456789'}
S2 = {'s2_b1_title': "Driver's license issued by a State or outlying possession of the U.S.", 's2_b1_authority': 'Indiana BMV', 's2_b1_number': 'D123456789', 's2_b1_expires': '2030-05-04',
      's2_c1_title': 'U.S. Social Security account number card (unrestricted)', 's2_c1_authority': 'SSA', 's2_c1_number': '', 's2_c1_expires': '',
      'first_day': '2026-11-02', 's2_rep_name_title': 'Walk, Recruiter, HR coordinator', 's2_signature': 'Walk Recruiter',
      's2_business_name': 'RSS Test Inc.', 's2_business_address': '1 Test Way, Gary, IN 46402'}


def official(client, fid, label):
    """The map the page gives the browser, filled into the official PDF by node, as official-form.js does."""
    status, body = client.get('/tax-forms?id=%d' % fid)
    m = re.search(r'<script type="application/json" id="tf-pdf-map">(.*?)</script>', body, re.S)
    assert m, label + ': no official form map on the page'
    mp = json.loads(m[1])
    status, raw = client.open(base + mp['src'])
    assert status == 200 and raw.startswith('%PDF'), label + ': the official blank form is not served'
    open('work/tf-map.json', 'w', encoding='utf-8').write(json.dumps(mp))
    out = subprocess.run([node, 'work/official_form_fill.cjs', 'work/test-app/public', 'work/tf-map.json', 'work/tf-%s.pdf' % label],
                         capture_output=True, text=True, encoding='utf-8')
    assert out.returncode == 0, label + ': ' + out.stderr[-600:]
    return mp, json.loads(out.stdout.strip().splitlines()[-1])
results = []
CRLF = chr(13) + chr(10)
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')
SSN, SSN2, I94 = '212551234', '212559876', '12345678901'


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token, self.url = None, None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                self.url = r.url
                return r.status, r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            self.url = e.url
            return e.code, e.read().decode('utf-8', 'replace')

    def get(self, path):
        status, text = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', text)
        if m:
            self.token = m[1]
        bad = [w for w in ('Fatal error', 'Warning:', 'Notice:', 'Deprecated:') if w in text]
        assert not bad, text[max(0, text.find(bad[0]) - 200):text.find(bad[0]) + 600] if bad else ''
        return status, text

    def login(self, email, password='TestPassword123!'):
        self.get('/login')
        self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': password}).encode()))

    def post(self, path, values, page):
        self.get(page)
        s, b = self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}, doseq=True).encode(), {'Accept': 'text/html'}))
        assert 'Fatal error' not in b and 'Warning:' not in b, b[:800]
        return s, b

    def post_files(self, path, values, files, page):
        self.get(page)
        b = '----taxforms'
        body = b''.join(('--' + b + CRLF + 'Content-Disposition: form-data; name="%s"' % k + CRLF + CRLF + str(v) + CRLF).encode() for k, v in [('_csrf', self.token)] + list(values.items()))
        for k, (name, data, ctype) in files.items():
            body += ('--' + b + CRLF + 'Content-Disposition: form-data; name="%s"; filename="%s"' % (k, name) + CRLF + 'Content-Type: ' + ctype + CRLF + CRLF).encode() + data + CRLF.encode()
        body += ('--' + b + '--' + CRLF).encode()
        return self.open(urllib.request.Request(base + path, body, {'Content-Type': 'multipart/form-data; boundary=' + b, 'Accept': 'text/html'}))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:900] if detail else '')
    results.append(label)
    print('PASS ' + label, flush=True)


def probe():
    out = subprocess.run([php, 'work/tax_forms_probe.php', ','.join([SSN, SSN2, I94])], check=True, capture_output=True, text=True, encoding='utf-8').stdout
    return json.loads(out)


def task(kind):
    return [t for t in probe()['tasks'] if t['kind'] == kind][0]


p = probe()
office = Client(); office.login('wk-rec@test.invalid')
office.post('/select-project', {'job_id': p['job'], 'then': '/onboarding'}, '/onboarding')
status, body = office.get('/onboarding')
check('The office ties a requirement to its form when it adds one', 'name="form_kind"' in body and 'Form W-4, filled in and signed in the portal' in body, body[:300])
for title, kind in [('Form W-4: federal tax withholding', 'w4'), ('Form I-9: identity and work authorization', 'i9'), ('Direct deposit', 'bank')]:
    office.post('/onboarding', {'do': 'requirement', 'title': title, 'instructions': 'Fill it in here.', 'for_crew': '1', 'form_kind': kind}, '/onboarding')
p = probe()
check('The three requirements hold their form', sorted(r['form_kind'] for r in p['requirements'] if r['form_kind']) == ['bank', 'i9', 'w4'], p['requirements'])

worker = Client(); worker.login('wk-w@test.invalid')
status, body = worker.get('/portal')
check('The worker\'s tasks hold the forms, not a note', 'data-form-kind="w4"' in body and 'href="/tax-forms?fill=w4"' in body and 'href="/tax-forms?fill=i9"' in body
      and 'data-bank-task="' in body and 'Do not enter Social Security numbers' not in body, body[body.find('id="onboarding"'):][:600])
check('The portal itself still asks for no Social Security number', 'name="ssn"' not in body)
check('No password field: the browser never puts a saved sign-in into a bank or SSN field', 'type="password"' not in body)
check('The bank account is typed twice, masked', re.search(r'name="account_number" type="text" class="secret"', body) and 'data-secret-pair="tb-acct-' in body)
status, body = worker.get('/tax-forms?fill=w4')
check('The W-4: the SSN masked and typed twice', re.search(r'name="ssn" type="text" class="secret"', body) and re.search(r'name="ssn_confirm" type="text" class="secret"[^>]*data-secret-pair', body)
      and 'Under penalties of perjury' in body and '/assets/masked-fields.js' in body, body[:400])

W4 = {'do': 'sign', 'form': 'w4', 'first_name': 'Walk', 'last_name': 'onsite', 'address': '12 Test Street', 'city': 'Gary', 'state': 'IN', 'zip': '46402',
      'ssn': SSN, 'ssn_confirm': SSN, 'filing_status': 'single', 'children': '1', 'other_dependents': '0', 'extra_withholding': '20',
      'signer_name': 'Walk onsite', 'attest': '1'}
status, body = worker.post('/tax-forms', {**W4, 'ssn_confirm': SSN2}, '/tax-forms?fill=w4')
check('Two SSNs that differ: nothing is sent, the form comes back filled, less the numbers',
      'The two Social Security numbers differ' in body and probe()['forms'] == [] and '12 Test Street' in body and SSN not in body and SSN2 not in body, body[:300])
status, body = worker.post('/tax-forms', {**W4, 'ssn': '000551234', 'ssn_confirm': '000551234'}, '/tax-forms?fill=w4')
check('A number that is not an SSN is refused', 'not a valid Social Security number' in body and probe()['forms'] == [])
status, body = worker.post('/tax-forms', {**W4, 'signer_name': 'Somebody Else'}, '/tax-forms?fill=w4')
check('A signature that is not the worker\'s name is refused', 'Sign by typing your first and last name' in body and probe()['forms'] == [])
status, body = worker.post('/tax-forms', W4, '/tax-forms?fill=w4')
f = probe()['forms']
check('Signed: stored encrypted, no number readable, the hash of what was signed kept',
      len(f) == 1 and f[0]['status'] == 'submitted' and f[0]['ssn_last_four'] == '1234' and not f[0]['secret_in_storage'] and f[0]['hash_ok']
      and f[0]['data']['ssn'] == SSN and f[0]['data']['step3_total'] == 2200 and f[0]['access'] == ['signed'], f)
w4 = f[0]['id']
p = probe()
check('The W-4 task and the employment record follow the form', task('w4')['status'] == 'submitted' and p['clearance']['w4_status'] == 'submitted' and p['clearance']['w4_form_id'] == w4, (task('w4'), p['clearance']))
status, body = worker.post('/tax-forms', W4, '/tax-forms?fill=w4')
check('A second W-4 while the first waits is refused', 'already waiting for payroll' in body and len(probe()['forms']) == 1)
status, body = worker.get('/tax-forms?id=%d' % w4)
check('The worker sees what they signed, the SSN by its last four', '•••••1234' in body and SSN not in body and 'Walk onsite' in body)

rec = Client(); rec.login('wk-rec@test.invalid')
status, body = rec.get('/tax-forms?id=%d' % w4)
check('A recruiter cannot open a W-4', status == 404 and SSN not in body, status)
status, body = office.post('/onboarding', {'do': 'review', 'task_id': task('w4')['id'], 'status': 'approved'}, '/onboarding')
check('Nobody approves the W-4 task by hand', 'closes by itself' in body and task('w4')['status'] == 'submitted', body[:200])

payroll = Client(); payroll.login('mc-payroll@test.invalid')
status, body = payroll.get('/change-requests')
check('Payroll sees the W-4 waiting, by the last four only', ('data-tax-form-waiting="%d"' % w4) in body and 'SSN ending 1234' in body and SSN not in body)
status, body = payroll.get('/tax-forms?id=%d' % w4)
check('Payroll opens it in full, and the look is recorded', SSN in body and 'matches what was signed' in body and probe()['forms'][0]['access'].count('opened') == 1, body[:300])
status, body = payroll.post('/tax-forms', {'do': 'decide', 'form_id': w4, 'decision': 'accept', 'note': ''}, '/tax-forms?id=%d' % w4)
check('Accepting without "entered in ADP" is refused', 'Enter the W-4 in ADP' in body and probe()['forms'][0]['status'] == 'submitted')
status, body = payroll.post('/tax-forms', {'do': 'decide', 'form_id': w4, 'decision': 'return', 'note': ''}, '/tax-forms?id=%d' % w4)
check('Sending back without a reason is refused', 'Say what to correct' in body and probe()['forms'][0]['status'] == 'submitted')
payroll.post('/tax-forms', {'do': 'decide', 'form_id': w4, 'decision': 'return', 'note': 'Your street number is missing'}, '/tax-forms?id=%d' % w4)
p = probe()
check('Sent back: the task goes back to the worker with the reason, who is told', p['forms'][0]['status'] == 'returned' and task('w4')['status'] == 'rejected'
      and 'Your street number is missing' in task('w4')['response'] and any('Your street number is missing' in m for m in p['told']) and p['clearance']['w4_status'] == 'pending', (task('w4'), p['told'][-2:]))
status, body = worker.get('/portal')
check('The worker can fill it again from the task', 'href="/tax-forms?fill=w4"' in body and 'Your street number is missing' in body)
worker.post('/tax-forms', {**W4, 'address': '12B Test Street'}, '/tax-forms?fill=w4')
w4b = probe()['forms'][-1]['id']
payroll.get('/tax-forms?id=%d' % w4b)
status, body = payroll.post('/tax-forms', {'do': 'decide', 'form_id': w4b, 'decision': 'accept', 'note': '', 'processed': '1', **EMP_W4, 'ein': '12-345'}, '/tax-forms?id=%d' % w4b)
check('Accepting a W-4 needs the "Employers Only" box: a short EIN is refused, what was typed comes back',
      'nine digits' in body and probe()['forms'][-1]['status'] == 'submitted' and 'RSS Test Inc., 1 Test Way' in body, body[:200])
payroll.post('/tax-forms', {'do': 'decide', 'form_id': w4b, 'decision': 'accept', 'note': '', 'processed': '1', **EMP_W4}, '/tax-forms?id=%d' % w4b)
p = probe()
check('Accepted: the task closes by itself, the employment record says reviewed, the worker is told',
      [x['status'] for x in p['forms']] == ['returned', 'accepted'] and task('w4')['status'] == 'approved' and p['clearance']['w4_status'] == 'reviewed'
      and any('W-4 was checked and accepted' in m for m in p['told']), (p['forms'], task('w4'), p['clearance']))

# ── I-9 Section 1 ─────────────────────────────────────────────────────────
I9 = {'do': 'sign', 'form': 'i9', 'first_name': 'Walk', 'last_name': 'onsite', 'address': '12B Test Street', 'city': 'Gary', 'state': 'IN', 'zip': '46402',
      'birth_date': '1990-05-04', 'ssn': SSN, 'ssn_confirm': SSN, 'status': 'authorized', 'doc_type': 'i94', 'doc_number': I94, 'doc_number_confirm': I94,
      'work_until_na': '1', 'signer_name': 'Walk onsite', 'attest': '1'}
status, body = worker.get('/tax-forms?fill=i9')
check('The I-9: the SSN and the document number masked and typed twice', re.search(r'name="doc_number" type="text" class="secret"', body) and re.search(r'name="doc_number_confirm" type="text" class="secret"', body)
      and 'I attest, under penalty of perjury' in body)
status, body = worker.post('/tax-forms', {**I9, 'doc_number_confirm': '12345678902'}, '/tax-forms?fill=i9')
check('Two document numbers that differ: nothing is sent', 'The two document numbers differ' in body and len(probe()['forms']) == 2)
status, body = worker.post('/tax-forms', {**I9, 'status': ''}, '/tax-forms?fill=i9')
check('Without a status ticked, nothing is sent', 'citizenship or immigration status' in body and len(probe()['forms']) == 2)
worker.post('/tax-forms', I9, '/tax-forms?fill=i9')
p = probe(); i9 = p['forms'][-1]
check('Section 1 signed: encrypted, the I-94 number unreadable in storage; the record says employee submitted',
      i9['form'] == 'i9' and i9['status'] == 'submitted' and not i9['secret_in_storage'] and i9['data']['doc_number'] == I94 and p['clearance']['i9_status'] == 'employee_submitted'
      and task('i9')['status'] == 'submitted', (i9, p['clearance']))
status, body = payroll.get('/tax-forms?id=%d' % i9['id'])
check('Payroll does not open an I-9', status == 404 and I94 not in body, status)
status, body = rec.get('/tax-forms?id=%d' % i9['id'])
check('The office opens it in full while it waits', I94 in body and SSN in body and probe()['forms'][-1]['access'].count('opened') == 1)
mp, filled = official(rec, i9['id'], 'i9')
v = filled['values']
check('The official I-9 (01/20/25) is filled with Section 1: names, address, birth date, SSN, box 4, the I-94 number, the signature',
      mp['src'].endswith('i-9-01-20-25.pdf') and v['Last Name (Family Name)'] == 'onsite' and v['First Name Given Name'] == 'Walk' and v['Address Street Number and Name'] == '12B Test Street'
      and v['Date of Birth mmddyyyy'] == '05/04/1990' and v['US Social Security Number'] == SSN and v['Form I94 Admission Number'] == I94 and v['Exp Date mmddyyyy'] == 'N/A'
      and filled['checked'] == ['CB_4'] and mp['choices'] == {'State': 'IN'} and v['Signature of Employee'].startswith('/s/ Walk onsite'), (v, filled['checked']))
status, body = rec.get('/tax-forms?id=%d' % i9['id'])
check('Section 2 is on the page, word for word, with the lists of acceptable documents', 'Section 2. Employer Review and Verification' in body and 'U.S. Passport Card' in body
      and html.unescape('I attest, under penalty of perjury, that (1) I have examined the documentation presented') in html.unescape(body))
status, body = rec.post('/tax-forms', {'do': 'decide', 'form_id': i9['id'], 'decision': 'accept', 'note': '', 'processed': '1', **{**S2, 's2_c1_title': ''}}, '/tax-forms?id=%d' % i9['id'])
check('Section 2 with a List B document and no List C is refused', 'one from List B and one from List C' in body and probe()['forms'][-1]['status'] == 'submitted', body[:200])
status, body = rec.post('/tax-forms', {'do': 'decide', 'form_id': i9['id'], 'decision': 'accept', 'note': '', **S2}, '/tax-forms?id=%d' % i9['id'])
check('Section 2 without the certification ticked is refused', 'tick the certification' in body and probe()['forms'][-1]['status'] == 'submitted', body[:200])
rec.post('/tax-forms', {'do': 'decide', 'form_id': i9['id'], 'decision': 'accept', 'note': '', 'processed': '1', **S2}, '/tax-forms?id=%d' % i9['id'])
p = probe()
check('Section 2 completed: the employment record says employer completed, first day 11/02, review due three business days later',
      p['clearance']['i9_status'] == 'employer_completed' and p['clearance']['first_day'] == '2026-11-02' and p['clearance']['review_due_on'] == '2026-11-05'
      and 'D123456789' not in json.dumps(p['forms'][-1]['employer_raw']), p['clearance'])
check('Accepted: the I-9 task closes', probe()['forms'][-1]['status'] == 'accepted' and task('i9')['status'] == 'approved')
status, body = rec.get('/tax-forms?id=%d' % i9['id'])
check('Once accepted, the office no longer opens it (identity documents, P5-M33)', status == 404 and I94 not in body, status)

# ── P5-M64c: the official forms, filled ───────────────────────────────────
mp, filled = official(payroll, w4b, 'w4')
P = 'topmostSubform[0].Page1[0].'
v = filled['values']
check('The official 2026 W-4 is filled from the record: name, address, SSN, Step 3, 4(c), the employer box',
      mp['src'].endswith('fw4-2026.pdf') and v[P + 'Step1a[0].f1_01[0]'] == 'Walk' and v[P + 'Step1a[0].f1_02[0]'] == 'onsite'
      and v[P + 'Step1a[0].f1_03[0]'] == '12B Test Street' and v[P + 'Step1a[0].f1_04[0]'] == 'Gary, IN 46402' and v[P + 'f1_05[0]'] == '212-55-1234'
      and v[P + 'Step3_ReadOrder[0].f1_06[0]'] == '2,200.00' and v[P + 'f1_08[0]'] == '2,200.00' and v[P + 'f1_11[0]'] == '20.00'
      and v[P + 'f1_12[0]'] == EMP_W4['employer_name_address'] and v[P + 'f1_13[0]'] == '11/02/2026' and v[P + 'f1_14[0]'] == '12-3456789'
      and filled['checked'] == [P + 'c1_1[0]'] and filled['fields_left'] == 0 and any('/s/ Walk onsite' in d['text'] for d in mp['draw']), (v, filled['checked']))
mp, filled = official(worker, w4b, 'w4-own')
check('The worker\'s own copy of the official W-4 shows the SSN by its last four only', filled['values'][P + 'f1_05[0]'] == 'XXX-XX-1234' and SSN not in json.dumps(mp))

# ── direct deposit, in the task ───────────────────────────────────────────
check('No bank details on file yet for the worker', not probe()['bank_verified'])
BANK = {'do': 'task_bank', 'account_holder': 'Walk onsite', 'bank_name': 'Task Bank', 'routing_number': '021000021', 'account_number': '555500001111', 'account_number_confirm': '555500001111'}
status, body = worker.post_files('/portal', {**BANK, 'account_number_confirm': '555500001112'}, {'bank_proof': ('cheque.png', PNG, 'image/png')}, '/portal')
check('From the task: two account numbers that differ send nothing', 'differ' in body and probe()['bank'] == [] and '/portal' in worker.url, body[:200])
worker.post_files('/portal', BANK, {'bank_proof': ('cheque.png', PNG, 'image/png')}, '/portal')
p = probe()
check('From the task: the bank details wait for payroll, the task shows sent', [(b['status'], b['last_four']) for b in p['bank']] == [('pending', '1111')] and task('bank')['status'] == 'submitted', (p['bank'], task('bank')))
rid = p['bank'][0]['id']
payroll.get('/change-requests?reveal=%d' % rid)
payroll.post('/change-requests', {'do': 'bank', 'request_id': rid, 'decision': 'approve', 'note': '', 'matched': '1'}, '/change-requests?reveal=%d' % rid)
check('Payroll accepts them: the Direct deposit task closes by itself', task('bank')['status'] == 'approved' and probe()['bank_verified'], task('bank'))
status, body = worker.get('/portal')
check('The portal shows the account in use by its last four, and no form any more', 'account ending 1111' in body and 'data-bank-task="' not in body and '555500001111' not in body)

status, body = worker.get('/tax-forms?fill=w4&lang=fr')
check('The help is in French, the official wording stays as on the form', 'numéro de sécurité sociale' in body and 'Under penalties of perjury' in body and 'Step 1: Enter Personal Information' in body)

json.dump(results, open('work/tax-forms-results.json', 'w'), indent=1)
