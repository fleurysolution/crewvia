"""P3-M02 through the screens: the account mapping and its confirmation,
what is and is not exported, balanced entries, each record once, the
QuickBooks file and its fingerprint, payroll off until turned on (ADP),
reversal by a correcting batch and re-export, roles and translations.
"""
import csv, hashlib, http.cookiejar, io, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
ac = json.load(open('work/acct.json'))
php = os.environ.get('PHP_BIN', 'php')
invA, invB, invC, invD = ac['inv']
v1, v2, v3 = ac['bills']
e1, e2, e3 = ac['claims']
results = []
THROUGH = '2024-12-31'


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def raw(self, path):
        try:
            with self.opener.open(base + path) as r:
                return r.status, dict(r.headers), r.read()
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read()

    def _send(self, req):
        try:
            with self.opener.open(req) as r:
                body, status = r.read().decode(), r.status
        except urllib.error.HTTPError as e:
            body, status = e.read().decode(), e.code
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body

    def get(self, path):
        return self._send(base + path)

    def post(self, path, values):
        return self._send(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()))

    def login(self, email):
        self.get('/login')
        status, body = self.post('/login', {'email': email, 'password': 'TestPassword123!'})
        return (status if '<title>Sign in' not in body else 401), body


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:600] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    out = subprocess.run([php, 'work/accounting_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


MINE = {'client_invoice:%d:issue' % invA, 'client_invoice:%d:issue' % invB, 'client_invoice:%d:payment' % invB,
        'vendor_invoice:%d:bill' % v1, 'vendor_invoice:%d:bill' % v2, 'vendor_invoice:%d:payment' % v2, 'expense_claim:%d:paid' % e1}
NEVER = {'client_invoice:%d:issue' % invC, 'client_invoice:%d:issue' % invD, 'vendor_invoice:%d:bill' % v3,
         'expense_claim:%d:paid' % e2, 'expense_claim:%d:paid' % e3}
PAYROLL = 'payroll_run:%d:journal' % ac['run']


def pending(client):
    status, body = client.get('/accounting?through=' + THROUGH)
    return status, body, set(re.findall(r'data-source="([^"]+)"', body))


def lines_of(batch, source=None):
    return [l for l in probe()['lines'] if l['batch_id'] == batch and (source is None or l['source'] == source)]


def act(client, values):
    client.get('/accounting')
    return client.post('/accounting', values)


admin = Client(); check('P3-M02 admin login', admin.login('acct-admin@test.invalid')[0] == 200)
payroll = Client(); check('P3-M02 payroll login', payroll.login('acct-payroll@test.invalid')[0] == 200)
recruiter = Client(); check('P3-M02 recruiter login', recruiter.login('acct-recruiter@test.invalid')[0] == 200)

check('A recruiter cannot open the QuickBooks export', recruiter.get('/accounting')[0] == 403)

# ── nothing goes out to an unconfirmed account ───────────────────────────
status, body, seen = pending(payroll)
check('Payroll sees what is waiting: the seven 2024 records, and none that must not go', MINE <= seen and not (NEVER & seen), (seen & (MINE | NEVER)))
check('Payroll is not in it while ADP posts payroll', PAYROLL not in seen and 'payroll not included' in body)
check('Unconfirmed accounts block the export, and the button is not offered', 'id="export-blockers"' in body and 'not confirmed against the QuickBooks chart' in body and 'id="export-form"' not in body)
check('Exporting anyway is refused', act(payroll, {'do': 'export', 'through': THROUGH})[0] == 422)

accounts = probe()['accounts']
mapping = {'do': 'mapping'}
for a in accounts:
    mapping['qb_account[%s]' % a['account_key']] = 'Staffing Revenue' if a['account_key'] == 'revenue' else a['qb_account']
    mapping['confirmed[%s]' % a['account_key']] = '1'
check('Payroll cannot change the mapping', act(payroll, mapping)[0] == 403)
check('An account name a spreadsheet would run as a formula is refused', act(admin, {**mapping, 'qb_account[bank]': '=HYPERLINK("x")'})[0] == 422)
check('An administrator maps revenue to "Staffing Revenue" and confirms every account', act(admin, mapping)[0] == 200
      and all(int(a['confirmed']) == 1 for a in probe()['accounts']))

# ── the export ───────────────────────────────────────────────────────────
check('An export dated in the future is refused', act(payroll, {'do': 'export', 'through': '2999-01-01'})[0] == 422)
status, _ = act(payroll, {'do': 'export', 'through': THROUGH})
b1 = probe()['batches'][-1]
check('Payroll exports up to the end of 2024', status == 200 and b1['kind'] == 'export' and b1['status'] == 'exported', b1)
B1 = int(b1['id'])
got = {l['source'] for l in lines_of(B1)}
check('The batch holds the seven records and none of the others', MINE <= got and not (NEVER & got) and PAYROLL not in got, got & (MINE | NEVER))
journals = {}
for l in lines_of(B1):
    journals.setdefault(l['journal_no'], [0.0, 0.0])
    journals[l['journal_no']][0] += float(l['debit']); journals[l['journal_no']][1] += float(l['credit'])
check('Every journal in it balances', all(abs(d - c) < 0.005 for d, c in journals.values()), journals)
issueA = lines_of(B1, 'client_invoice:%d:issue' % invA)
check('Invoice A: Dr A/R 5,000 for the client and project, Cr Staffing Revenue 5,000, dated 4 May',
      sorted((l['qb_account'], float(l['debit']), float(l['credit'])) for l in issueA) == [('Accounts Receivable (A/R)', 5000, 0), ('Staffing Revenue', 0, 5000)]
      and all(l['party'] == 'Accounting test client' and l['class'] == 'P3-M02 project' and l['txn_date'] == '2024-05-04' for l in issueA), issueA)
payB = lines_of(B1, 'client_invoice:%d:payment' % invB)
check('Invoice B\'s payment: Dr bank, Cr A/R 3,000 on the day it was paid', {l['account_key'] for l in payB} == {'bank', 'accounts_receivable'} and payB[0]['txn_date'] == '2024-06-01')
check('The hotel bill goes to hotels, the vehicle bill to transportation, both against A/P',
      {l['account_key'] for l in lines_of(B1, 'vendor_invoice:%d:bill' % v1)} == {'hotels_expense', 'accounts_payable'}
      and {l['account_key'] for l in lines_of(B1, 'vendor_invoice:%d:bill' % v2)} == {'transportation_expense', 'accounts_payable'})
claim = lines_of(B1, 'expense_claim:%d:paid' % e1)
check('The paid flight claim: transportation against the bank, with no worker\'s name', {l['account_key'] for l in claim} == {'transportation_expense', 'bank'} and all(not l['party'] for l in claim))

status, body, seen = pending(payroll)
check('Exported records are no longer waiting', not (MINE & seen))
before = [s for s in probe()['sources'] if s['active_key'] in MINE]
act(payroll, {'do': 'export', 'through': THROUGH})
after = [s for s in probe()['sources'] if s['active_key'] in MINE]
check('A second export cannot send them again: each is held once', len(before) == 7 and len(after) == 7 and all(s['batch_id'] == B1 for s in after), after)

# ── the file ─────────────────────────────────────────────────────────────
status, headers, data = payroll.raw('/accounting?download=%d' % B1)
text = data.decode()
check('The download is a CSV with the QuickBooks journal columns', status == 200 and headers.get('Content-Type', '').startswith('text/csv')
      and next(csv.reader(io.StringIO(text))) == ['Journal No', 'Journal Date', 'Account', 'Debits', 'Credits', 'Description', 'Name', 'Class'], (status, text[:200]))
check('Dates are MM/DD/YYYY and amounts plain', '05/04/2024' in text and '5000.00' in text and '$' not in text)
check('Its SHA-256 is the one recorded', hashlib.sha256(data).hexdigest() == b1['file_sha256'] == headers.get('X-Content-SHA256'))
check('A second download is the same file', payroll.raw('/accounting?download=%d' % B1)[2] == data)
probe('tamper', B1)
check('A stored line changed after export: the download is refused', payroll.raw('/accounting?download=%d' % B1)[0] == 409)
probe('untamper', B1)
check('Put back, it downloads again, identical', payroll.raw('/accounting?download=%d' % B1)[2] == data)
act(admin, {**mapping, 'qb_account[revenue]': 'Revenue renamed'})
check('Renaming an account later does not rewrite what was sent', payroll.raw('/accounting?download=%d' % B1)[2] == data
      and all(l['qb_account'] == 'Staffing Revenue' for l in lines_of(B1) if l['account_key'] == 'revenue'))

# ── payroll, when ADP does not post it ───────────────────────────────────
check('Payroll cannot turn on the payroll export', act(payroll, {'do': 'payroll', 'payroll': '1', 'reason': 'Ours'})[0] == 403)
check('Turning it on without a reason is refused', act(admin, {'do': 'payroll', 'payroll': '1', 'reason': ''})[0] == 422)
check('An administrator turns it on, with the reason', act(admin, {'do': 'payroll', 'payroll': '1', 'reason': 'ADP journal not connected to QuickBooks'})[0] == 200)
status, body, seen = pending(payroll)
check('The approved period now waits to be exported', PAYROLL in seen)
act(payroll, {'do': 'export', 'through': THROUGH})
B2 = int(probe()['batches'][-1]['id'])
pl = {l['account_key']: (float(l['debit']), float(l['credit'])) for l in lines_of(B2, PAYROLL)}
check('Payroll journal: wages 1,425 + 1,200 + 75 - 20 = 2,680, employer 50, per diem 170 + 150 = 320 on the debit side',
      pl.get('wages_expense') == (2680, 0) and pl.get('employer_expense') == (50, 0) and pl.get('per_diem_expense') == (320, 0), pl)
check('and deductions 100, employer owed 50, net owed 1,495 + 1,350 + 55 = 2,900 on the credit side; 3,050 each',
      pl.get('deductions_payable') == (0, 100) and pl.get('employer_payable') == (0, 50) and pl.get('net_pay_payable') == (0, 2900), pl)
act(admin, {'do': 'payroll', 'payroll': '0'})

# ── reversal ─────────────────────────────────────────────────────────────
check('A reversal without a reason is refused', act(payroll, {'do': 'reverse', 'batch_id': B1, 'date': '', 'reason': ''})[0] == 422)
status, _ = act(payroll, {'do': 'reverse', 'batch_id': B1, 'date': __import__('datetime').date.today().isoformat(), 'reason': 'Imported into the wrong company'})
batches = {int(b['id']): b for b in probe()['batches']}
R = int(batches[B1]['reversed_by_batch_id'] or 0)
check('The export is reversed by a correcting batch', status == 200 and batches[B1]['status'] == 'reversed' and R and batches[R]['kind'] == 'reversal' and int(batches[R]['reverses_batch_id']) == B1)
orig = sorted((l['source'], l['account_key'], float(l['debit']), float(l['credit'])) for l in lines_of(B1))
rev = sorted((l['source'], l['account_key'], float(l['credit']), float(l['debit'])) for l in lines_of(R))
check('The correcting batch is the same lines with debits and credits swapped', orig == rev and len(orig) > 0)
check('The original batch\'s lines are untouched', len(lines_of(B1)) == len(orig))
check('Reversing it twice is refused', act(payroll, {'do': 'reverse', 'batch_id': B1, 'date': __import__('datetime').date.today().isoformat(), 'reason': 'Again'})[0] == 422)
status, headers, data = payroll.raw('/accounting?download=%d' % R)
check('The reversal downloads, with its own fingerprint', status == 200 and hashlib.sha256(data).hexdigest() == batches[R]['file_sha256'])
status, body, seen = pending(payroll)
check('Its records wait to be exported again', MINE <= seen)
act(payroll, {'do': 'export', 'through': THROUGH})
B3 = int(probe()['batches'][-1]['id'])
active = [s for s in probe()['sources'] if s['active_key'] in MINE]
check('Re-exported in a new batch, each still held once', len(active) == 7 and all(s['batch_id'] == B3 for s in active), active)
check('The new batch uses today\'s mapping: "Revenue renamed"', any(l['qb_account'] == 'Revenue renamed' for l in lines_of(B3)))

# ── invoice dates from now on ────────────────────────────────────────────
payroll.post('/select-project', {'job_id': ac['job']})
payroll.get('/client-invoices')
payroll.post('/client-invoices', {'do': 'issue', 'invoice_id': invC})
check('Issuing an invoice now records when', any(r['id'] == invC and r['status'] == 'issued' and int(r['has_issued']) == 1 for r in probe()['issued']))
act(payroll, {'do': 'export', 'through': __import__('datetime').date.today().isoformat()})
check('It goes out in the next export, dated today', any(l['source'] == 'client_invoice:%d:issue' % invC and l['txn_date'] == __import__('datetime').date.today().isoformat()
      for l in probe()['lines']))

status, body = admin.get('/accounting?lang=fr')
check('The export is translated into French', 'Export QuickBooks' in body and 'Plan comptable' in body)
status, body = admin.get('/accounting?lang=es')
check('The export is translated into Spanish', 'Exportación a QuickBooks' in body)
admin.get('/accounting?lang=en')

json.dump(results, open('work/acct-http-results.json', 'w'), indent=1)
