"""P3-M03 through the screens: records post themselves to the ledger once,
on their own date or, for a month already closed, on the day they arrive;
the QuickBooks export is drawn from the ledger; manual journals drafted by
one person and posted by another, refused when wrong, reversed by a
correcting journal; the chart of accounts; the database refusing changes
to posted journals and the hash chain showing one made around it; records
changed after posting; several processes posting at once; roles and
translations.
"""
import datetime, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/ledger.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []
TODAY = datetime.date.today().isoformat()


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
    out = subprocess.run([php, 'work/ledger_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def act(client, values, path='/ledger'):
    client.get(path)
    return client.post(path, values)


def journal(source=None, id=None):
    return next((j for j in probe()['journals'] if (source is not None and j['source'] == source) or (id is not None and j['id'] == id)), None)


def page_attr(body, ident, attr):
    m = re.search(r'id="%s"[^>]*?%s="([^"]*)"' % (ident, attr), body)
    return m[1] if m else None


def row_attr(body, account, attr, section):
    part = body.split('id="%s"' % section, 1)[1].split('<div class="card', 1)[0]
    m = re.search(r'data-account="%s"[^>]*?%s="([^"]*)"' % (re.escape(account), attr), part)
    return m[1] if m else None


def lines(values):
    out = {}
    for i, (account, debit, credit) in enumerate(values):
        out['account[%d]' % i] = account
        out['debit[%d]' % i] = debit
        out['credit[%d]' % i] = credit
    return out


def draft(client, date, memo, values, extra=None):
    return act(client, {'do': 'draft', 'date': date, 'memo': memo, **lines(values), **(extra or {})})


admin = Client(); check('P3-M03 admin login', admin.login('gl-admin@test.invalid')[0] == 200)
payroll = Client(); check('P3-M03 payroll login', payroll.login('gl-payroll@test.invalid')[0] == 200)
payroll2 = Client(); check('P3-M03 second payroll login', payroll2.login('gl-payroll2@test.invalid')[0] == 200)
recruiter = Client(); check('P3-M03 recruiter login', recruiter.login('gl-recruiter@test.invalid')[0] == 200)
PAYROLL_ID, PAYROLL2_ID = fx['ids']['payroll'], fx['ids']['payroll2']

check('A recruiter cannot open the ledger', recruiter.get('/ledger')[0] == 403)
check('nor draft a journal', act(recruiter, {'do': 'draft'})[0] == 403)

# ── the records post themselves ──────────────────────────────────────────
status, body = payroll.get('/ledger')
check('Payroll opens the ledger: the records are posted, and the chain is intact', status == 200 and int(page_attr(body, 'ledger-integrity', 'data-synced')) > 0
      and page_attr(body, 'ledger-integrity', 'data-ok') == '1' and page_attr(body, 'ledger-integrity', 'data-triggers') == '5')
FEB = 'client_invoice:%d:issue' % fx['inv_feb']
j = journal(FEB)
check('The February invoice: one journal, Dr A/R 4,000 Cr revenue, for the client and the project, on 3 February',
      j and j['status'] == 'posted' and j['kind'] == 'source' and j['doc_date'] == j['posted_on'] == '2025-02-03' and j['export_key'] == FEB
      and sorted((l['account'], l['debit'], l['credit']) for l in j['lines']) == [('accounts_receivable', 4000, 0), ('revenue', 0, 4000)]
      and all(l['party'] == 'Ledger test client' and l['class'] == 'P3-M03 project' for l in j['lines']), j)
b = journal('vendor_invoice:%d:bill' % fx['bill'])
check('The hotel bill: Dr hotels 600 Cr A/P', b and sorted((l['account'], l['debit'], l['credit']) for l in b['lines']) == [('accounts_payable', 0, 600), ('hotels_expense', 600, 0)])
c = journal('expense_claim:%d:paid' % fx['claim'])
check('The paid claim: Dr other costs 50 Cr bank', c and sorted((l['account'], l['debit'], l['credit']) for l in c['lines']) == [('bank', 0, 50), ('other_expense', 50, 0)])
old = journal('client_invoice:%d:issue' % fx['inv_old'])
check('An invoice dated in November 2022, closed before it reached the ledger, counts today and keeps its own date',
      old and old['doc_date'] == '2022-11-20' and old['posted_on'] == TODAY and 'a month already closed' in body, old)
p = probe()
check('Payroll periods are in the ledger even while ADP posts them to QuickBooks', any(s.startswith('payroll_run:') for s in p['per_source']))
check('Each record is in the ledger once', all(n == 1 for n in p['per_source'].values()), [s for s, n in p['per_source'].items() if n != 1])
status, body = payroll.get('/ledger')
check('Opened again, nothing new is posted', page_attr(body, 'ledger-integrity', 'data-synced') == '0' and len(probe()['journals']) == len(p['journals']))
chain = sorted(j['chain_no'] for j in p['journals'] if j['status'] == 'posted')
check('The posted journals form one unbroken chain', chain == list(range(1, len(chain) + 1)))

status, body = payroll.get('/ledger?period=2025-02')
check('February 2025\'s trial balance balances and holds the invoice', page_attr(body, 'ledger-trial', 'data-balanced') == '1'
      and float(row_attr(body, 'revenue', 'data-credit', 'ledger-trial')) >= 4000)

# ── several processes at once ────────────────────────────────────────────
claim = int(subprocess.run([php, 'work/ledger_probe.php', 'claim'], check=True, capture_output=True, text=True).stdout)
procs = [subprocess.Popen([php, 'work/ledger_probe.php', 'sync'], stdout=subprocess.PIPE, text=True) for _ in range(4)]
posted = [json.loads(pr.communicate()[0]) for pr in procs]
check('Four processes syncing at once post the new claim once between them', sum(posted) == 1 and probe()['per_source']['expense_claim:%d:paid' % claim] == 1, posted)
check('and the chain is still intact', probe('integrity')['ok'] is True)

# ── manual journals ──────────────────────────────────────────────────────
check('Payroll cannot add an account', act(payroll, {'do': 'account_add', 'number': '5200', 'label': 'Office rent', 'side': 'expense'})[0] == 403)
check('An administrator adds 5200 Office rent', act(admin, {'do': 'account_add', 'number': '5200', 'label': 'Office rent', 'side': 'expense'})[0] == 200
      and any(a['account_key'] == 'acct_5200' and a['is_system'] == 0 and a['confirmed'] == 0 for a in probe()['accounts']))
check('A number already taken is refused', act(admin, {'do': 'account_add', 'number': '5200', 'label': 'Rent again', 'side': 'expense'})[0] == 422)
check('A number that is not 4 to 6 digits is refused', act(admin, {'do': 'account_add', 'number': '52', 'label': 'Short', 'side': 'expense'})[0] == 422)
check('A name a spreadsheet would run is refused', act(admin, {'do': 'account_add', 'number': '5300', 'label': '=cmd', 'side': 'expense'})[0] == 422)

RENT = [('acct_5200', '1200', ''), ('bank', '', '1200')]
for label, args in [('An unbalanced journal is refused', (TODAY, 'Office rent', [('acct_5200', '1200', ''), ('bank', '', '1100')])),
                    ('A one-line journal is refused', (TODAY, 'Office rent', [('acct_5200', '1200', '')])),
                    ('A line with a debit and a credit is refused', (TODAY, 'Office rent', [('acct_5200', '1200', '5'), ('bank', '', '1195')])),
                    ('A negative amount is refused', (TODAY, 'Office rent', [('acct_5200', '-5', ''), ('bank', '', '-5')])),
                    ('Three decimals are refused', (TODAY, 'Office rent', [('acct_5200', '1.005', ''), ('bank', '', '1.005')])),
                    ('A journal dated tomorrow is refused', ((datetime.date.today() + datetime.timedelta(days=1)).isoformat(), 'Office rent', RENT)),
                    ('A journal with nothing said of it is refused', (TODAY, '', RENT))]:
    check(label, draft(payroll, *args)[0] == 422)
status, body = draft(payroll, TODAY, 'Client write-off', [('accounts_receivable', '', '100'), ('other_expense', '100', '')])
check('A journal on client balances is refused: they go through receivables', status == 422 and 'never by journal' in body, body[:200])
status, body = draft(payroll, '2022-11-15', 'Late rent', RENT)
check('A journal dated in a closed month is refused', status == 422 and 'The month 2022-11 is closed' in body, body[:200])

check('Payroll drafts the October office rent', draft(payroll, TODAY, 'October office rent', RENT, {'class[0]': 'P3-M03 project'})[0] == 200)
rent = [j for j in probe()['journals'] if j['kind'] == 'manual'][-1]
check('It waits as a draft by its author, out of the books', rent['status'] == 'draft' and rent['created_by'] == PAYROLL_ID and rent['chain_no'] is None and rent['total'] == 1200)
status, body = act(payroll, {'do': 'approve', 'journal_id': rent['id']})
check('Its author cannot post it', status == 422 and 'Someone other than the author' in body)
check('A recruiter cannot post it', act(recruiter, {'do': 'approve', 'journal_id': rent['id']})[0] == 403)
check('A second payroll user posts it', act(payroll2, {'do': 'approve', 'journal_id': rent['id']})[0] == 200)
rent = journal(id=rent['id'])
check('Posted: sealed in the chain, approved by the second person, with its own QuickBooks key',
      rent['status'] == 'posted' and rent['approved_by'] == PAYROLL2_ID and rent['chain_no'] and len(rent['hash']) == 64 and rent['export_key'].startswith('gl_journal:%d:' % rent['id'])
      and [(l['account'], l['debit'], l['credit'], l['class']) for l in rent['lines']] == [('acct_5200', 1200, 0, 'P3-M03 project'), ('bank', 0, 1200, None)], rent)
check('Posting it twice is refused', act(payroll2, {'do': 'approve', 'journal_id': rent['id']})[0] == 422)

check('Payroll drafts a bank fee', draft(payroll, TODAY, 'Wire fee', [('other_expense', '30', ''), ('bank', '', '30')])[0] == 200)
fee = [j for j in probe()['journals'] if j['kind'] == 'manual'][-1]
status, body = act(payroll2, {'do': 'discard', 'journal_id': fee['id']})
check('Someone else cannot discard it', status == 422)
check('Its author discards it, and it never counts', act(payroll, {'do': 'discard', 'journal_id': fee['id']})[0] == 200 and journal(id=fee['id'])['status'] == 'discarded')

# ── to QuickBooks, from the ledger ───────────────────────────────────────
status, body = payroll.get('/accounting?through=' + TODAY)
seen = set(re.findall(r'data-source="([^"]+)"', body))
check('The export waits with the manual journal, drawn from the ledger', rent['export_key'] in seen and FEB in seen)
check('but not payroll, while ADP posts it', not any(s.startswith('payroll_run:') for s in seen))
check('The new account, not yet mapped, blocks the export', 'not confirmed against the QuickBooks chart' in body and 'Office rent' in body)
mapping = {'do': 'mapping', 'qb_account[acct_5200]': 'Rent or Lease'}
for a in probe()['accounts']:
    mapping['confirmed[%s]' % a['account_key']] = '1'
check('An administrator maps it', act(admin, mapping, '/accounting')[0] == 200)
check('Payroll exports', act(payroll, {'do': 'export', 'through': TODAY}, '/accounting')[0] == 200)
active = {s['active_key'] for s in probe()['sources'] if s['active_key']}
check('The batch holds the manual journal and the records, each once', rent['export_key'] in active and FEB in active)
status, body = payroll.get('/ledger')
check('Ledger and QuickBooks now agree on office rent', row_attr(body, 'acct_5200', 'data-difference', 'ledger-vs-export') == '0.00'
      and row_attr(body, 'acct_5200', 'data-ledger', 'ledger-vs-export') == '1200.00')

# ── correcting journals ──────────────────────────────────────────────────
src = journal(FEB)
status, body = act(payroll, {'do': 'reverse', 'journal_id': src['id'], 'date': TODAY, 'reason': 'Wrong'})
check('A journal posted from a record is not reversed here: the record is corrected', status == 422 and 'corrected on the record' in body)
check('A reversal without a reason is refused', act(payroll, {'do': 'reverse', 'journal_id': rent['id'], 'date': TODAY, 'reason': ''})[0] == 422)
check('A reversal dated before the journal is refused', act(payroll, {'do': 'reverse', 'journal_id': rent['id'], 'date': '2020-01-01', 'reason': 'Booked twice'})[0] == 422)
check('Payroll reverses the rent: booked twice', act(payroll, {'do': 'reverse', 'journal_id': rent['id'], 'date': TODAY, 'reason': 'Booked twice'})[0] == 200)
rent = journal(id=rent['id'])
rev = journal(id=rent['reversed_by_id'])
check('The correcting journal swaps every line, and the original stands, marked',
      rev and rev['kind'] == 'reversal' and rev['reverses_id'] == rent['id'] and rev['status'] == 'posted'
      and [(l['account'], l['debit'], l['credit']) for l in rev['lines']] == [('acct_5200', 0, 1200), ('bank', 1200, 0)] and rent['status'] == 'posted', rev)
check('Reversing it twice is refused', act(payroll, {'do': 'reverse', 'journal_id': rent['id'], 'date': TODAY, 'reason': 'Again'})[0] == 422)
status, body = payroll.get('/ledger?account=acct_5200&from=%s&to=%s' % (TODAY, TODAY))
check('Office rent: the journal and its reversal, back to zero', page_attr(body, 'account-ledger', 'data-closing') == '0.00'
      and len(re.findall(r'<tr data-journal=', body.split('id="account-ledger"', 1)[1].split('id="ledger-vs-export"', 1)[0])) == 2)
check('The reversal waits to go to QuickBooks', row_attr(body, 'acct_5200', 'data-difference', 'ledger-vs-export') == '-1200.00')

# ── the chart ────────────────────────────────────────────────────────────
check('Payroll cannot switch an account off', act(payroll, {'do': 'account_active', 'account': 'acct_5200', 'active': '0'})[0] == 403)
check('An account the records post to stays on', act(admin, {'do': 'account_active', 'account': 'bank', 'active': '0'})[0] == 422)
check('An administrator switches office rent off', act(admin, {'do': 'account_active', 'account': 'acct_5200', 'active': '0'})[0] == 200)
status, body = payroll.get('/ledger')
form = body.split('id="journal-form"', 1)[1].split('</form>', 1)[0]
check('It is no longer offered, and a journal on it is refused', 'value="acct_5200"' not in form and draft(payroll, TODAY, 'Rent', RENT)[0] == 422)
act(admin, {'do': 'account_active', 'account': 'acct_5200', 'active': '1'})

# ── nothing changes a posted journal ─────────────────────────────────────
r = probe('try_update', rent['id'])
check('The database refuses to change a posted line', r['refused'] and 'A posted journal cannot be changed' in r['message'], r)
r = probe('try_delete', rent['id'])
check('and to delete a posted journal', r['refused'] and 'cannot be deleted' in r['message'], r)
probe('tamper', rent['id'])
status, body = payroll.get('/ledger')
check('A line changed around the triggers breaks the chain at that journal', page_attr(body, 'ledger-integrity', 'data-ok') == '0'
      and page_attr(body, 'ledger-integrity', 'data-broken') == str(rent['id']) and 'no longer matches its fingerprint' in body)
check('and the missing trigger is reported', page_attr(body, 'ledger-integrity', 'data-triggers') == '4' and 'triggers that refuse changes' in body)
probe('untamper', rent['id'])
check('Put back, the chain is intact again', probe('integrity')['ok'] is True)
up = subprocess.run([php, 'work/test-app/install/upgrade.php'], capture_output=True, text=True)
check('The upgrade puts the trigger back, silently', up.returncode == 0 and 'Ledger:' not in up.stdout and probe()['triggers'] == 5, up.stdout[-400:])

# ── a record changed after posting ───────────────────────────────────────
probe('drift', fx['inv_feb'])
status, body = payroll.get('/ledger')
check('An invoice changed after posting is shown, and its journal stands', 'data-drift="%s"' % FEB in body
      and sorted(l['debit'] for l in journal(FEB)['lines']) == [0, 4000])
probe('undrift', fx['inv_feb'])
check('Put back, it is no longer shown', 'data-drift="%s"' % FEB not in payroll.get('/ledger')[1])

status, body = payroll.get('/ledger?lang=fr')
check('The ledger is translated into French', 'Grand livre' in body and 'Balance de vérification' in body)
status, body = payroll.get('/ledger?lang=es')
check('and into Spanish', 'Libro mayor' in body and 'Balance de comprobación' in body)
payroll.get('/ledger?lang=en')

json.dump(results, open('work/gl-http-results.json', 'w'), indent=1)
