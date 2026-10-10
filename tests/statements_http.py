"""P3-M05 statements through the screens, on records dated 2018 and 2019
that no other test uses, so every figure is exact: the income statement
(whole and for one project), the balance sheet balancing with earlier and
current earnings, the direct cash flow explaining the bank to the cent,
budgets set, changed and kept in history, variance favourable and not,
printing on Letter with the brand mark, roles and translations.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/statements.json'))
php = os.environ.get('PHP_BIN', 'php')
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


def probe():
    return json.loads(subprocess.run([php, 'work/statements_probe.php'], check=True, capture_output=True, text=True).stdout)


def act(client, path, values):
    client.get(path)
    return client.post(path, values)


def attr(body, pattern, name='data-amount'):
    m = re.search(r'%s[^>]*?%s="([^"]*)"' % (pattern, name), body)
    return float(m[1]) if m and re.fullmatch(r'-?[\d.]+', m[1]) else (m[1] if m else None)


def line(body, key):
    return attr(body, r'data-line="%s"' % re.escape(key))


def cash(body, label):
    return attr(body, r'data-cash="%s"' % re.escape(label))


def total(body, ident):
    return attr(body, r'id="%s"' % ident)


def journal(client, poster, date, memo, values):
    form = {'do': 'draft', 'date': date, 'memo': memo}
    for i, (account, debit, credit) in enumerate(values):
        form.update({'account[%d]' % i: account, 'debit[%d]' % i: debit, 'credit[%d]' % i: credit})
    client.get('/ledger')
    status, _ = client.post('/ledger', form)
    out = subprocess.run([php, '-r', "require 'work/test-app/app/bootstrap.php'; echo val(\"SELECT MAX(id) FROM gl_journals WHERE kind = 'manual'\");"], capture_output=True, text=True).stdout
    return status == 200 and act(poster, '/ledger', {'do': 'approve', 'journal_id': out.strip()})[0] == 200


admin = Client(); check('P3-M05 statements admin login', admin.login('st-admin@test.invalid')[0] == 200)
payroll = Client(); check('P3-M05 statements payroll login', payroll.login('st-payroll@test.invalid')[0] == 200)
recruiter = Client(); check('P3-M05 statements recruiter login', recruiter.login('st-recruiter@test.invalid')[0] == 200)

check('A recruiter cannot open the statements', recruiter.get('/statements')[0] == 403)
check('nor set a budget', act(recruiter, '/statements', {'do': 'budget'})[0] == 403)

# What Crewvia never records, typed as journals: drafted by payroll, posted by an administrator.
check('An administrator adds 5250 Office lease', act(admin, '/ledger', {'do': 'account_add', 'number': '5250', 'label': 'Office lease', 'side': 'expense'})[0] == 200)
check('The owner puts 5,000 into the business on 2 January 2019', journal(payroll, admin, '2019-01-02', 'Owner contribution', [('bank', '5000', ''), ('owner_equity', '', '5000')]))
check('and the March 2019 lease of 1,000 is paid', journal(payroll, admin, '2019-03-01', 'March office lease', [('acct_5250', '1000', ''), ('bank', '', '1000')]))

# ── income statement ─────────────────────────────────────────────────────
status, body = payroll.get('/statements?view=income&from=2019-03-01&to=2019-03-31')
check('Payroll opens the income statement for March 2019', status == 200 and 'id="income-statement"' in body)
check('Revenue 10,500 from both projects', line(body, 'revenue') == 10500 and total(body, 'income-total') == 10500, (line(body, 'revenue'), total(body, 'income-total')))
check('Costs: hotels 2,000, office lease 1,000, other 300, 3,300 in all', line(body, 'hotels_expense') == 2000 and line(body, 'acct_5250') == 1000
      and line(body, 'other_expense') == 300 and total(body, 'expense-total') == 3300)
check('Net income 7,200', attr(body, r'id="income-statement"', 'data-net') == 7200)
status, body = payroll.get('/statements?view=income&from=2019-03-01&to=2019-03-31&class=' + urllib.parse.quote('P3-M05 project'))
check('For the one project: revenue 10,000, its own costs 2,300, net 7,700; the lease, booked to no project, is not in it',
      line(body, 'revenue') == 10000 and total(body, 'expense-total') == 2300 and line(body, 'acct_5250') is None
      and attr(body, r'id="income-statement"', 'data-net') == 7700 and 'Costs not booked to a project are not in it' in body)
status, body = payroll.get('/statements?view=income&from=2019-03-01&to=2019-03-31&class=' + urllib.parse.quote('Not a project'))
check('A project that does not exist shows every project', line(body, 'revenue') == 10500)

# ── balance sheet ────────────────────────────────────────────────────────
status, body = payroll.get('/statements?view=balance&asof=2019-03-31')
check('Balance sheet at 31 March 2019: bank 10,200 and receivables 3,500, 13,700 of assets',
      line(body, 'bank') == 10200 and line(body, 'accounts_receivable') == 3500 and total(body, 'assets-total') == 13700, (line(body, 'bank'), line(body, 'accounts_receivable')))
check('Payables 500, owner equity 5,000', line(body, 'accounts_payable') == 500 and total(body, 'liabilities-total') == 500 and line(body, 'owner_equity') == 5000)
check('Earnings: 1,000 from 2018, 7,200 this year', line(body, 'prior_earnings') == 1000 and line(body, 'current_earnings') == 7200)
check('Liabilities and equity 13,700: it balances', total(body, 'le-total') == 13700 and attr(body, r'id="balance-sheet"', 'data-balanced') == 1
      and 'Assets equal liabilities and equity.' in body)
status, body = payroll.get('/statements?view=balance&asof=2018-12-31')
check('At the end of 2018: the 1,000 receivable against 2018 earnings', total(body, 'assets-total') == 1000 and line(body, 'current_earnings') == 1000
      and line(body, 'prior_earnings') == 0 and attr(body, r'id="balance-sheet"', 'data-balanced') == 1)

# ── cash flow ────────────────────────────────────────────────────────────
status, body = payroll.get('/statements?view=cash&from=2019-01-01&to=2019-03-31')
check('Cash flow, first quarter 2019: from nothing to 10,200', attr(body, r'id="cash-flow"', 'data-opening') == 0 and attr(body, r'id="cash-flow"', 'data-closing') == 10200)
check('Operating: 8,000 received from clients, 1,500 paid to vendors, 1,300 of costs paid directly, 5,200 net',
      cash(body, 'Received from clients') == 8000 and cash(body, 'Paid to vendors') == -1500 and cash(body, 'Costs paid directly') == -1300
      and total(body, 'cash-operating') == 5200)
check('Financing: the owner\'s 5,000; investing: nothing', cash(body, 'Owner contributions and draws') == 5000 and total(body, 'cash-financing') == 5000
      and total(body, 'cash-investing') == 0)
check('The movements explain the bank to the cent', attr(body, r'id="cash-flow"', 'data-reconciled') == 1 and attr(body, r'id="cash-flow"', 'data-change') == 10200)
status, body = payroll.get('/statements?view=cash&from=2019-03-21&to=2019-03-31')
check('Late March alone: starts at 11,700 after the client paid, ends at 10,200 after the hotel was paid',
      attr(body, r'id="cash-flow"', 'data-opening') == 11700 and cash(body, 'Paid to vendors') == -1500 and attr(body, r'id="cash-flow"', 'data-closing') == 10200)

# ── printing ─────────────────────────────────────────────────────────────
status, body = payroll.get('/statements?view=balance&asof=2019-03-31&print=1')
check('The balance sheet prints on Letter, without the browser stamp, with the brand mark as an image',
      status == 200 and '@page { size: Letter; margin: 0; }' in body and '<img src="/assets/app-icon.svg"' in body and 'background-image' not in body)
check('with the same figures, and says it is not audited', total(body, 'le-total') == 13700 and 'Not an audited statement' in body)
check('An income statement and a cash flow print too', 'id="income-statement"' in payroll.get('/statements?view=income&from=2019-03-01&to=2019-03-31&print=1')[1]
      and 'id="cash-flow"' in payroll.get('/statements?view=cash&from=2019-01-01&to=2019-03-31&print=1')[1])

# ── budgets ──────────────────────────────────────────────────────────────
def budget(client, account, frm, to, amount, note=''):
    return act(client, '/statements?view=budget', {'do': 'budget', 'account': account, 'from': frm, 'to': to, 'amount': amount, 'note': note})


check('A budget on an asset is refused', budget(payroll, 'bank', '2019-03', '2019-03', '100')[0] == 422)
check('A negative budget is refused', budget(payroll, 'revenue', '2019-03', '2019-03', '-5')[0] == 422)
check('A range that ends before it starts is refused', budget(payroll, 'revenue', '2019-03', '2019-01', '100')[0] == 422)
check('More than 24 months at once is refused', budget(payroll, 'revenue', '2019-01', '2021-01', '100')[0] == 422)
check('Payroll budgets 12,000 of revenue for March 2019', budget(payroll, 'revenue', '2019-03', '2019-03', '12000', 'Plan')[0] == 200)
check('1,500 of hotels for March', budget(payroll, 'hotels_expense', '2019-03', '2019-03', '1500')[0] == 200)
check('1,000 a month of office lease for the quarter', budget(payroll, 'acct_5250', '2019-01', '2019-03', '1000')[0] == 200
      and [b['period'] for b in probe()['budgets'] if b['account_key'] == 'acct_5250'] == ['2019-01', '2019-02', '2019-03'])
check('An administrator revises revenue to 11,000', budget(admin, 'revenue', '2019-03', '2019-03', '11000', 'Revised after the first week')[0] == 200)
ev = [e for e in probe()['events'] if e['account_key'] == 'revenue']
check('Both versions are kept, with the note', [(e['old_amount'], e['new_amount']) for e in ev] == [('', 12000), (12000, 11000)] or
      [(e['old_amount'], e['new_amount']) for e in ev] == [(None, 12000), (12000, 11000)], ev)
budget(admin, 'revenue', '2019-03', '2019-03', '11000')
check('Saving the same amount again changes nothing', len([e for e in probe()['events'] if e['account_key'] == 'revenue']) == 2)

status, body = payroll.get('/statements?view=budget&from=2019-01&to=2019-03')
row = lambda k, a: attr(body, r'data-account="%s"' % k, 'data-' + a)
check('Revenue: 11,000 budgeted, 10,500 earned, 500 short', row('revenue', 'budget') == 11000 and row('revenue', 'actual') == 10500 and row('revenue', 'variance') == -500)
check('Hotels: 1,500 budgeted, 2,000 spent, 500 over', row('hotels_expense', 'budget') == 1500 and row('hotels_expense', 'actual') == 2000 and row('hotels_expense', 'variance') == -500)
check('Office lease: 3,000 budgeted, 1,000 paid, 2,000 under: favourable', row('acct_5250', 'budget') == 3000 and row('acct_5250', 'actual') == 1000 and row('acct_5250', 'variance') == 2000
      and 'data-favourable="1"' in body.split('data-account="acct_5250"', 1)[1].split('</tr>', 1)[0])
check('Over budget is marked unfavourable', 'data-favourable="0"' in body.split('data-account="hotels_expense"', 1)[1].split('</tr>', 1)[0])
check('Other costs, with no budget, still show what was spent', row('other_expense', 'actual') == 300 and row('other_expense', 'budget') == 0)
check('The history lists the changes', 'data-budget-event="revenue:2019-03"' in body and 'Revised after the first week' in body)

status, body = payroll.get('/statements?lang=fr')
check('The statements are translated into French', 'États financiers' in body and 'Compte de résultat' in body)
status, body = payroll.get('/statements?view=balance&lang=es')
check('and into Spanish', 'Estados financieros' in body and 'Balance general' in body)
payroll.get('/statements?lang=en')

json.dump(results, open('work/st-http-results.json', 'w'), indent=1)
