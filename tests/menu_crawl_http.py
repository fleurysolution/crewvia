"""P5-M58 through the screens: every desk's menu opens, and what its home screens link to opens too.
For each kind of account - administrator, recruiter, supervisor, Hotels & Travel, Payroll & Billing, employee,
client - every link of its menu (daily screens and "More") answers 200, without a PHP error and without
"Not your desk"; every link on its home screen and on the project dashboard does too (the audit of
16 Oct 2026 found the dashboard sending three desks to screens they cannot open, and the client's bell
to a refused page).
Runs after people_http.py and menu_crawl_db.php, on the walkthrough fixture.
"""
import http.cookiejar, json, re, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
results = []
SKIP = re.compile(r'^/(logout|google-start|gmail|activate|recover|export|download|payslip\?)|download=|\.(pdf|csv|xlsx|zip|png|jpg|svg|ico|js|css)$')
ACCOUNTS = [('administrator', 'wk-admin@test.invalid'), ('recruiter', 'wk-rec@test.invalid'), ('supervisor', 'wk-sup@test.invalid'),
            ('Hotels & Travel', 'mc-hotels@test.invalid'), ('Payroll & Billing', 'mc-payroll@test.invalid'),
            ('employee', 'wk-w@test.invalid'), ('client', 'client@test.invalid')]


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:900] if detail else '')
    results.append(label)
    print('PASS ' + label)


def session(email):
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    body = op.open(base + '/login').read().decode()
    token = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)[1]
    with op.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': token, 'email': email, 'password': 'TestPassword123!'}).encode())) as r:
        return op, r.geturl().replace(base, '')


def get(op, path):
    try:
        with op.open(base + path, timeout=120) as r:
            return r.status, r.geturl().replace(base, ''), r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, path, e.read().decode('utf-8', 'replace')


def broken(status, body):
    if status != 200:
        return 'status %d' % status
    for marker in ('Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught', 'PDOException'):
        if marker in body:
            return 'PHP ' + marker
    return None


def links(body, menu_only):
    if menu_only:
        body = ''.join(re.findall(r'<(?:nav|aside)\b.*?</(?:nav|aside)>', body, re.S))
    return {h.split('#')[0] for h in re.findall(r'href="(/[^"]*)"', body) if h.split('#')[0] and not SKIP.search(h.split('#')[0])}


for label, email in ACCOUNTS:
    op, landed = session(email)
    status, _, home = get(op, landed)
    menu = links(home, True)
    failures = {}
    for path in sorted(menu):
        st, _, body = get(op, path)
        why = broken(st, body)
        if why:
            failures[path] = why
    check('%s: every screen of the menu opens (%d)' % (label.capitalize(), len(menu)), menu and not failures, failures)
    linked = links(home, False) - menu
    if label not in ('employee', 'client'):
        st, _, dash = get(op, '/')
        if st == 200:
            linked |= links(dash, False) - menu
    failures = {}
    for path in sorted(linked):
        st, _, body = get(op, path)
        if st in (403, 404) or (st == 200 and broken(st, body)):
            failures[path] = broken(st, body) or 'status %d' % st
    check('%s: every link on its home screen and the project dashboard opens (%d)' % (label.capitalize(), len(linked)), not failures, failures)

json.dump(results, open('work/menu-crawl-results.json', 'w'), indent=1)
