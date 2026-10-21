"""P5-M101: the open positions as JSON for the agency's own website (after billing_ledger_db.php opened a requisition).
  - /careers-feed lists exactly the positions /careers shows, each with its apply link into Crewvia;
  - readable from any website (CORS), GET only;
  - pay terms carried, nothing about the client.
"""
import json, re, urllib.error, urllib.request

base = 'http://127.0.0.1:8097'
results = []


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:900] if detail else '')
    results.append(label)
    print('PASS ' + label, flush=True)


def fetch(path, method='GET', data=None):
    req = urllib.request.Request(base + path, data=data, method=method)
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, r.read().decode('utf-8'), dict(r.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8'), dict(e.headers)


status, text, headers = fetch('/careers-feed')
feed = json.loads(text)
check('The feed answers JSON in its own format', status == 200 and feed['format'] == 'crewvia-careers-v1' and 'application/json' in headers.get('Content-Type', ''))
check('Any website may read it (CORS)', headers.get('Access-Control-Allow-Origin') == '*')
status, page, _ = fetch('/careers')
on_page = sorted(int(x) for x in set(re.findall(r'/apply\?id=(\d+)', page)))
in_feed = sorted(j['id'] for j in feed['jobs'])
check('The feed lists exactly the positions the careers page shows', on_page == in_feed and len(in_feed) > 0, (on_page, in_feed))
job = next((j for j in feed['jobs'] if j['title'] == 'Follow-up welders'), None)
check('Each position carries its terms and its apply link into Crewvia', job is not None and job['apply_url'].endswith('/apply?id=%d' % job['id'])
      and {'pay_per_hour', 'guaranteed_hours_week', 'per_diem_per_day', 'hotel_paid', 'travel_paid', 'city', 'state'} <= set(job), job)
status, apply_page, _ = fetch('/apply?id=%d' % job['id'])
check('The apply link opens the application for that position', status == 200 and 'Follow-up welders' in apply_page)
check('Nothing about the client', 'Billing test client' not in text and 'client' not in json.dumps(feed['jobs']).lower(), text[:400])
# P5-M102: the application page.
check('The application page never shows the project name (it can name the client)', 'Billing plant A' not in apply_page)
check('A job that pays the travel shows it, and asks the airport', 'Hotel paid' in apply_page and 'Travel to and from site paid' in apply_page and 'name="home_airport"' in apply_page)
local = next(j for j in feed['jobs'] if j['title'] == 'Local packer')
status, local_page, _ = fetch('/apply?id=%d' % local['id'])
check('A job that pays no travel promises none and does not ask the airport', status == 200 and 'Hotel paid' not in local_page and 'Travel to and from site paid' not in local_page and 'name="home_airport"' not in local_page)
check('The feed says the same', local['travel_paid'] is False and local['hotel_paid'] is False)
check('The careers page makes no blanket promise of travel', 'Travel, lodging and a daily allowance are provided' not in page and 'across the United States' in page)
check('No "work near you" on the application', 'work near you' not in apply_page)
# P5-M102: a refused application comes back with the entries and the reason, never a blank page.
import http.cookiejar, urllib.parse
jar = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def send(path, values=None):
    req = urllib.request.Request(base + path, urllib.parse.urlencode(values).encode() if values is not None else None)
    try:
        with jar.open(req) as r:
            return r.status, r.read().decode('utf-8')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8')
_, form = send('/apply?id=%d' % job['id'])
token = re.search(r'name="_csrf" value="([a-f0-9]+)"', form)[1]
person = {'vacancy_id': job['id'], 'name': 'Feed Applicant', 'email': 'feed-applicant@test.invalid', 'phone': '555-010-2020', 'city': 'Atlanta', 'state': 'GA', 'home_airport': 'ATL - Atlanta'}
status, body = send('/apply', dict(person, _csrf=token))
check('No consent: the form comes back with the reason and the entries kept', status == 422 and 'id="apply-error"' in body and 'value="Feed Applicant"' in body and 'value="ATL - Atlanta"' in body, body[:300])
status, body = send('/apply', dict(person, _csrf='0' * 64, consent='1'))
check('A form left open too long comes back with the entries kept, not "That form expired"', status == 422 and 'id="apply-error"' in body and 'value="feed-applicant@test.invalid"' in body and 'That form expired' not in body, body[:300])
token = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)[1]
status, body = send('/apply', dict(person, _csrf=token, consent='1'))
check('Then the application goes through', status == 200 and 'Application received' in body, body[:300])
_, form = send('/apply?id=%d' % local['id'])
token = re.search(r'name="_csrf" value="([a-f0-9]+)"', form)[1]
status, body = send('/apply', dict(person, vacancy_id=local['id'], _csrf=token, consent='1'))
check('A second application seconds later is asked to wait, on the form, entries kept', status == 422 and 'id="apply-error"' in body and 'value="Feed Applicant"' in body, body[:300])
status, _, _ = fetch('/careers-feed', 'POST', b'x')
check('GET only', status == 405)
print(f'careers_feed_http: {len(results)} checks passed')
