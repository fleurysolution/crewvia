"""P5-M16 (T25) through the screen: the Executive overview groups its
figures above the project table into People (on site, placed, still to
place, fill rate, the hiring pipeline), this week's money (crew cost,
billing, margin and its share) and what needs somebody today (every kind
of problem added up across projects, each leading to its page); the table
can be narrowed to one stage. Other suites share this database, so each
check adds one known thing and watches exactly that figure move.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/overview_groups.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []
O = '/overview'


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

    def post(self, path, values):
        self.get(O)
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode()))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    subprocess.run([php, 'work/overview_groups_probe.php', *map(str, args)], check=True, capture_output=True, text=True)


def figures():
    body = admin.get(O)[1]
    out = {}
    for key, value in re.findall(r'data-figure="([a-z_]+)"[^>]*>([^<]*)<', body):
        digits = re.sub(r'[^0-9.\-]', '', value)
        # A money figure with nothing agreed reads "—": that is $0 here.
        out[key] = float(digits) if digits not in ('', '-', '.') else (0.0 if key in ('pay', 'bill', 'margin') else None)
    for where, n in re.findall(r'data-attention="([^"]+)"><span class="dot[^"]*"></span><a href="[^"]+"><strong>(\d+)</strong>', body):
        out['at:' + where] = int(n)
    return out, body


admin = Client()
admin.get('/login')
admin.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': admin.token, 'email': 'ov-admin@test.invalid', 'password': 'TestPassword123!'}).encode()))

before, body = figures()
check('The overview opens with its three groups and the stage chips', all('id="%s"' % g in body for g in ('group-people', 'group-money', 'group-attention', 'stage-chips')))

# ── People ─────────────────────────────────────────────────────────────
probe('person', 'on_site')
probe('person', 'confirmed')
after, _ = figures()
check('Two placed, one of them on site: on site +1, placed +2, still to place -2 (the project asked for 3)',
      after['onsite'] - before['onsite'] == 1 and after['placed'] - before['placed'] == 2 and before['unplaced'] - after['unplaced'] == 2, (before, after))
check('The week\'s crew cost and billing follow the people: +$2,400 pay and +$4,000 billing at 40 hours',
      round(after['pay'] - before['pay'], 2) == 2400.0 and round(after['bill'] - before['bill'], 2) == 4000.0 and round(after['margin'] - before['margin'], 2) == 1600.0, (before.get('pay'), after.get('pay'), before.get('bill'), after.get('bill')))
before = after
probe('applicant', 'new')
probe('applicant', 'screening')
probe('applicant', 'offered')
after, body = figures()
check('The hiring line: three new this week, one more reviewed, one more offer out', after['new_week'] - before['new_week'] == 3 and after['reviewing'] - before['reviewing'] == 1 and after['offered'] - before['offered'] == 1, (before, after))
check('and it leads to Hiring', 'href="/hiring" data-figure="new_week"' in body)

# ── Needs somebody today ───────────────────────────────────────────────
before = after
for kind in ('dispute', 'folio', 'overdue', 'incident'):
    probe(kind)
after, body = figures()
for where, label in (('/lateness', 'lateness disputes to settle'), ('/folios', 'hotel folios still open'), ('/accounts-payable', 'bills past due'), ('/learning', 'open safety incidents')):
    check('One more %s, leading to %s' % (label, where), after.get('at:' + where, 0) - before.get('at:' + where, 0) == 1 and label in body, (where, before.get('at:' + where), after.get('at:' + where)))
listed = sum(int(n) for n in re.findall(r'<li data-attention="[^"]+"><span class="dot[^"]*"></span><a href="[^"]+"><strong>(\d+)</strong>', body))
check('The heading counts everything listed under it', after['attention'] == listed, (after['attention'], listed))
check('Problems in red come first', body.index('class="dot red"') < body.index('class="dot amber"') if 'class="dot amber"' in body else True)

# ── the table by stage ─────────────────────────────────────────────────
row = lambda title: '<strong>%s</strong>' % title          # the table's own cell, not the project picker
status, body = admin.get(O)
check('All projects, with a chip per stage', row('P5-M16 finished outage') in body and row('P5-M16 running turnaround') in body
      and 'data-stage-chip="closed"' in body and 'data-stage-chip="on_site"' in body)
status, body = admin.get(O + '?stage=closed')
check('The table narrows to finished projects', row('P5-M16 finished outage') in body and row('P5-M16 running turnaround') not in body)
status, body = admin.get(O + '?stage=on_site')
check('and to those on site', row('P5-M16 running turnaround') in body and row('P5-M16 finished outage') not in body)
check('An unknown stage shows everything', row('P5-M16 running turnaround') in admin.get(O + '?stage=nonsense')[1])

# ── translations ───────────────────────────────────────────────────────
admin.post('/language', {'locale': 'fr', 'back': O})
check('The overview in French', 'À traiter aujourd’hui' in admin.get(O)[1] and 'L’argent de la semaine' in admin.get(O)[1])
admin.post('/language', {'locale': 'en', 'back': O})

json.dump(results, open('work/og-http-results.json', 'w'), indent=1)
