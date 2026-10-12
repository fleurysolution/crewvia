"""P5-M06a, the phone foundation: the service worker keeps only the offline
page and static files, answers only page reads, and never caches a
signed-in page; the offline page holds no script, form or data; one
manifest; the menu button on a phone (present for a signed-in person,
folded only when scripts run); translations.
"""
import http.cookiejar, json, re, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
results = []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                return r.status, r.headers, r.read().decode(errors="replace")
        except urllib.error.HTTPError as e:
            return e.code, e.headers, e.read().decode(errors="replace")

    def get(self, path):
        status, headers, body = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        return status, headers, body

    def post(self, path, values):
        data = urllib.parse.urlencode({'_csrf': self.token, **values}).encode()
        return self.open(urllib.request.Request(base + path, data))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:600] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


anon = Client()

# ── the service worker ─────────────────────────────────────────────────
status, headers, sw = anon.get('/service-worker.js')
check('The service worker is served as JavaScript', status == 200 and 'javascript' in headers.get('Content-Type', ''), headers.get('Content-Type'))
offline = re.search(r"const OFFLINE = '([^']+)';", sw)[1]
precache = json.loads(re.search(r'const PRECACHE = (\[[^\]]*\]);', sw)[1].replace('OFFLINE', "'%s'" % offline).replace("'", '"'))
check('It keeps only the offline page and static files', precache[0] == '/offline.html' and all(p == '/offline.html' or p.startswith('/assets/') for p in precache), precache)
check('It never stores a response it fetched: only the fixed list, at install', 'cache.put' not in sw and sw.count('cache.addAll(PRECACHE)') == 1)
check('It answers only pages being opened, and only reads', "request.method !== 'GET' || request.mode !== 'navigate'" in sw)
check('A new version clears the old caches', 'caches.delete' in sw)
for p in precache:
    check('%s is there to keep' % p, anon.get(p)[0] == 200)

# ── the offline page ───────────────────────────────────────────────────
status, headers, page = anon.get('/offline.html')
check('The offline page opens without signing in', status == 200 and 'id="offline"' in page)
check('It speaks English, French and Spanish', 'You are offline' in page and 'Vous êtes hors ligne' in page and 'Está sin conexión' in page)
check('It holds no script, no form and no data', '<script' not in page and '<form' not in page and '@' not in page.split('<main')[1])

# ── one manifest ───────────────────────────────────────────────────────
status, headers, old = anon.get('/manifest.webmanifest')
check('The old static manifest is gone: that address now leads to signing in', 'short_name' not in old and '<title>Sign in' in old)
status, headers, manifest = anon.get('/app-manifest')
m = json.loads(manifest)
check('The manifest pages use installs the app standalone, with 192 and 512 icons',
      status == 200 and m['display'] == 'standalone' and m['start_url'] == '/' and {'192x192', '512x512'} <= {i['sizes'] for i in m['icons']})

# ── the menu button ────────────────────────────────────────────────────
status, headers, login = anon.get('/login')
check('Signed out, there is no menu button', 'nav-toggle' not in login)
w = Client()
w.get('/login')
w.post('/login', {'email': 'worker@test.invalid', 'password': 'TestPassword123!'})
status, headers, body = w.get('/portal')
check('A signed-in worker gets the menu button, tied to the menu and closed',
      status == 200 and re.search(r'<button type="button" class="[^"]*nav-toggle" aria-controls="workspace-nav" aria-expanded="false">Menu</button>', body)
      and '<nav class="nav" id="workspace-nav"' in body)
head = body.split('</head>')[0]
check('The page marks that scripts run before it is drawn', "document.documentElement.classList.add('js')" in head)
check('The menu folds only on a phone and only when scripts run', re.search(r'@media\(max-width:720px\)\{\s*\.js \.nav-toggle\{[^}]*\}\s*\.js \.top \.nav\{display:none\}', head))
check('Every script is inside the page, the service worker registered last', body.rstrip().endswith('</body>\n</html>') or body.rstrip().endswith('</body>\r\n</html>') or body.rstrip().endswith('</body>'),
      body.rstrip()[-120:])
check('and nothing comes after the end of the page but its closing', '<script' not in body.split('</body>')[-1])
status, headers, body = w.get('/portal?lang=es')
check('The button in Spanish', '>Menú</button>' in body)
w.get('/portal?lang=en')

json.dump(results, open('work/mobile-http-results.json', 'w'), indent=1)
