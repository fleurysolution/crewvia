"""P5-M100 through the screens and the hub endpoints (after billing_ledger_db.php sealed February 2024).
  - the owner sees the subscription: this month, the chain intact, the sealed month with its fingerprint;
  - the statement lists the people counted, as CSV too; a recruiter does not open it;
  - /api/billing-count and /api/revenue answer a signed request with figures only, no names;
  - a bad signature, an old timestamp, another product are refused (contract v1.1);
  - Approved, next step: counters per requisition for the recruiters and HR, from Hiring.
"""
import hashlib, hmac, http.cookiejar, json, re, time, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
SECRET = 'hub-test-secret-crewvia'
results = []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                return r.status, r.read().decode('utf-8', 'replace'), dict(r.headers)
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), dict(e.headers)

    def get(self, path):
        status, text, headers = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', text)
        if m:
            self.token = m[1]
        bad = [w for w in ('Fatal error', 'Warning:', 'Notice:', 'Deprecated:') if w in text]
        assert not bad, text[max(0, text.find(bad[0]) - 200):text.find(bad[0]) + 600] if bad else ''
        return status, text, headers

    def login(self, email):
        self.get('/login')
        self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': 'TestPassword123!'}).encode()))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:900] if detail else '')
    results.append(label)
    print('PASS ' + label, flush=True)


def hub(path, query, product='crewvia', secret=SECRET, stamp=None):
    stamp = str(int(time.time()) if stamp is None else stamp)
    sig = 'sha256=' + hmac.new(secret.encode(), (stamp + '.' + query).encode(), hashlib.sha256).hexdigest()
    req = urllib.request.Request(base + path + '?' + query, headers={'X-FS-Product': product, 'X-FS-Timestamp': stamp, 'X-FS-Signature': sig})
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, r.read().decode('utf-8')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8')


admin = Client(); admin.login('wk-admin@test.invalid')
status, body, _ = admin.get('/crewvia-billing')
check('Crewvia subscription opens for the owner, chain intact, this month so far', status == 200 and 'data-chain="ok"' in body and 'id="billing-now"' in body, body[:400])
check('February 2024 is listed sealed, with its amount and fingerprint', 'data-closing="2024-02"' in body and '$2,500.00' in body)
status, body, _ = admin.get('/crewvia-billing?month=2024-02')
check('The statement lists the people counted, one line each, nobody who never worked',
      status == 200 and 'Billing Two Sites' in body and 'Billing Pay Cycle' in body and 'Billing Never Worked' not in body and 'Billing Never Came' not in body)
check('The late day is noted, not counted', 'data-late="1"' in body and 'Billing Late Day' not in body)
check('The printed statement carries the Fleury Solutions letterhead', 'class="print-only letterhead"' in body and 'fleury-solutions-symbol.svg' in body)
status, csv, headers = admin.get('/crewvia-billing?month=2024-02&csv=1')
check('The statement downloads as CSV', status == 200 and 'text/csv' in headers.get('Content-Type', '') and csv.splitlines()[0].startswith('Month,Person,Projects') and 'Billing Two Sites' in csv)
rec = Client(); rec.login('wk-rec@test.invalid')
status, _, _ = rec.get('/crewvia-billing')
check('A recruiter does not open the subscription', status == 403)

status, text = hub('/api/billing-count', 'month=2024-02')
data = json.loads(text)
check('Signed billing-count answers the sealed figure', status == 200 and data['product'] == 'crewvia' and data['month'] == '2024-02' and data['billable_people'] >= 3
      and len(data['ledger_hash']) == 64 and data['sealed_at'].endswith('Z'), text)
check('billing-count carries no names or personal data', set(data) == {'product', 'client', 'month', 'billable_people', 'event_count', 'ledger_hash', 'sealed_at'} and 'Billing' not in text, text)
status, text = hub('/api/revenue', 'month=2024-02')
rev = json.loads(text)
check('Signed revenue answers the subscription, one-time 0, with a checksum', status == 200 and rev['monthly_recurring'] == 2500.0 and rev['one_time'] == 0.0
      and rev['active_subscriptions'] == 1 and rev['currency'] == 'USD' and len(rev['checksum']) == 64, text)
expected = hashlib.sha256('|'.join(['crewvia', rev['month'], 'USD', '%.2f' % rev['monthly_recurring'], '%.2f' % rev['one_time'], '1', rev['generated_at']]).encode()).hexdigest()
check('The revenue checksum is the SHA-256 of its values joined by "|"', rev['checksum'] == expected, (rev, expected))
status, text = hub('/api/billing-count', 'month=' + time.strftime('%Y-%m'))
check('An open month answers its live count, not sealed', status == 200 and json.loads(text)['ledger_hash'] is None and json.loads(text)['sealed_at'] is None, text)
status, text = hub('/api/billing-count', 'month=2024-02', secret='wrong-secret')
check('A bad signature is refused (401 bad_signature)', status == 401 and json.loads(text)['error'] == 'bad_signature', text)
status, text = hub('/api/billing-count', 'month=2024-02', stamp=int(time.time()) - 600)
check('A timestamp over 5 minutes old is refused (401 stale_timestamp)', status == 401 and json.loads(text)['error'] == 'stale_timestamp', text)
status, text = hub('/api/billing-count', 'month=2024-02', product='other')
check('Another product is refused (404 unknown_product)', status == 404 and json.loads(text)['error'] == 'unknown_product', text)
status, text = hub('/api/billing-count', 'month=2024-13')
check('A bad month is refused (400 bad_request)', status == 400 and json.loads(text)['error'] == 'bad_request', text)
try:
    with urllib.request.urlopen(base + '/api/billing-count?month=2024-02') as r:
        status = r.status
except urllib.error.HTTPError as e:
    status = e.code
check('No signature, no answer', status == 401)
try:
    with urllib.request.urlopen(urllib.request.Request(base + '/api/billing-count?month=2024-02', data=b'x', method='POST')) as r:
        status, text = r.status, r.read().decode()
except urllib.error.HTTPError as e:
    status, text = e.code, e.read().decode()
check('An unsigned POST learns nothing either (401 bad_signature)', status == 401 and json.loads(text)['error'] == 'bad_signature', text)

status, body, _ = rec.get('/follow-up')
check('Approved, next step opens for a recruiter, with counters per requisition', status == 200 and 'id="follow-up-requisitions"' in body, body[:400])
check('Approved two months ago, never called since: listed ready for a call', 'Follow Quiet' in body and 'data-follow-person' in body)
check('Called since the approval, or deployed: not listed', 'Follow Called' not in body and 'Follow Deployed' not in body)
check('Counters only, no alert wording', 'overdue' not in body.lower() and 'alert' not in body.lower())
status, body, _ = rec.get('/follow-up?days=60')
check('The window is chosen (7 to 60 days): approved 50 days ago is not yet past 60', status == 200 and '<option value="60" selected' in body and 'Follow Quiet' not in body)
hr = Client(); hr.login('wk-hr@test.invalid')
status, body, _ = hr.get('/hiring')
check('Hiring links to it for HR', status == 200 and 'id="follow-up-link"' in body)
status, _, _ = hr.get('/follow-up')
check('HR opens it', status == 200)
print(f'billing_http: {len(results)} checks passed')
