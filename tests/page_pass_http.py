"""P5-M23 (layout wave 4), the page-by-page pass:
  - every page title in a view sits in the standard header, except the
    print documents and two public form cards, which are named;
  - every back link is the shared back-link, not a styled paragraph;
  - the only inline style a view may carry is a width worked out from data
    (a progress bar), and no count the app shows says "(s)";
  - a count reads as a person says it, by each language's rule (French says
    "0 demande"), on the probe and on the pages;
  - the section label above a title is the one in the reader's own menu,
    no longer a hand-written one ("AGREEMENTS" over Projects);
  - Hours and the employee folder are translated.
Runs on the main fixture's accounts.
"""
import glob, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
php = os.environ.get('PHP_BIN', 'php')
VIEWS = 'work/test-app/app/views/'
results = []

# The print documents are their own page, and two public pages hold their title in a form card.
OWN_PAGE = {'procurement-print.php', 'reports-print.php', 'statements-print.php', 'activate.php', 'application_received.php', 'poster.php'}
# Ceilings measured when wave 4 ended; lower them when more go.


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


def titles_outside(s):
    """Line numbers of <h1> with no page-head / page-heading among the divs around it."""
    stack, bad = [], []
    for m in re.finditer(r'<div\b([^>]*)>|</div>|<h1[\s>]', s):
        if m[0].startswith('<div'):
            c = re.search(r'class="([^"]*)"', m[1] or '')
            stack.append(c[1] if c else '')
        elif m[0] == '</div>':
            if stack:
                stack.pop()
        elif not any(re.search(r'\bpage-head(ing)?\b', c) for c in stack):
            bad.append(s.count('\n', 0, m.start()) + 1)
    return bad


# ── the views, read as source ──────────────────────────────────────────
views = {os.path.basename(f): open(f, encoding='utf-8').read() for f in glob.glob(VIEWS + '*.php')}
check('The views are there to read (%d)' % len(views), len(views) > 100)
outside = {v: b for v, b in ((v, titles_outside(s)) for v, s in views.items()) if b}
check('Every page title sits in the standard header', set(outside) == OWN_PAGE, sorted(set(outside) ^ OWN_PAGE))
loose = [v for v, s in views.items() if re.search(r'<p(?! class="back-link")[^>]*>\s*<a (?![^>]*class="btn)[^>]*>\s*(?:←|<\?= te\(\'←)', s)]
check('Every back link is the shared back-link', not loose, loose)
# P5-M25: a style is either data (a width from PHP, as a percentage) or a class.
styles = [(v, m) for v, s in views.items() for m in re.findall(r'style="([^"]*)"', s)]
fixed = [(v, m) for v, m in styles if not re.fullmatch(r'width:<\?= [^"]*\?>%', m)]
check('Every inline style in the views is a width worked out from data (%d of them)' % len(styles), styles and not fixed, fixed)
# Every PHP file of the app the reader sees, not only the views; the catalogues are the translations of these.
app_php = {f: open(f, encoding='utf-8').read() for f in glob.glob('work/test-app/app/**/*.php', recursive=True) if '/lang/' not in f.replace('\\', '/')}
plurals = sorted({m for s in app_php.values() for m in re.findall(r"te?\('([^']*\((?:s|es)\)[^']*)'", s)})
check('No count the app shows says "(s)" (%d files read)' % len(app_php), len(app_php) > 200 and not plurals, plurals)
css = open('work/test-app/public/assets/crewvia-ui.css', encoding='utf-8').read()
# A rule starts a line or follows another on it ('.in-xs { ... } .in-s { ... }').
HELPERS = set(re.findall(r'(?:^|\} )\.([a-z][a-z0-9-]*) \{', css, re.M))
used = {w for s in views.values() for c in re.findall(r'class="([^"]*)"', s) for w in re.sub(r'<\?.*?\?>', ' ', c).split()}
P23_P25 = {'m0', 'mt-xs', 'mt-s', 'mt', 'mt-l', 'mt-xl', 'mb-xs', 'mb-s', 'mb', 'mb-l', 'mb-xl', 'pull-up', 'ml-s', 'f1', 'f2', 'f3', 'inline', 'w-auto', 'pre-wrap',
           'wrap-any', 'nowrap', 't-amber', 't-red', 't-green', 't-muted', 'faded', 'card-bar', 'card-row', 'card-foot', 'row-between', 'back-link', 'in-xs', 'in-s',
           'in-m', 'in-l', 'in-xl', 'fw-xs', 'fw-s', 'flex', 'flex-wrap', 'gap-s', 'gap', 'gap-l', 'items-start', 'items-center', 'items-end', 'list-row', 'tone-warn',
           'tone-alert', 'big-figure', 'stat-label', 'progress-track', 'progress-fill', 'board-cols', 'qr-box', 'steps', 'bg-soft', 'pad-s', 't-amber-ink'}
