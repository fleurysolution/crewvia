"""P4-M03 through the screens: saved reports on the P4-M01 2017 fixture,
with columns, conditions, order and limit; relative periods; exports as
CSV, logged with their SHA-256, formulas neutralised; who may open,
change and export; the export policy; schedules and their refusals; the
runner delivering once per slot inside Crewvia; downloads checked for the
recipient, the role, the fingerprint and the expiry; translations.
"""
import csv, datetime, hashlib, http.cookiejar, io, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/report-builder.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []
ID = fx['ids']


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def _open(self, req):
        try:
            with self.opener.open(req) as r:
                return r.status, dict(r.headers), r.read(), r.geturl()
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read(), e.geturl()

    def _send(self, req):
        status, headers, raw, url = self._open(req)
        body = raw.decode()
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        self.url, self.headers = url, headers
        return status, body

    def get(self, path):
        return self._send(base + path)

    def post(self, path, values):
        if self.token is None:
            self.get(path)
        return self._send(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}, doseq=True).encode()))

    def raw(self, path, values=None):
        data = urllib.parse.urlencode({'_csrf': self.token, **values}, doseq=True).encode() if values is not None else None
        return self._open(urllib.request.Request(base + path, data))

    def login(self, email):
        self.get('/login')
        status, body = self.post('/login', {'email': email, 'password': 'TestPassword123!'})
        return (status if '<title>Sign in' not in body else 401), body


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:600] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    out = subprocess.run([php, 'work/report_builder_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def runner():
    return subprocess.run([php, 'work/test-app/install/run-report-schedules.php'], check=True, capture_output=True, text=True).stdout


def table(body):
    rows = {}
    for key, cells in re.findall(r'<tr data-row="([^"]+)">(.*?)</tr>', body, re.S):
        rows[key] = dict(re.findall(r'data-c="([^"]+)" data-v="([^"]*)"', cells))
    m = re.search(r'id="report-totals">(.*?)</tr>', body, re.S)
    return rows, dict(re.findall(r'data-c="([^"]+)" data-v="([^"]*)"', m[1])) if m else {}, re.findall(r'<tr data-row="([^"]+)">', body)


def save(client, values, definition=0):
    client.get('/report-builder?new=1')
    base_values = {'do': 'save', 'definition_id': definition, 'period': 'fixed', 'job_id': 0, 'row_limit': 1000, 'sort_dir': 'asc'}
    return client.post('/report-builder', {**base_values, **values})


def last_definition():
    return probe()['definitions'][-1]


admin = Client(); check('P4-M03 admin login', admin.login('rb-admin@test.invalid')[0] == 200)
payroll = Client(); check('P4-M03 payroll login', payroll.login('rb-payroll@test.invalid')[0] == 200)
payroll2 = Client(); check('P4-M03 second payroll login', payroll2.login('rb-payroll2@test.invalid')[0] == 200)
recruiter = Client(); check('P4-M03 recruiter login', recruiter.login('rb-recruiter@test.invalid')[0] == 200)
hotels = Client(); check('P4-M03 hotels login', hotels.login('rb-hotels@test.invalid')[0] == 200)

check('The hotel desk has no report builder', hotels.get('/report-builder')[0] == 403)
check('Recruiting cannot build on the payroll report it may not read', save(recruiter, {'name': 'Peek', 'source': 'payroll', 'columns[]': ['week']})[0] == 422)
check('A report with no column is refused', save(payroll, {'name': 'Nothing', 'source': 'payroll'})[0] == 422)
check('A condition comparing a number with text is refused', save(payroll, {'name': 'Bad', 'source': 'payroll', 'columns[]': ['week'], 'cond_column[0]': 'gross', 'cond_op[0]': 'gt', 'cond_value[0]': 'lots'})[0] == 422)
check('A limit over 5,000 rows is refused', save(payroll, {'name': 'Huge', 'source': 'payroll', 'columns[]': ['week'], 'row_limit': 9000})[0] == 422)

# ── a saved report ───────────────────────────────────────────────────────
FEB = {'from': '2017-02-01', 'to': '2017-02-28'}
status, body = save(payroll, {'name': 'February payroll by week', 'source': 'payroll', 'columns[]': ['week', 'gross', 'payable'], **FEB, 'sort_column': 'payable', 'sort_dir': 'desc'})
d1 = last_definition()
check('Payroll saves February payroll by week, kept private', status == 200 and d1['name'] == 'February payroll by week' and d1['owner_id'] == ID['payroll'] and d1['shared'] == 0, d1)
rows, tot, order = table(body)
check('It shows only the columns kept: week, gross, payable', set(next(iter(rows.values()))) == {'week', 'gross', 'payable'}, rows)
check('Ordered by payable, largest first: the week of 11 February (2,310) before the 18th (1,180)', order == ['w2017-02-11', 'w2017-02-18'] and rows['w2017-02-11']['payable'] == '2310.00', order)
check('Its totals are those of the rows kept: gross 3,400, payable 3,490', tot.get('gross') == '3400.00' and tot.get('payable') == '3490.00' and tot.get('week') == '2', tot)
save(payroll, {'name': 'February payroll by week', 'source': 'payroll', 'columns[]': ['week', 'gross', 'payable'], **FEB, 'cond_column[0]': 'payable', 'cond_op[0]': 'gt', 'cond_value[0]': '2000'}, d1['id'])
rows, tot, order = table(payroll.get('/report-builder?id=%d' % d1['id'])[1])
check('A condition, payable more than 2,000, keeps the first week alone', order == ['w2017-02-11'] and tot['payable'] == '2310.00', order)
save(payroll, {'name': 'February payroll by week', 'source': 'payroll', 'columns[]': ['week', 'gross', 'payable'], **FEB, 'sort_column': 'week', 'row_limit': 1}, d1['id'])
status, body = payroll.get('/report-builder?id=%d' % d1['id'])
rows, tot, order = table(body)
check('Limited to 1 row, earliest week first, and it says so', order == ['w2017-02-11'] and 'The first 1 of 2 rows' in body, order)
save(payroll, {'name': 'February payroll by week', 'source': 'payroll', 'columns[]': ['week', 'gross', 'payable'], **FEB, 'sort_column': 'week'}, d1['id'])

# ── export ───────────────────────────────────────────────────────────────
payroll.get('/report-builder?id=%d' % d1['id'])
status, headers, raw, _ = payroll.raw('/report-builder', {'do': 'export', 'definition_id': d1['id']})
text = raw.decode()
lines = list(csv.reader(io.StringIO(text)))
check('The export is a CSV of what the screen shows: header, then raw figures', status == 200 and headers.get('Content-Type', '').startswith('text/csv')
      and lines == [['Week ending', 'Gross', 'Payable'], ['2017-02-11', '2200.00', '2310.00'], ['2017-02-18', '1200.00', '1180.00']], lines)
sha = hashlib.sha256(raw).hexdigest()
ex = probe()['exports'][-1]
check('Its SHA-256 is sent with it and logged, with who and how many rows', headers.get('X-Content-SHA256') == sha and ex['sha256'] == sha and ex['user_id'] == ID['payroll']
      and ex['row_count'] == 2 and ex['kind'] == 'download' and ex['definition_id'] == d1['id'], ex)
status, body = save(recruiter, {'name': 'Who is on the P4-M01 project', 'source': 'employees', 'columns[]': ['name', 'status'], 'asof': '2017-02-28', 'job_id': fx['jobA']})
d2 = last_definition()
recruiter.get('/report-builder?id=%d' % d2['id'])
status, headers, raw, _ = recruiter.raw('/report-builder', {'do': 'export', 'definition_id': d2['id']})
names = [r[0] for r in csv.reader(io.StringIO(raw.decode()))]
check('A name a spreadsheet would run as a formula is neutralised in the file', "'=HYPERLINK(\"http://example.invalid\")" in names and not any(n.startswith('=') for n in names), names)

# ── who opens and changes what ───────────────────────────────────────────
check('Another payroll user cannot open a private saved report', payroll2.get('/report-builder?id=%d' % d1['id'])[0] == 404)
save(payroll, {'name': 'February payroll by week', 'source': 'payroll', 'columns[]': ['week', 'gross', 'payable'], **FEB, 'sort_column': 'week', 'shared': 1}, d1['id'])
check('Shared, the other payroll user opens it', payroll2.get('/report-builder?id=%d' % d1['id'])[0] == 200)
check('but recruiting still cannot: it may not read payroll', recruiter.get('/report-builder?id=%d' % d1['id'])[0] == 404)
check('Only its owner changes it', save(payroll2, {'name': 'Mine now', 'source': 'payroll', 'columns[]': ['week']}, d1['id'])[0] == 403)
check('nor may another archive it', payroll2.post('/report-builder', {'do': 'archive', 'definition_id': d1['id']})[0] == 403)
check('Recruiting\'s list does not show it', 'data-definition="%d"' % d1['id'] not in recruiter.get('/report-builder')[1])

# ── the export policy ────────────────────────────────────────────────────
check('Payroll cannot change who may export', payroll.post('/report-builder', {'do': 'policy', 'source': 'payroll'})[0] == 403)
check('An administrator narrows the payroll report\'s export to administrators', admin.post('/report-builder', {'do': 'policy', 'source': 'payroll'})[0] == 200)
status, body = payroll.get('/report-builder?id=%d' % d1['id'])
check('Payroll still reads it, but is not offered the export', status == 200 and 'id="no-export"' in body and 'id="export-form"' not in body)
check('and exporting anyway is refused', payroll.raw('/report-builder', {'do': 'export', 'definition_id': d1['id']})[0] == 403)
admin.get('/report-builder?id=%d' % d1['id'])
check('An administrator still exports it', admin.raw('/report-builder', {'do': 'export', 'definition_id': d1['id']})[0] == 200)
check('Back to every reader', admin.post('/report-builder', {'do': 'policy', 'source': 'payroll', 'readers': '1'})[0] == 200
      and payroll.raw('/report-builder', {'do': 'export', 'definition_id': d1['id']})[0] == 200)

# ── a relative period ────────────────────────────────────────────────────
save(payroll, {'name': 'Payroll last month', 'source': 'payroll', 'columns[]': ['week', 'payable'], 'period': 'last_month'})
d3 = last_definition()
status, body = payroll.get('/report-builder?id=%d' % d3['id'])
today = datetime.date.today()
last_end = today.replace(day=1) - datetime.timedelta(days=1)
m = re.search(r'id="resolved" data-from="([^"]+)" data-to="([^"]+)"', body)
check('Last month is worked out on the day: from the 1st to the last day of the month before', m and m[1] == last_end.replace(day=1).isoformat() and m[2] == last_end.isoformat(), m and m.groups())

# ── schedules ────────────────────────────────────────────────────────────
def schedule(client, definition, values):
    client.get('/report-builder?id=%d' % definition)
    return client.post('/report-builder', {'do': 'schedule', 'definition_id': definition, 'frequency': 'weekly', 'weekday': 1, 'run_hour': 6, **values})


check('A recipient who may not read the report is refused', schedule(payroll, d1['id'], {'recipients[]': [ID['payroll2'], ID['recruiter']]})[0] == 422)
check('A worker is never a recipient', schedule(payroll, d1['id'], {'recipients[]': [ID['worker']]})[0] == 422)
check('No recipient is refused', schedule(payroll, d1['id'], {})[0] == 422)
check('A monthly run on the 31st, which some months lack, is refused', schedule(payroll, d1['id'], {'frequency': 'monthly', 'monthday': 31, 'recipients[]': [ID['payroll2']]})[0] == 422)
check('Another user cannot schedule the owner\'s report', schedule(payroll2, d1['id'], {'recipients[]': [ID['payroll2']]})[0] == 403)
status, body = schedule(payroll, d1['id'], {'recipients[]': [ID['payroll2'], ID['payroll']]})
s1 = probe()['schedules'][-1]
nxt = datetime.datetime.fromisoformat(s1['next_run_at'])
check('Payroll schedules it weekly on Mondays at 6 for two people: the first run is the next Monday at 6',
      status == 200 and s1['frequency'] == 'weekly' and nxt.weekday() == 0 and nxt.hour == 6 and nxt > datetime.datetime.now() and json.loads(s1['recipients_json']) == [ID['payroll2'], ID['payroll']], s1)

# ── the runner and the delivery ──────────────────────────────────────────
out = runner()
check('Nothing is due yet', 'nothing due' in out and not probe()['deliveries'], out)
probe('due', s1['id'], '2017-03-06 06:00:00')
out = runner()
p = probe()
dl = p['deliveries'][-1]
check('Due, the runner delivers it once, to two people', 'delivered "February payroll by week"' in out and 'to 2 recipient(s)' in out and len(p['deliveries']) == 1
      and dl['status'] == 'ready' and dl['row_count'] == 2 and dl['sha256'] == hashlib.sha256(dl['csv'].encode()).hexdigest(), out)
check('Each is told in Crewvia, with a link to the file; nothing is emailed', sorted(n['user_id'] for n in p['notified'] if n['target'] == '/report-builder?delivery=%d' % dl['id']) == sorted([ID['payroll'], ID['payroll2']]))
check('The schedule moves to its next Monday', datetime.datetime.fromisoformat(p['schedules'][-1]['next_run_at']) > datetime.datetime.now())
out = runner()
check('Run again, nothing is delivered twice', 'nothing due' in out and len(probe()['deliveries']) == 1, out)
status, body = payroll2.get('/report-builder')
check('The delivery waits in the recipient\'s list', 'data-delivery="%d" data-status="ready"' % dl['id'] in body)
status, headers, raw, _ = payroll2.raw('/report-builder?delivery=%d' % dl['id'])
check('The recipient downloads it: the stored file, its fingerprint in the header', status == 200 and raw.decode() == dl['csv'] and headers.get('X-Content-SHA256') == dl['sha256'])
p = probe()
check('The download is recorded, for that recipient', any(r['user_id'] == ID['payroll2'] and r['downloaded_at'] for r in p['recipients'])
      and p['exports'][-1]['kind'] == 'delivery' and p['exports'][-1]['delivery_id'] == dl['id'])
check('Someone it was not sent to gets nothing, administrator or not', recruiter.raw('/report-builder?delivery=%d' % dl['id'])[0] == 404 and admin.raw('/report-builder?delivery=%d' % dl['id'])[0] == 404)
probe('role', ID['payroll2'], 'recruiter')
check('A recipient whose role no longer reads the report is refused', payroll2.raw('/report-builder?delivery=%d' % dl['id'])[0] == 403)
probe('role', ID['payroll2'], 'payroll')
probe('tamper', dl['id'])
check('A file changed after it was stored is refused', payroll.raw('/report-builder?delivery=%d' % dl['id'])[0] == 409)
probe('untamper', dl['id'])
check('Put back, it downloads again', payroll.raw('/report-builder?delivery=%d' % dl['id'])[0] == 200)

probe('role', ID['payroll2'], 'hotels')
probe('due', s1['id'], '2017-03-13 06:00:00')
runner()
probe('role', ID['payroll2'], 'payroll')
p = probe()
d2nd = p['deliveries'][-1]
skipped = [r for r in p['recipients'] if r['delivery_id'] == d2nd['id'] and r['user_id'] == ID['payroll2']]
check('At the next slot, a recipient who may no longer read it is not sent it, and the reason is kept',
      len(p['deliveries']) == 2 and skipped and skipped[0]['skipped_reason'] == 'may not read this report' and not skipped[0]['notified_at'], skipped)
check('and cannot download it later either', payroll2.raw('/report-builder?delivery=%d' % d2nd['id'])[0] == 404)

probe('expire', dl['id'])
check('After its 30 days a delivery is gone', payroll.raw('/report-builder?delivery=%d' % dl['id'])[0] == 410)
out = runner()
check('and the runner erases its file', 'Expired 1 delivered file(s)' in out and next(x for x in probe()['deliveries'] if x['id'] == dl['id'])['csv'] is None, out)

payroll.get('/report-builder?id=%d' % d1['id'])
check('The owner stops the schedule', payroll.post('/report-builder', {'do': 'schedule_stop', 'definition_id': d1['id'], 'schedule_id': s1['id']})[0] == 200 and probe()['schedules'][-1]['active'] == 0)
probe('due', s1['id'], '2017-03-20 06:00:00')
check('A stopped schedule does not run', 'nothing due' in runner() and len(probe()['deliveries']) == 2)

# ── an administrator runs what is due ────────────────────────────────────
schedule(payroll, d3['id'], {'recipients[]': [ID['payroll']], 'frequency': 'daily'})
s2 = probe()['schedules'][-1]
probe('due', s2['id'], '2017-03-21 06:00:00')
check('Payroll cannot run the schedules by hand', payroll.post('/report-builder', {'do': 'run_due'})[0] == 403)
status, body = admin.post('/report-builder', {'do': 'run_due'})
check('An administrator runs what is due from the page', status == 200 and 'delivered &quot;Payroll last month&quot;' in body and len(probe()['deliveries']) == 3)

payroll.get('/report-builder?id=%d' % d3['id'])
check('Archiving a report stops its schedules and hides it', payroll.post('/report-builder', {'do': 'archive', 'definition_id': d3['id']})[0] == 200
      and probe()['schedules'][-1]['active'] == 0 and payroll.get('/report-builder?id=%d' % d3['id'])[0] == 404)

status, body = payroll.get('/report-builder?lang=fr')
check('The report builder is translated into French', 'Générateur de rapports' in body and 'Rapports enregistrés' in body)
status, body = payroll.get('/report-builder?lang=es')
check('and into Spanish', 'Generador de informes' in body)
payroll.get('/report-builder?lang=en')

json.dump(results, open('work/rb-http-results.json', 'w'), indent=1)
