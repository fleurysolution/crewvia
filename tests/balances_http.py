"""P3-M04 through the screens: aging by client and vendor, payments
recorded once and applied to invoices, what stays on account, the refusals,
credit notes, taking back and reversing, the status following the balance,
the statement, the reconciliation checks, payment terms, the old "mark
paid" and "pay" buttons now recording payments, and the QuickBooks entries
for all of it.
"""
import datetime, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
bj = json.load(open('work/bal.json'))
php = os.environ.get('PHP_BIN', 'php')
i1, i2, i3, i4, i5, o1 = bj['inv']
b1, b2, b3, b4 = bj['bills']
CLIENT = bj['client']
VENDOR = 'Balances vendor'
results = []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

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
    out = subprocess.run([php, 'work/balances_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def inv(i):
    return next(x for x in probe()['invoices'] if x['id'] == i)


def bill(i):
    return next(x for x in probe()['bills'] if x['id'] == i)


def page(client, side='ar', party=CLIENT):
    return client.get('/balances?side=%s&party=%s' % (side, urllib.parse.quote(str(party))))


def aging_row(body, party):
    m = re.search(r'<tr data-party="%s">(.*?)</tr>' % re.escape(str(party)), body, re.S)
    if not m:
        return None
    row = m.group(1)
    buckets = {k: v for k, v in re.findall(r'data-bucket="(\w+)">([^<]*)<', row)}
    return {'open': float(re.search(r'data-open="([\d.-]+)"', row).group(1)), 'on_account': float(re.search(r'data-on-account="([\d.-]+)"', row).group(1)), 'buckets': buckets}


def post(client, values, side='ar', party=CLIENT):
    page(client, side, party)
    return client.post('/balances', {'side': side, 'party': party, **values})


def record(client, amount, ref, apply=None, side='ar', party=CLIENT, date=None):
    values = {'do': 'record', 'received_on': date or bj['today'], 'amount': amount, 'method': 'check', 'reference': ref}
    values.update({'apply[%d]' % k: v for k, v in (apply or {}).items()})
    return post(client, values, side, party)


admin = Client(); check('P3-M04 admin login', admin.login('bal-admin@test.invalid')[0] == 200)
payroll = Client(); check('P3-M04 payroll login', payroll.login('bal-payroll@test.invalid')[0] == 200)
recruiter = Client(); check('P3-M04 recruiter login', recruiter.login('bal-recruiter@test.invalid')[0] == 200)
check('A recruiter cannot see balances', recruiter.get('/balances')[0] == 403)

# ── aging ────────────────────────────────────────────────────────────────
status, body = page(payroll)
row = aging_row(body, CLIENT)
check('The client owes 3,500: 1,000 not yet due, 2,000 at 31-60 days, 500 over 90; the paid, draft and other client\'s invoices are not in it',
      status == 200 and row and row['open'] == 3500 and row['buckets'] == {'current': '$1,000.00', 'd30': '', 'd60': '$2,000.00', 'd90': '', 'over': '$500.00'}, row)
check('Nothing is flagged to reconcile yet', 'data-exception' not in body)

# ── a payment applied to two invoices, the rest on account ──────────────
pays_before = len(probe()['ar'])
status, _ = record(payroll, '2600', 'CHK-1001', {i2: '2000', i3: '500'})
p1 = probe()['ar'][-1]
check('A check for 2,600 is recorded and applied: 2,000 to one invoice, 500 to another', status == 200 and p1['amount'] == 2600 and len(probe()['ar']) == pays_before + 1)
check('Both invoices are now paid, dated the payment\'s day', inv(i2)['status'] == 'paid' and inv(i3)['status'] == 'paid' and inv(i2)['paid_at'].startswith(bj['today']))
status, body = page(payroll)
row = aging_row(body, CLIENT)
check('The client now owes 1,000 and has 100 on account', row['open'] == 1000 and row['on_account'] == 100, row)

for label, args in [('A payment applied beyond its own amount is refused, and nothing is recorded', ('50', 'CHK-1002', {i1: '60'})),
                    ('An application beyond what the invoice asks is refused, and nothing is recorded', ('2000', 'CHK-1003', {i1: '1500'})),
                    ('Applying to another client\'s invoice is refused', ('800', 'CHK-1004', {o1: '800'})),
                    ('The same reference twice is refused', ('10', 'CHK-1001', {})),
                    ('An amount of 0 is refused', ('0', 'CHK-1005', {}))]:
    n = len(probe()['ar'])
    status, body = record(payroll, *args)
    check(label, status == 422 and len(probe()['ar']) == n, (status, body[:200]))
check('A payment dated in the future is refused', record(payroll, '10', 'CHK-1006', date='2999-01-01')[0] == 422)

status, _ = post(payroll, {'do': 'apply', 'payment_id': p1['id'], 'invoice_id': i1, 'amount': '100'})
check('The 100 on account is applied to the invoice not yet due: 900 left', status == 200 and aging_row(page(payroll)[1], CLIENT)['open'] == 900)
check('A payment with nothing left cannot be applied again', post(payroll, {'do': 'apply', 'payment_id': p1['id'], 'invoice_id': i1, 'amount': '1'})[0] == 422)

# ── credit notes ─────────────────────────────────────────────────────────
check('Payroll cannot issue a credit note', post(payroll, {'do': 'credit', 'invoice_id': i1, 'amount': '150', 'reason': 'Billed twice', 'issued_on': bj['today']})[0] == 403)
check('A credit note above what is left is refused', post(admin, {'do': 'credit', 'invoice_id': i1, 'amount': '950', 'reason': 'Too much', 'issued_on': bj['today']})[0] == 422)
check('A credit note without a reason is refused', post(admin, {'do': 'credit', 'invoice_id': i1, 'amount': '150', 'reason': '', 'issued_on': bj['today']})[0] == 422)
check('An administrator credits 150 for two days billed twice: 750 left', post(admin, {'do': 'credit', 'invoice_id': i1, 'amount': '150', 'reason': 'Two days billed twice', 'issued_on': bj['today']})[0] == 200
      and aging_row(page(payroll)[1], CLIENT)['open'] == 750)

# ── taking back and reversing ────────────────────────────────────────────
alloc = next(a for a in probe()['ar_alloc'] if a['payment_id'] == p1['id'] and a['invoice_id'] == i2 and not a['reversed'])
check('Taking back an application needs a reason', post(payroll, {'do': 'unapply', 'allocation_id': alloc['id'], 'reason': ''})[0] == 422)
status, _ = post(payroll, {'do': 'unapply', 'allocation_id': alloc['id'], 'reason': 'Applied to the wrong invoice'})
check('Taken back, the invoice is issued again with 2,000 open, and the 2,000 is back on account', status == 200 and inv(i2)['status'] == 'issued' and inv(i2)['paid_at'] is None
      and aging_row(page(payroll)[1], CLIENT)['on_account'] == 2000)
status, _ = post(payroll, {'do': 'reverse_payment', 'payment_id': p1['id'], 'reason': 'Check returned unpaid'})
check('The bounced check is reversed: its applications go, the over-90 invoice is open again', status == 200 and probe()['ar'][[x['id'] for x in probe()['ar']].index(p1['id'])]['reversed'] == 1
      and inv(i3)['status'] == 'issued' and all(a['reversed'] for a in probe()['ar_alloc'] if a['payment_id'] == p1['id']))
check('Reversing it twice is refused', post(payroll, {'do': 'reverse_payment', 'payment_id': p1['id'], 'reason': 'Again'})[0] == 422)
status, body = page(payroll)
row = aging_row(body, CLIENT)
check('Back to 3,350 open: 1,000 less the 150 credit, 2,000 and 500; nothing on account', row['open'] == 3350 and row['on_account'] == 0, row)
running = re.findall(r'data-running="([\d.-]+)"', body)
check('The statement\'s running balance ends at the same 3,350', running and float(running[-1]) == 3350, running[-3:])
check('The statement shows the credit note and the reversal with their reasons', 'Two days billed twice' in body and 'Check returned unpaid' in body)

# ── reconciliation checks ────────────────────────────────────────────────
probe('mark', i1)
check('An invoice marked paid with money still open is flagged', 'is marked paid but $850.00 is still open' in page(payroll)[1])
probe('unmark', i1)
record(payroll, '300', 'OLD-1', date=bj['ago40'])
check('A payment on account for over 30 days is flagged', 'Payment OLD-1 has had $300.00 on account for over 30 days' in page(payroll)[1])
old = probe()['ar'][-1]
post(payroll, {'do': 'apply', 'payment_id': old['id'], 'invoice_id': i3, 'amount': '300'})
check('Applied, it is no longer flagged', 'OLD-1 has had' not in page(payroll)[1])

# ── payment terms and the invoice screen ─────────────────────────────────
check('Payroll cannot change payment terms', post(payroll, {'do': 'terms', 'payment_terms_days': '45'})[0] == 403)
check('Terms over 365 days are refused', post(admin, {'do': 'terms', 'payment_terms_days': '400'})[0] == 422)
check('The client is given 45 days', post(admin, {'do': 'terms', 'payment_terms_days': '45'})[0] == 200)
payroll.post('/select-project', {'job_id': bj['job']})
payroll.get('/client-invoices')
payroll.post('/client-invoices', {'do': 'issue', 'invoice_id': i5})
check('An invoice issued now is due 45 days later', inv(i5)['status'] == 'issued' and inv(i5)['due_on'] == bj['in45'], inv(i5))
payroll.get('/client-invoices')
check('"Mark payment received" without a reference is refused', payroll.post('/client-invoices', {'do': 'paid', 'invoice_id': i5, 'payment_method': 'ach', 'payment_reference': ''})[0] == 422)
payroll.get('/client-invoices')
status, _ = payroll.post('/client-invoices', {'do': 'paid', 'invoice_id': i5, 'payment_method': 'ach', 'payment_reference': 'ACH-77'})
check('"Mark payment received" now records the payment and applies it', status == 200 and inv(i5)['status'] == 'paid' and probe()['ar'][-1]['reference'] == 'ACH-77'
      and any(a['invoice_id'] == i5 and a['amount'] == 300 and not a['reversed'] for a in probe()['ar_alloc']))

# ── payables ─────────────────────────────────────────────────────────────
status, body = page(payroll, 'ap', VENDOR)
row = aging_row(body, VENDOR)
check('The vendor is owed 1,000: 400 a few days late, 600 not yet due; the bill not approved and the one paid before are not in it',
      row and row['open'] == 1000 and row['buckets']['d30'] == '$400.00' and row['buckets']['current'] == '$600.00', row)
payroll.get('/accounts-payable')
status, _ = payroll.post('/accounts-payable', {'do': 'pay', 'invoice_id': b1, 'payment_method': 'transfer', 'payment_reference': 'V-PAY-1'})
check('"Pay" on the bill register records the payment and the bill shows paid with its reference', status == 200 and bill(b1)['status'] == 'paid' and bill(b1)['payment_reference'] == 'V-PAY-1'
      and probe()['ap'][-1]['reference'] == 'V-PAY-1')
status, _ = record(payroll, '250', 'V-PAY-2', {b2: '250'}, 'ap', VENDOR)
check('A part payment of 250 leaves 350 on the other bill, still approved', status == 200 and bill(b2)['status'] == 'approved' and aging_row(page(payroll, 'ap', VENDOR)[1], VENDOR)['open'] == 350)
check('A payment cannot be applied to a bill not yet approved', record(payroll, '250', 'V-PAY-3', {b3: '250'}, 'ap', VENDOR)[0] == 422)
post(admin, {'do': 'credit', 'invoice_id': b2, 'amount': '50', 'reason': 'Damaged delivery', 'issued_on': bj['today']}, 'ap', VENDOR)
check('A vendor credit of 50 leaves 300', aging_row(page(payroll, 'ap', VENDOR)[1], VENDOR)['open'] == 300)
credit = re.search(r'data-credit="(\d+)"', page(admin, 'ap', VENDOR)[1]).group(1)
post(admin, {'do': 'reverse_credit', 'credit_id': credit, 'reason': 'Vendor withdrew the credit'}, 'ap', VENDOR)
check('Reversed, the bill is back to 350', aging_row(page(payroll, 'ap', VENDOR)[1], VENDOR)['open'] == 350)

# ── the QuickBooks entries for all of it ─────────────────────────────────
admin.get('/accounting')
admin.post('/accounting', {'do': 'payroll', 'payroll': '0'})
payroll.get('/accounting')
status, _ = payroll.post('/accounting', {'do': 'export', 'through': bj['today']})
lines = probe()['lines']
by = lambda src: sorted((l['account_key'], l['debit'], l['credit']) for l in lines if l['source'] == src)
check('The export is made', status == 200, status)
check('The bounced check: Dr bank Cr A/R 2,600, then the same the other way',
      by('ar_payment:%d:post' % p1['id']) == [('accounts_receivable', 0, 2600), ('bank', 2600, 0)] and by('ar_payment:%d:reversal' % p1['id']) == [('accounts_receivable', 2600, 0), ('bank', 0, 2600)])
check('The client credit note: Dr revenue Cr A/R 150', any(l['source'].startswith('ar_credit:') and l['account_key'] == 'revenue' and l['debit'] == 150 for l in lines))
check('The vendor payments: Dr A/P Cr bank; the vendor credit and its reversal both go out',
      by('ap_payment:%d:post' % probe()['ap'][-1]['id']) == [('accounts_payable', 250, 0), ('bank', 0, 250)]
      and any(l['source'].endswith(':reversal') and l['source'].startswith('ap_credit:') for l in lines))
check('An invoice paid through recorded payments sends no separate payment entry; one paid before still does',
      not any(l['source'] == 'client_invoice:%d:payment' % i5 for l in lines) and any(l['source'] == 'client_invoice:%d:payment' % i4 for l in lines))
journals = {}
for l in lines:
    journals.setdefault((l['batch_id'], l['journal_no']), [0.0, 0.0])
    journals[(l['batch_id'], l['journal_no'])][0] += l['debit']; journals[(l['batch_id'], l['journal_no'])][1] += l['credit']
check('Every one of those journals balances', all(abs(d - c) < 0.005 for d, c in journals.values()))

status, body = payroll.get('/balances?lang=fr')
check('Balances is translated into French', 'Ce que les clients doivent' in body and 'Ancienneté' in body)
status, body = payroll.get('/balances?side=ap&lang=es')
check('Balances is translated into Spanish', 'Lo que debemos a proveedores' in body)
payroll.get('/balances?lang=en')

json.dump(results, open('work/bal-http-results.json', 'w'), indent=1)