missing = sorted(c for c in P23_P25 & used if c not in HELPERS)
check('and every helper class the views use is defined (%d in use)' % len(P23_P25 & used), not missing and len(P23_P25 & used) > 40, missing)

# ── counts, by each language's rule ────────────────────────────────────
pr = {loc: json.loads(subprocess.run([php, 'work/page_pass_probe.php', loc], check=True, capture_output=True, encoding='utf-8').stdout) for loc in ('en', 'fr', 'es')}
check('The probe reads each language', [pr[l]['locale'] for l in ('en', 'fr', 'es')] == ['en', 'fr', 'es'])
check('English: 0 requests, 1 request, 2 requests', pr['en']['requests'] == ['0 requests waiting', '1 request waiting', '2 requests waiting'], pr['en']['requests'])
check('French: 0 and 1 are singular, 2 is plural', pr['fr']['requests'] == ['0 demande en attente', '1 demande en attente', '2 demandes en attente'], pr['fr']['requests'])
check('Spanish: only 1 is singular', pr['es']['requests'] == ['0 solicitudes pendientes', '1 solicitud pendiente', '2 solicitudes pendientes'], pr['es']['requests'])
check('The overview title: 1 person, 2 people', pr['en']['people'][1] == '1 person out on one job' and pr['en']['people'][2] == '2 people out on one job')
check('ten() escapes what it prints', pr['en']['escaped'] == '&lt;b&gt;1&lt;/b&gt; one')
check('A sentence with two counts agrees part by part, in each language',
      [pr[l]['driving'] for l in ('en', 'fr', 'es')] == ['1 violation, 0 accidents in 3 years', '1 infraction, 0 accident en 3 ans', '1 infracción, 0 accidentes en 3 años'],
      [pr[l]['driving'] for l in ('en', 'fr', 'es')])
check('"1 hour ago" in each language', [pr[l]['ago'] for l in ('en', 'fr', 'es')] == ['1 hour ago', 'il y a 1 heure', 'hace 1 hora'], [pr[l]['ago'] for l in ('en', 'fr', 'es')])
check('The Hours subtitle and the folder title are translated',
      pr['fr']['guarantee'] == 'garantie de 50 heures' and pr['fr']['week'].startswith('Semaine se terminant') and pr['es']['folder'] == 'Expediente del empleado')

# ── the pages ──────────────────────────────────────────────────────────
admin = Client()
admin.login('admin@test.invalid')
eyebrow = lambda body: (re.search(r'<div class="page-eyebrow">([^<]*)</div>', body) or [None, None])[1]
body = admin.get('/projects')[1]
check('Projects is labelled with its menu section, not "AGREEMENTS"', eyebrow(body) == 'Overview' and 'AGREEMENTS' not in body, eyebrow(body))
check('and so is Requisitions ("Recruiting", not "RECRUITMENT / REQUISITIONS")', eyebrow(admin.get('/requisitions')[1]) == 'Recruiting')
worker = Client()
worker.login('worker@test.invalid')
check('A worker\'s contracts are labelled in their own menu\'s words', eyebrow(worker.get('/contracts')[1]) == 'My papers')
body = worker.get('/portal')[1]
waiting = re.search(r'id="waiting">(.*?)<h2', body, re.S)[1]
check('The worker\'s portal counts in words and names the assignment\'s status in words',
      '(s)' not in waiting and re.search(r'· (on_site|offered|confirmed|travelling|completed|cancelled) ·', body) is None, waiting[:300])
status, body = admin.get('/hours')
check('Hours: the standard header, the week and the guarantee in words', status == 200 and re.search(r'<div class="page-head"><div><h1>Hours</h1>\s*<p class="sub">\s*Week ending ', body) is not None and '-hour guarantee' in body)
admin.post('/language', {'locale': 'fr', 'back': '/hours'}, '/hours')
body = admin.get('/hours')[1]
check('and in French', 'Semaine se terminant le' in body and 'Week ending' not in body and 'garantie de' in body)
admin.post('/language', {'locale': 'en', 'back': '/hours'}, '/hours')
left = [p for p in ('/surveys', '/training', '/leave-types', '/pay-grades', '/statements', '/report-builder', '/assets') if 'class="page-head"><div><h1' not in admin.get(p)[1]]
check('The pages wave 1 left open with the standard header (surveys, training, leave types, pay grades, statements, report builder, assets)', not left, left)

json.dump(results, open('work/pp-http-results.json', 'w'), indent=1)
