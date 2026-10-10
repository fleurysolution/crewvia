"""P3-M05 through the screens: a month closes only when it has ended and
nothing in it waits to be exported; once closed, nothing new is dated in it
(payments, credit notes, bill approvals, exports, reversals); the trial
balance of what was exported; reopening; roles and translations.
"""
import datetime, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
pj = json.load(open('work/per.json'))
php = os.environ.get('PHP_BIN', 'php')
MARCH = '2023-03'
TODAY = datetime.date.today().isoformat()
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
    out = subprocess.run([php, 'work/periods_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def act(client, path, values, get=None):
    client.get(get or path)
    return client.post(path, values)


def period(client, values):
    return act(client, '/periods', {'period': MARCH, **values}, '/periods?period=' + MARCH)


def tb(body):
    rows = {k: (float(dr), float(cr), float(cl)) for k, cl, dr, cr in re.findall(r'data-account="(\w+)" data-closing="([\d.-]+)" data-debit="([\d.-]+)" data-credit="([\d.-]+)"', body)}
    balanced = re.search(r'data-balanced="(\d)"', body)
    return rows, balanced.group(1) if balanced else None


admin = Client(); check('P3-M05 admin login', admin.login('per-admin@test.invalid')[0] == 200)
payroll = Client(); check('P3-M05 payroll login', payroll.login('per-payroll@test.invalid')[0] == 200)
recruiter = Client(); check('P3-M05 recruiter login', recruiter.login('per-recruiter@test.invalid')[0] == 200)
check('A recruiter cannot see financial periods', recruiter.get('/periods')[0] == 403)

# ── closing ──────────────────────────────────────────────────────────────
status, body = payroll.get('/periods?period=' + MARCH)
check('March 2023 has the invoice and the approved bill waiting to be exported', status == 200 and 'data-pending="2"' in body)
check('Payroll cannot close a month', period(payroll, {'do': 'close', 'reason': 'Reconciled'})[0] == 403)
check('The current month cannot be closed', act(admin, '/periods', {'do': 'close', 'period': TODAY[:7], 'reason': 'Too early'})[0] == 422)
status, body = period(admin, {'do': 'close', 'reason': 'Reconciled with QuickBooks'})
check('A month with entries still to export cannot be closed', status == 422 and '2 entries dated in 2023-03 are still waiting' in body, body[:200])
status, _ = act(payroll, '/accounting', {'do': 'export', 'through': '2023-03-31'})
check('Payroll exports up to the end of March 2023', status == 200 and len(probe()['march']) == 4, probe()['march'])
march_batch = probe()['march'][0]['batch_id']

status, body = payroll.get('/periods?period=' + MARCH)
rows, balanced = tb(body)
check('The trial balance for March: A/R 1,000 Dr, revenue 1,000 Cr, other costs 400 Dr, A/P 400 Cr',
      rows.get('accounts_receivable', (0, 0, 0))[0] == 1000 and rows.get('revenue', (0, 0, 0))[1] == 1000
      and rows.get('other_expense', (0, 0, 0))[0] == 400 and rows.get('accounts_payable', (0, 0, 0))[1] == 400, rows)
check('Its debits equal its credits: 1,400 each', balanced == '1' and '$1,400.00' in re.search(r'id="tb-debit">([^<]+)', body).group(1)
      and '$1,400.00' in re.search(r'id="tb-credit">([^<]+)', body).group(1))
check('Nothing is waiting now', 'data-pending="0"' in body)
check('Closing without a reason is refused', period(admin, {'do': 'close', 'reason': ''})[0] == 422)
check('An administrator closes March 2023', period(admin, {'do': 'close', 'reason': 'Reconciled with QuickBooks'})[0] == 200
      and {'period': MARCH, 'status': 'closed'} in probe()['periods'])
check('Closing it twice is refused', period(admin, {'do': 'close', 'reason': 'Again'})[0] == 422)

# ── nothing new dated in a closed month ──────────────────────────────────
party = '/balances?side=ar&party=%d' % pj['client']
status, body = act(payroll, '/balances', {'side': 'ar', 'party': pj['client'], 'do': 'record', 'received_on': '2023-03-20', 'amount': '1000', 'method': 'check',
                                          'reference': 'PER-CHK-1', 'apply[%d]' % pj['invoice']: '1000'}, party)
check('A payment dated in the closed month is refused', status == 422 and 'The month 2023-03 is closed' in body, body[:200])
check('A credit note dated in it is refused', act(admin, '/balances', {'side': 'ar', 'party': pj['client'], 'do': 'credit', 'invoice_id': pj['invoice'], 'amount': '10',
                                                                        'reason': 'Late correction', 'issued_on': '2023-03-25'}, party)[0] == 422)
admin.post('/select-project', {'job_id': pj['job']})
status, _ = act(admin, '/accounts-payable', {'do': 'approve', 'invoice_id': pj['late']})
check('A bill dated in it cannot be approved', status == 422 and next(b for b in probe()['bills'] if b['id'] == str(pj['late']) or int(b['id']) == pj['late'])['status'] == 'received')
status, _ = act(payroll, '/balances', {'side': 'ar', 'party': pj['client'], 'do': 'record', 'received_on': TODAY, 'amount': '1000', 'method': 'check',
                                       'reference': 'PER-CHK-2', 'apply[%d]' % pj['invoice']: '1000'}, party)
check('The same payment dated today, an open month, is recorded', status == 200)
pay = probe()['ar'][-1]
probe('backdate', pay['id'])
status, body = payroll.get('/accounting')
slipped = next(g for g in probe()['gl'] if g['source'] == 'ar_payment:%d:post' % int(pay['id']))
check('A record that slipped into the closed month reaches the ledger in the open month, never in March (P3-M03)',
      slipped['doc_date'] == '2023-03-28' and slipped['posted_on'] == TODAY and 'a closed month' not in body, slipped)
probe('restore', pay['id'])
check('It exports, dated today', act(payroll, '/accounting', {'do': 'export', 'through': TODAY})[0] == 200
      and not [l for l in probe()['march'] if l['source'] == 'ar_payment:%d:post' % int(pay['id'])])
check('Reversing the March export dated in March is refused', act(payroll, '/accounting', {'do': 'reverse', 'batch_id': march_batch, 'date': '2023-03-31', 'reason': 'Wrong company'})[0] == 422)
check('Reversed today instead, it is accepted', act(payroll, '/accounting', {'do': 'reverse', 'batch_id': march_batch, 'date': TODAY, 'reason': 'Wrong company'})[0] == 200)
rows_after, _ = tb(payroll.get('/periods?period=' + MARCH)[1])
check('March\'s trial balance is unchanged: the reversal is dated in an open month', rows_after == rows, (rows, rows_after))
rows_now, balanced_now = tb(payroll.get('/periods?period=' + TODAY[:7])[1])
check('This month\'s trial balance holds the reversal and still balances', balanced_now == '1')

# ── a trial balance that does not balance says so ────────────────────────
probe('unbalance')
check('A lone line makes February 2023 say debits and credits differ', tb(payroll.get('/periods?period=2023-02')[1])[1] == '0')
probe('rebalance')

# ── reopening ────────────────────────────────────────────────────────────
check('Reopening without a reason is refused', period(admin, {'do': 'reopen', 'reason': ''})[0] == 422)
check('An administrator reopens March 2023', period(admin, {'do': 'reopen', 'reason': 'Vendor sent a late bill'})[0] == 200
      and {'period': MARCH, 'status': 'open'} in probe()['periods'])
check('The late bill can now be approved', act(admin, '/accounts-payable', {'do': 'approve', 'invoice_id': pj['late']})[0] == 200
      and next(b for b in probe()['bills'] if int(b['id']) == pj['late'])['status'] == 'approved')
events = [(e['action'], e['reason']) for e in probe()['events'] if e['period'] == MARCH]
check('Both the close and the reopen are kept, with their reasons', events == [('close', 'Reconciled with QuickBooks'), ('reopen', 'Vendor sent a late bill')], events)

status, body = admin.get('/periods?lang=fr')
check('Financial periods is translated into French', 'Périodes comptables' in body and 'Balance de vérification' in body)
status, body = admin.get('/periods?lang=es')
check('Financial periods is translated into Spanish', 'Periodos contables' in body)
admin.get('/periods?lang=en')

json.dump(results, open('work/per-http-results.json', 'w'), indent=1)
