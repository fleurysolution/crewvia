"""P5-M22 (layout wave 3) through the screens: each role's menu opens with its
daily screens, in the order of its day, and folds every other screen it may
open under "More", by section. Nothing is lost or doubled, More opens by
itself on a screen kept there, the daily screens all open (the list never
widens access), the client has no fold, and the phone header grows with the
open menu instead of letting the page paint over it. Uses the desk users
made by home_db.php.
"""
import http.cookiejar, json, re, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
results = []

DAILY = {
    'admin':  ['/overview', '/activity', '/approvals', '/', '/projects', '/hiring', '/reviews', '/hours', '/billing', '/people', '/settings'],
    'rec':    ['/home', '/activity', '/hiring', '/reviews', '/candidates', '/requisitions', '/recruitment', '/onboarding', '/tax-forms', '/contracts', '/client-orders', '/approvals'],
    'hotels': ['/home', '/activity', '/hotels', '/travel', '/folios', '/manning', '/operations', '/procurement', '/approvals'],
    'pay':    ['/home', '/activity', '/hours', '/attendance-week', '/lateness', '/payroll-runs', '/payroll-export', '/billing', '/client-invoices', '/accounts-payable', '/approvals'],
    'sup':    ['/my-team', '/activity', '/reviews', '/attendance', '/lateness', '/my-tasks', '/project-tasks', '/procurement', '/safety-plan', '/notifications'],
    'w':      ['/portal', '/my-jobs', '/attendance', '/timeoff', '/my-payslips', '/notifications', '/activity'],
}


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                return r.status, r.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode()

    def get(self, path):
        status, body = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body

    def login(self, email):
        self.get('/login')
        return self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': 'TestPassword123!'}).encode()))

    def post(self, path, values, page):
        self.get(page)
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def menu(body):
    # P5-M73: the person's bookmarks and recent screens sit above the menu; the menu itself is what follows.
    nav = re.sub(r'<div class="nav-marks" data-nav-marks>.*?</div>', '', re.search(r'<nav[^>]*>(.*?)</nav>', body, re.S)[1], flags=re.S)
    daily = re.search(r'<div class="nav-daily">(.*?)</div>', nav, re.S)
    more = re.search(r'<details class="nav-more"([^>]*)><summary>([^<]*)</summary>(.*)</details>', nav, re.S)
    hrefs = lambda html: re.findall(r'<a href="(/[^"]*)"', html or '')
    return {'daily': hrefs(daily[1] if daily else ''), 'more': hrefs(more[3] if more else ''), 'open': bool(more and 'open' in more[1]),
            'label': more[2] if more else None, 'all': hrefs(nav), 'nav': nav}


c = {}
for key in DAILY:
    c[key] = Client()
    c[key].login('hm-%s@test.invalid' % key)

# ── the daily screens first, the rest under More ───────────────────────
for key, expected in DAILY.items():
    status, body = c[key].get('/activity')
    m = menu(body)
    check('%s: the daily screens first, in the order of the day' % key, m['daily'] == expected, m['daily'])
    check('%s: every other screen under More, nothing lost or listed twice' % key,
          m['more'] and not set(m['daily']) & set(m['more']) and len(m['all']) == len(set(m['all'])) and sorted(m['all']) == sorted(m['daily'] + m['more']), m)
    check('%s: More is folded on a daily screen' % key, m['label'] == 'More' and not m['open'])
    refused = [p for p in m['daily'] if c[key].get(p)[0] != 200]
    check('%s: every daily screen opens for them' % key, not refused, refused)

# The fold opens by itself on a screen kept under it, on that screen's section.
m = menu(c['rec'].get('/roster')[1])
check('On a screen kept under More, More is open', m['open'] and '/roster' in m['more'])
check('with that screen\'s section unfolded and the screen marked', re.search(r'<details class="nav-group" data-section="Deployment" open>.*?<a href="/roster" class="on">', m['nav'], re.S) is not None)

# The worker's screens kept under More still sit in their own sections.
m = menu(c['w'].get('/portal')[1])
check('A worker\'s papers stay under More, in "My papers"', re.search(r'data-section="My papers"[^>]*>.*?href="/contracts"', m['nav'], re.S) is not None and '/contracts' in m['more'])

# ── the client ─────────────────────────────────────────────────────────
cl = Client()
cl.login('client@test.invalid')
m = menu(cl.get('/client-portal')[1])
check('A client has their one screen and no fold', m['all'] == ['/client-portal'] and m['label'] is None, m['all'])

# ── the phone header ───────────────────────────────────────────────────
css = c['rec'].get('/assets/crewvia-ui.css')[1]
check('On a phone the header grows with the open menu', re.search(r'@media \(max-width: 720px\) \{ \.top \{ height: auto; \} \}', css) is not None)

# ── translations ───────────────────────────────────────────────────────
c['rec'].post('/language', {'locale': 'es', 'back': '/home'}, '/home')
check('More in Spanish', menu(c['rec'].get('/home')[1])['label'] == 'Más')
c['rec'].post('/language', {'locale': 'en', 'back': '/home'}, '/home')

json.dump(results, open('work/mn-http-results.json', 'w'), indent=1)
