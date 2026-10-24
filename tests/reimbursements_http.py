"""P5-M110: a bag paid at the airport counter, paid back on the next pay.
  - the worker photographs the receipt in the claim form (Baggage); a receipt backs one claim only;
  - payroll approves it, then pays it on the pay: one reimbursement adjustment in the open period, tied to the claim,
    and the claim is paid - in one go, once;
  - the database refuses a claim paid both ways, paid with no trace, or changed once paid;
  - a reimbursement typed by hand while a claim waits is refused;
  - the pay period links the adjustment to its claim and receipt; the worker sees it paid; the payslip names it;
  - the accounts count it once (in the pay period, not again as a separate payment).
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request, uuid

base = 'http://127.0.0.1:8097'
php = os.environ.get('PHP_BIN', 'php')
PNG = bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d4944415478da63f8ffff3f0005fe02fea7d6a4860000000049454e44ae426082')


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
        status, text = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', text)
        if m:
            self.token = m[1]
        bad = [w for w in ('Fatal error', 'Warning:', 'Notice:', 'Deprecated:') if w in text]
        assert not bad, text[max(0, text.find(bad[0]) - 200):text.find(bad[0]) + 600] if bad else ''
        return status, text

    def login(self, email):
        self.get('/login')
        self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': 'TestPassword123!'}).encode()))

    def post(self, path, values, page):
        self.get(page)
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}, doseq=True).encode(), {'Accept': 'text/html'}))

    def post_file(self, path, values, field, filename, content, mime, page):
        self.get(page)
        b = uuid.uuid4().hex
        parts = []
        for k, v in {'_csrf': self.token, **values}.items():
            parts.append(('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (b, k, v)).encode())
        parts.append(('--%s\r\nContent-Disposition: form-data; name="%s"; filename="%s"\r\nContent-Type: %s\r\n\r\n' % (b, field, filename, mime)).encode() + content + b'\r\n')
        parts.append(('--%s--\r\n' % b).encode())
        return self.open(urllib.request.Request(base + path, b''.join(parts), {'Content-Type': 'multipart/form-data; boundary=' + b, 'Accept': 'text/html'}))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:900] if detail else '')
    print('PASS ' + label, flush=True)


def php_run(code, *args):
    return subprocess.run([php, '-r', "require 'work/test-app/app/bootstrap.php'; " + code, *map(str, args)], check=True, capture_output=True, text=True, encoding='utf-8').stdout


def one(query, *args):
    rows = json.loads(php_run('echo json_encode(rows($argv[1], array_slice($argv, 2)));', query, *args))
    return rows[0] if rows else None


def refused_by_db(why, sql, *args):
    out = php_run('try { q($argv[1], array_slice($argv, 2)); echo "accepted"; } catch (Throwable $e) { echo "refused: " . $e->getMessage(); }', sql, *args)
    return out.startswith('refused') and why in out


me = one("SELECT p.id AS placement, w.candidate_id, u.id AS user_id FROM users u JOIN worker_accounts w ON w.user_id = u.id JOIN placements p ON p.candidate_id = w.candidate_id WHERE u.email = 'worker@test.invalid' ORDER BY p.id LIMIT 1")
admin_id = one("SELECT id FROM users WHERE email = 'admin@test.invalid'")['id']
php_run("q(\"INSERT INTO payroll_runs (week_ending, opened_by) VALUES ('2026-12-26', ?)\", [(int) $argv[1]]);", admin_id)
run = one("SELECT id FROM payroll_runs WHERE week_ending = '2026-12-26'")['id']

worker = Client(); worker.login('worker@test.invalid')
status, page = worker.get('/expenses')
check('The claim form takes a photo of the receipt, and offers Baggage', status == 200 and 'name="receipt" type="file"' in page and 'value="baggage"' in page, page[:400])
status, _ = worker.post_file('/expenses', {'do': 'claim', 'placement_id': me['placement'], 'category': 'baggage', 'amount': '35.00', 'receipt_document_id': '0', 'note': 'Checked bag, Houston'},
                             'receipt', 'bag.png', PNG, 'image/png', '/expenses')
claim = one("SELECT e.*, d.document_type FROM expense_claims e JOIN worker_documents d ON d.id = e.receipt_document_id WHERE e.note = 'Checked bag, Houston'")
check('Filed with the receipt photographed in the form', claim and claim['status'] == 'submitted' and claim['category'] == 'baggage' and claim['document_type'] == 'Receipt', [status, claim])
cid = int(claim['id'])
status, body = worker.post('/expenses', {'do': 'claim', 'placement_id': me['placement'], 'category': 'other', 'amount': '35', 'receipt_document_id': claim['receipt_document_id']}, '/expenses')
check('The same receipt does not back a second claim', 'already on another claim' in body and one("SELECT COUNT(*) AS n FROM expense_claims WHERE receipt_document_id = ?", claim['receipt_document_id'])['n'] == 1, status)

admin = Client(); admin.login('admin@test.invalid')
status, page = admin.get('/expenses')
check('Payroll sees the claim, its receipt, waiting for review', 'id="claim-%d"' % cid in page and '/proofs?download=%s' % claim['receipt_document_id'] in page and 'Waiting for review' in page)
admin.post('/expenses', {'do': 'review', 'claim_id': cid, 'status': 'approved'}, '/expenses')
check('Approved', one('SELECT status FROM expense_claims WHERE id = ?', cid)['status'] == 'approved')

status, body = admin.post('/payroll-runs', {'do': 'adjust', 'run_id': run, 'candidate_id': me['candidate_id'], 'kind': 'reimbursement', 'amount': '35', 'hours': '', 'reason': 'Bag'}, '/payroll-runs?id=%d' % run)
check('A reimbursement typed by hand while the claim waits is refused', 'approved reimbursement waiting' in body and one('SELECT COUNT(*) AS n FROM payroll_adjustments WHERE run_id = ?', run)['n'] == 0, status)
status, page = admin.get('/payroll-runs?id=%d' % run)
check('The open pay period lists it to pay', 'data-claim-to-pay="%d"' % cid in page)

check('The database refuses it paid on the pay with no adjustment', refused_by_db('has its payroll adjustment', "UPDATE expense_claims SET status = 'paid', payment_method = 'payroll', payment_reference = 'x' WHERE id = ?", cid))
check('and an adjustment for another amount', refused_by_db('for its amount', "INSERT INTO payroll_adjustments (run_id, candidate_id, kind, amount, reason, created_by, expense_claim_id) VALUES (?, ?, 'reimbursement', 99, 'x', 1, ?)", run, me['candidate_id'], cid))

admin.post('/expenses', {'do': 'pay_on_pay', 'claim_id': cid, 'run_id': run}, '/expenses')
paid = one('SELECT status, payment_method, payment_reference FROM expense_claims WHERE id = ?', cid)
adj = one('SELECT * FROM payroll_adjustments WHERE expense_claim_id = ?', cid)
check('Paid on the pay: one reimbursement adjustment in the period, tied to the claim, and the claim is paid',
      paid['status'] == 'paid' and paid['payment_method'] == 'payroll' and adj and int(adj['run_id']) == run and adj['kind'] == 'reimbursement'
      and float(adj['amount']) == 35.0 and int(adj['candidate_id']) == int(me['candidate_id']) and 'claim #%d' % cid in adj['reason'], [paid, adj])

_, again = admin.post('/expenses', {'do': 'pay_on_pay', 'claim_id': cid, 'run_id': run}, '/expenses')
_, other = admin.post('/expenses', {'do': 'pay', 'claim_id': cid, 'payment_method': 'check', 'payment_reference': 'CHK-1'}, '/expenses')
check('It is not paid a second time, either way', 'only once' in again and 'only once' in other
      and one('SELECT COUNT(*) AS n FROM payroll_adjustments WHERE expense_claim_id = ?', cid)['n'] == 1
      and one('SELECT payment_method FROM expense_claims WHERE id = ?', cid)['payment_method'] == 'payroll')
check('The database refuses changing a paid claim', refused_by_db('stays as it was paid', "UPDATE expense_claims SET status = 'approved' WHERE id = ?", cid)
      and refused_by_db('stays as it was paid', "UPDATE expense_claims SET payment_method = 'check' WHERE id = ?", cid))

status, page = admin.get('/payroll-runs?id=%d' % run)
check('The pay period links the adjustment to its claim and its receipt, and no longer lists it to pay',
      'href="/expenses#claim-%d"' % cid in page and '/proofs?download=%s' % claim['receipt_document_id'] in page and 'data-claim-to-pay="%d"' % cid not in page)
status, page = admin.get('/payslip?run=%d&candidate=%s' % (run, me['candidate_id']))
check('The payslip names it', 'Baggage (claim #%d)' % cid in page, status)
status, page = worker.get('/expenses')
check('The worker sees it paid on the pay of that week', 'data-paid-on-pay' in page and 'Paid' in page)
check('and is told', one("SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND message LIKE '%reimbursement of $35.00%'", me['user_id'])['n'] == 1)

entries = json.loads(php_run('require_once "work/test-app/app/accounting.php"; echo json_encode(array_column(accounting_entries("2027-12-31", false), "source"));'))
check('The accounts do not post it again as a separate payment', 'expense_claim:%d:paid' % cid not in entries)
health = json.loads(php_run('require_once "work/test-app/app/operations.php"; echo json_encode(ops_health(true)["checks"]["reimbursements"] ?? ops_health(true)["reimbursements"] ?? null);'))
check('System health checks reimbursements', health is not None, health)
