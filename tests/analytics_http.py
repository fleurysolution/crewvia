"""P4-M02 through the screens, on the 2016 fixture of analytics_db.php: the
seven dashboards with exact figures, who may open which, the ledger read
after it catches up, the view logged, translations.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/analytics.json'))
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


def dash(client, query):
    status, body = client.get('/analytics?d=' + query)
    kpis = dict(re.findall(r'data-kpi="([^"]+)" data-v="([^"]*)"', body))
    tables = {}
    for tid, inner in re.findall(r'<div class="card scroll" data-table="([^"]+)">(.*?)</table>', body, re.S):
        rows = {}
        for key, cells in re.findall(r'<tr data-row="([^"]+)">(.*?)</tr>', inner, re.S):
            rows[key] = dict(re.findall(r'data-c="([^"]+)" data-v="([^"]*)"', cells))
        m = re.search(r'id="%s-totals">(.*?)</tr>' % re.escape(tid), inner, re.S)
        tables[tid] = (rows, dict(re.findall(r'data-c="([^"]+)" data-v="([^"]*)"', m[1])) if m else {})
    return status, body, kpis, tables


admin = Client(); check('P4-M02 admin login', admin.login('an-admin@test.invalid')[0] == 200)
payroll = Client(); check('P4-M02 payroll login', payroll.login('an-payroll@test.invalid')[0] == 200)
recruiter = Client(); check('P4-M02 recruiter login', recruiter.login('an-recruiter@test.invalid')[0] == 200)
hotels = Client(); check('P4-M02 hotels login', hotels.login('an-hotels@test.invalid')[0] == 200)
listed = lambda c: set(re.findall(r'data-dashboard="([^"]+)"', c.get('/analytics')[1]))

# ── who opens what ───────────────────────────────────────────────────────
check('Recruiting lists recruitment, placement and utilization', listed(recruiter) == {'recruitment', 'placement', 'utilization'}, listed(recruiter))
check('Payroll lists utilization, revenue, profitability, the financial dashboard and procurement',
      listed(payroll) == {'utilization', 'revenue', 'profitability', 'financial', 'procurement'}, listed(payroll))
check('The hotel desk lists procurement only', listed(hotels) == {'procurement'}, listed(hotels))
check('An administrator lists all seven', len(listed(admin)) == 7)
for c, k, who in [(recruiter, 'revenue', 'Recruiting'), (recruiter, 'financial', 'Recruiting'), (recruiter, 'procurement', 'Recruiting'), (payroll, 'recruitment', 'Payroll'),
                  (hotels, 'profitability', 'The hotel desk'), (hotels, 'utilization', 'The hotel desk')]:
    check('%s cannot open the %s dashboard' % (who, k), c.get('/analytics?d=' + k)[0] == 403)
check('An unknown dashboard is not found', admin.get('/analytics?d=secrets')[0] == 404)

# ── recruitment conversion ───────────────────────────────────────────────
MAR = '&from=2016-03-01&to=2016-03-31'
status, body, k, t = dash(recruiter, 'recruitment' + MAR + '&job_id=%d' % fx['hire'])
check('March on the hiring project: 6 applications, 2 accepted, 33.3 %', k['applications'] == '6' and k['accepted'] == '2' and k['conversion'] == '33.30', k)
check('1 rejected, 1 withdrawn, 2 still open, 15 days to acceptance at the median', k['rejected'] == '1' and k['withdrawn'] == '1' and k['open'] == '2' and k['median_days'] == '15.00', k)
f = t['funnel'][0]
check('The funnel: 6 applied, 5 screened, 4 interviewed, 3 offered, 2 accepted',
      [f[s]['reached'] for s in ['applied', 'screening', 'interview', 'offered', 'accepted']] == ['6', '5', '4', '3', '2'], f)
check('each stage against the one before: 83.3, 80, 75, 66.7 %', [f[s]['of_previous'] for s in ['screening', 'interview', 'offered', 'accepted']] == ['83.30', '80.00', '75.00', '66.70'])
s = t['by-source'][0]
check('By source: the job board 3 applications and 1 hire, referrals 2 and 1, an unknown source 1 and none',
      (s['source:job board']['applications'], s['source:job board']['accepted'], s['source:referral']['conversion'], s['source:unknown']['applications'])
      == ('3', '1', '50.00', '1'), s)
status, body, k, t = dash(recruiter, 'recruitment' + MAR)
check('Without the project filter the other project\'s application counts too', k['applications'] == '7' and 'project:%d' % fx['other'] in t['by-project'][0])
status, body, k, t = dash(recruiter, 'recruitment&from=2016-02-01&to=2016-02-29&job_id=%d' % fx['hire'])
check('February had none', k['applications'] == '0' and k['conversion'] == '')

# ── placement rates ──────────────────────────────────────────────────────
status, body, k, t = dash(recruiter, 'placement' + MAR + '&job_id=%d' % fx['hire'])
r = t['placement-table'][0]['j%d' % fx['hire']]
check('4 ordered, 2 on assignment at the end of March: 50 % filled, 2 still to fill', (r['ordered'], r['filled'], r['fill_rate'], r['open']) == ('4', '2', '50.00', '2'), r)
check('4 offered in March: 2 taken up, 1 fell through, 1 waiting: 66.7 % take-up', (r['offered'], r['taken'], r['fell'], r['waiting'], r['take_up']) == ('4', '2', '1', '1', '66.70'), r)
check('The headline figures agree', k['fill_rate'] == '50.00' and k['take_up'] == '66.70')

# ── utilization ──────────────────────────────────────────────────────────
status, body, k, t = dash(payroll, 'utilization' + MAR + '&job_id=%d' % fx['hire'])
rows, tot = t['utilization-table']
a, b = rows['p%d' % fx['p0']], rows['p%d' % fx['p1']]
check('The long-standing hand: 5 weeks at 40, 150 hours worked, 8 of paid leave: 75 %', (a['weeks'], a['expected'], a['worked'], a['leave'], a['utilization'])
      == ('5', '200.00', '150.00', '8.00', '75.00'), a)
check('The new starter on a 50-hour guarantee: 3 weeks, 100 of 150 hours: 66.7 %', (b['weeks'], b['expected'], b['worked'], b['utilization']) == ('3', '150.00', '100.00', '66.70'), b)
check('Assignments not yet started, cancelled or only offered are left out', len(rows) == 2 and tot['utilization'] == '71.40' and k['utilization'] == '71.40', tot)
check('The available person with no assignment is on the bench', 'c%d' % fx['bench'] in t['bench'][0])
check('Recruiting opens utilization too', recruiter.get('/analytics?d=utilization' + MAR)[0] == 200)

# ── client revenue ───────────────────────────────────────────────────────
APR = '&from=2016-04-01&to=2016-04-30'
status, body, k, t = dash(payroll, 'revenue' + APR)
rows, tot = t['revenue-table']
c1, c2 = rows.get('client:Analytics client'), rows.get('client:Analytics second client')
check('April, the first client: 9,000 after the credit note, 64.3 % of revenue, 6,000 received, 3,000 open and overdue',
      c1 and (c1['revenue'], c1['share'], c1['received'], c1['open'], c1['overdue']) == ('9000.00', '64.30', '6000.00', '3000.00', '3000.00'), c1)
check('The second client: 5,000, nothing received', c2 and (c2['revenue'], c2['received'], c2['open']) == ('5000.00', '0.00', '5000.00'), c2)
check('The hotel vendor paid from the same bank is not a client', not any('Analytics inn' in key for key in rows))
check('14,000 of revenue, 8,000 receivable, 17.1 days of sales outstanding', (k['revenue'], k['received'], k['open'], k['dso'], k['top_share'])
      == ('14000.00', '6000.00', '8000.00', '17.10', '64.30'), k)

# ── project profitability ────────────────────────────────────────────────
status, body, k, t = dash(payroll, 'profitability')
p = t['profit-table'][0].get('j%d' % fx['costed'])
check('The costed project: nothing earned, 1,000 of direct cost (a claim of 400 and a bill of 600), 1,000 lost',
      p and (p['revenue'], p['direct'], p['gross'], p['net'], p['gross_pct']) == ('0.00', '1000.00', '-1000.00', '-1000.00', ''), p)
check('Its budget, 5,000 of revenue and 2,000 of cost, and the 700 van ordered and not billed', p['budget_revenue'] == '5000.00' and p['budget_cost'] == '2000.00' and p['committed'] == '700.00', p)
check('It counts among the projects losing money', int(k['losing']) >= 1)

# ── financial dashboard ──────────────────────────────────────────────────
status, body, k, t = dash(payroll, 'financial&asof=2016-04-30')
check('At 30 April 2016: 4,100 of cash (6,000 in, 1,500 to the hotel, 400 for a claim)', k['cash'] == '4100.00', k)
check('April: 14,000 of revenue, 12,000 net; the year: 11,000 net after February\'s 1,000 of costs',
      (k['revenue_mtd'], k['net_mtd'], k['revenue_ytd'], k['net_ytd']) == ('14000.00', '12000.00', '14000.00', '11000.00'), k)
months, mt = t['months']
check('Twelve months, February to April as recorded', len(months) == 12 and months['m2016-02']['net'] == '-1000.00' and months['m2016-03']['cash'] == '-400.00'
      and months['m2016-04']['revenue'] == '14000.00' and months['m2016-04']['cash'] == '4100.00', months.get('m2016-04'))
check('Receivables and payables open today are shown', all(x in k for x in ['ar', 'ar_late', 'ap', 'ap_late']) and float(k['ar']) >= 8000)

# ── procurement ──────────────────────────────────────────────────────────
MAY = '&from=2016-05-01&to=2016-05-31&job_id=%d' % fx['buying']
status, body, k, t = dash(hotels, 'procurement' + MAY)
check('May on the buying project: 1 request waiting, 1 order of 250 waiting for approval', (k['requested'], k['awaiting'], k['awaiting_value']) == ('1', '1', '250.00'), k)
check('2 orders approved for 1,300, in 3 days on average', (k['approved'], k['approved_value'], k['approval_days']) == ('2', '1300.00', '3.00'), k)
check('4 of 5 units delivered, 1 rejected, 900 billed, 1 bill on hold (over its order)', (k['delivered'], k['rejected'], k['billed'], k['held']) == ('80.00', '1.00', '900.00', '1'), k)
cat = t['by-category'][0]
check('By category: vehicles 1,000 half delivered, safety equipment 300 billed 500', (cat['category:vehicle']['value'], cat['category:vehicle']['delivered'],
      cat['category:safety_equipment']['billed']) == ('1000.00', '50.00', '500.00'), cat)
check('By vendor: Wheels Co and Gear Co', set(t['by-vendor'][0]) == {'vendor_name:Wheels Co', 'vendor_name:Gear Co'})
check('Payroll sees the same', dash(payroll, 'procurement' + MAY)[2]['approved_value'] == '1300.00')

log = json.loads(subprocess.run([php, 'work/analytics_probe.php', str(fx['ids']['payroll'])], check=True, capture_output=True, text=True).stdout)
check('Each dashboard payroll opened is logged with its filters', any(r['detail'].startswith('revenue from=2016-04-01') for r in log)
      and any(r['detail'].startswith('financial asof=2016-04-30') for r in log), log[-4:])

status, body = payroll.get('/analytics?lang=fr')
check('Dashboards are translated into French', 'Tableaux de bord' in body and 'Revenus par client' in body)
status, body = recruiter.get('/analytics?d=placement&lang=es')
check('and into Spanish', 'Tasas de colocación' in body)
payroll.get('/analytics?lang=en')
recruiter.get('/analytics?lang=en')

json.dump(results, open('work/an-http-results.json', 'w'), indent=1)
