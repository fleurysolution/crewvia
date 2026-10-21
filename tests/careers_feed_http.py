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
status, _, _ = fetch('/careers-feed', 'POST', b'x')
check('GET only', status == 405)
print(f'careers_feed_http: {len(results)} checks passed')
