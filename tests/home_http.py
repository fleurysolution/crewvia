"""P5-M21 (layout wave 2) through the screens: every role lands on its own
home after signing in (a page asked for before signing in still wins); the
recruiting, Logistics and payroll desks' Today, each figure moving with the
records it counts and leading to its page; the supervisor's crew today on My
team; the worker's one home, the portal, holding what self-service showed,
with /self-service leading there; the menus and the logo leading home;
translations. Other suites share this database, so each figure is checked
by adding one known thing and watching it move.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/home.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS, V = fx['ids'], fx['vacancy']
results = []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None
        self.url = None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                self.url = urllib.parse.urlparse(r.geturl()).path
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

    def login(self, email, first=None):
        self.get(first or '/login')
        self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': 'TestPassword123!'}).encode()))
        return self.url

    def post(self, path, values, page):
        self.get(page)
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    return subprocess.run([php, 'work/home_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout.strip()


def tiles(c, path='/home'):
    body = c.get(path)[1]
    return {k: int(n) for k, n in re.findall(r'data-tile="([a-z_]+)"><div class="n">(\d+)</div>', body)}, body


# ── where each role lands ──────────────────────────────────────────────
c = {}
for key, home in (('admin', '/overview'), ('rec', '/home'), ('hotels', '/home'), ('pay', '/home'), ('sup', '/my-team'), ('w', '/portal')):
    c[key] = Client()
    landed = c[key].login('hm-%s@test.invalid' % key)
    check('%s lands on %s' % (key, home), landed == home, landed)
client = Client()
check('A client lands on the client portal', client.login('client@test.invalid') == '/client-portal')
asked = Client()
# A browser asks for HTML; Crewvia remembers the page only for such a navigation (app/bootstrap.php require_login).
asked.open(urllib.request.Request(base + '/candidates', headers={'Accept': 'text/html'}))
check('A page asked for before signing in still comes first', asked.login('hm-rec@test.invalid', '/login') == '/candidates', asked.url)
check('The administrator\'s Today is the Executive overview', c['admin'].get('/home') and c['admin'].url == '/overview')

# ── the recruiter ──────────────────────────────────────────────────────
before, body = tiles(c['rec'])
check('The recruiter\'s Today: applicants this week, waiting, in review, offers', set(before) == {'new_week', 'waiting', 'reviewing', 'offered'} and 'id="home-short"' in body and 'id="home-reach"' in body)
check('The requisition still short of people is listed, leading to its applicants', 'data-short="%d"' % V in body and 'href="/candidates?vacancy=%d"' % V in body)
probe('applicant')
after, _ = tiles(c['rec'])
check('One more application: applied this week +1, waiting for review +1', after['new_week'] - before['new_week'] == 1 and after['waiting'] - before['waiting'] == 1, (before, after))
over = probe('overfilled')
status, body = c['rec'].get('/home')
# openings is unsigned: openings minus hired once went below zero and took the page down.
check('A requisition with more hired than openings: Today still opens, and it is not listed as short', status == 200 and 'data-short="%s"' % over not in body and 'data-short="%d"' % V in body, status)

# ── Logistics ──────────────────────────────────────────────────────────
before, body = tiles(c['hotels'])
check('Logistics\' Today: arrivals, no bed, sharing, no travel', set(before) == {'arriving', 'beds', 'sharing', 'travel'} and 'id="home-arrivals"' in body and 'id="home-waiting"' in body)
pid = probe('arrival')
after, body = tiles(c['hotels'])
check('Someone confirmed to start in 3 days: arriving +1, no bed +1, no travel +1', after['arriving'] - before['arriving'] == 1 and after['beds'] - before['beds'] == 1 and after['travel'] - before['travel'] == 1, (before, after))
check('and they are in the week\'s arrivals, with no bed and no travel booked', re.search(r'data-arrival="%s">.*?tag red.*?tag amber' % pid, body, re.S) is not None)

# ── payroll ────────────────────────────────────────────────────────────
before, body = tiles(c['pay'])
check('Payroll\'s Today: hours to approve, sheets, disputes, bills', set(before) == {'hours_waiting', 'sheets_open', 'disputes', 'bills_due'} and 'id="home-checks"' in body and 'id="home-run"' in body)
for kind in ('hours', 'dispute', 'bill'):
    probe(kind)
after, _ = tiles(c['pay'])
check('A day submitted, a lateness disputed, a bill past due: each +1', after['hours_waiting'] - before['hours_waiting'] == 1 and after['disputes'] - before['disputes'] == 1 and after['bills_due'] - before['bills_due'] == 1, (before, after))

# ── the supervisor ─────────────────────────────────────────────────────
t, body = tiles(c['sup'], '/my-team')
check('The supervisor\'s My team opens on their crew today: two on site, both unmarked, a day to approve', t.get('on_site') == 2 and t.get('unmarked') == 2 and t.get('hours') == 1 and 'id="crew-today"' in body, t)
probe('mark')
t, _ = tiles(c['sup'], '/my-team')
check('Marked at roll call, one is left to mark', t.get('unmarked') == 1, t)

# ── the worker's one home ──────────────────────────────────────────────
status, body = c['w'].get('/portal')
check('The worker\'s portal holds what self-service showed: what waits on them and a card for every area', 'id="waiting"' in body and 'My self-service' in body
      and all('data-card="%s"' % k in body for k in ('details', 'timeoff', 'payslips', 'benefits', 'reviews', 'goals', 'hr_record', 'requests', 'expenses', 'notifications')))
check('and puts their onboarding task first among what waits on them', 'data-waiting="onboarding"' in body and 'id="onboarding"' in body)
check('with Jobs for you and their assignment', 'id="jobs-for-you-card"' in body and 'id="assignments"' in body)
c['w'].get('/self-service')
check('My self-service leads to the portal', c['w'].url == '/portal')
fr = c['w'].get('/self-service?lang=fr')[1]
check('keeping the language of the link it was opened from', c['w'].url == '/portal' and 'Mon libre-service' in fr)
c['w'].get('/portal?lang=en')
check('and is no longer a separate entry in the worker\'s menu', 'href="/self-service"' not in re.search(r'<nav[^>]*>(.*?)</nav>', body, re.S)[1])

# ── the menus and the logo ─────────────────────────────────────────────
body = c['rec'].get('/candidates')[1]
# P5-M73: the person's bookmarks and recent screens sit above the menu; the menu itself is what follows.
nav = re.sub(r'<div class="nav-marks" data-nav-marks>.*?</div>', '', re.search(r'<nav[^>]*>(.*?)</nav>', body, re.S)[1], flags=re.S)
check('Today is the first entry of a desk\'s menu', re.findall(r'href="(/[^"]*)"', nav)[:1] == ['/home'])
check('and the logo leads home', 'class="brand" href="/home"' in body and 'class="brand" href="/portal"' in c['w'].get('/portal')[1])

# ── translations ───────────────────────────────────────────────────────
c['rec'].post('/language', {'locale': 'es', 'back': '/home'}, '/home')
check('Today in Spanish', 'Requisiciones que aún necesitan gente' in c['rec'].get('/home')[1])
c['rec'].post('/language', {'locale': 'en', 'back': '/home'}, '/home')

json.dump(results, open('work/hm-http-results.json', 'w'), indent=1)
