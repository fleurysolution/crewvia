"""P4-M07 error monitoring and health through the screens and the command
line: an exception, an undefined function and an out-of-memory fatal each
logged once with the reference the person is shown, the query string never
logged; /health for an uptime monitor; System health for administrators
only; the cron health check and its exit codes; the scheduled jobs
recording their runs; old error logs purged; translations.
"""
import datetime, glob, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
php = os.environ.get('PHP_BIN', 'php')
app = 'work/test-app'
logs = app + '/storage/logs'
results = []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None
        self.headers = {}

    def get(self, path, strict=True):
        try:
            with self.opener.open(base + path) as r:
                status, h, body = r.status, r.headers, r.read().decode()
        except urllib.error.HTTPError as e:
            status, h, body = e.code, e.headers, e.read().decode()
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        if strict:
            assert 'Fatal error' not in body and 'Warning:' not in body, body[:800]
        self.headers = h
        return status, body

    def post(self, path, values):
        try:
            with self.opener.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode())) as r:
                return r.status, r.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode()

    def login(self, email):
        self.get('/login')
        return self.post('/login', {'email': email, 'password': 'TestPassword123!'})


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:600] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def log_entries():
    out = []
    for f in sorted(glob.glob(logs + '/errors-*.log')):
        out += [json.loads(l) for l in open(f, encoding='utf-8') if l.strip()]
    return out


def raw_logs():
    return ''.join(open(f, encoding='utf-8').read() for f in glob.glob(logs + '/errors-*.log'))


def cli(script, *args):
    p = subprocess.run([php, app + '/install/' + script, *args], capture_output=True, text=True)
    return p.returncode, p.stdout + p.stderr


def runs():
    return json.loads(subprocess.run([php, 'work/operations_probe.php'], check=True, capture_output=True, text=True).stdout)['runs']


# Test-only pages that break on purpose, beside index.php in the test copy.
open(app + '/public/ops-trial-throw.php', 'w').write('<?php require __DIR__."/../app/bootstrap.php"; throw new RuntimeException("Trial failure in a page");\n')
open(app + '/public/ops-trial-undefined.php', 'w').write('<?php require __DIR__."/../app/bootstrap.php"; crewvia_trial_no_such_function();\n')
open(app + '/public/ops-trial-memory.php', 'w').write('<?php require __DIR__."/../app/bootstrap.php"; ini_set("memory_limit","24M"); $a=[]; while(true){$a[]=str_repeat("x",1048576);}\n')
before = len(log_entries())

# ── an exception: logged, and the person sees only a reference ──────────
anon = Client()
status, body = anon.get('/ops-trial-throw.php?token=trial-secret-token-123', strict=False)
ref = re.search(r'id="error-reference"[^>]*>([0-9a-f]{12})<', body)
check('An uncaught exception answers 500 with a reference, the same as the X-Request-Id header', status == 500 and ref and anon.headers.get('X-Request-Id') == ref[1], body[:300])
check('and shows nothing of the error: no message, no class, no file', 'Trial failure' not in body and 'RuntimeException' not in body and '.php' not in body)
entry = next((e for e in log_entries() if e['request_id'] == (ref[1] if ref else '')), None)
check('The error log holds it under that reference: class, message, file and line, the path', entry and entry['class'] == 'RuntimeException' and entry['message'] == 'Trial failure in a page'
      and entry['file'] == 'public/ops-trial-throw.php' and entry['line'] == 1 and entry['path'] == '/ops-trial-throw.php' and entry['method'] == 'GET', entry)
check('The query string is never logged (links carry tokens)', 'trial-secret-token-123' not in raw_logs())
status, body = anon.get('/ops-trial-undefined.php', strict=False)
check('A call to a function that does not exist is caught the same way', status == 500 and any(e['class'] == 'Error' and 'crewvia_trial_no_such_function' in e['message'] for e in log_entries()))
status, body = anon.get('/ops-trial-memory.php', strict=False)
check('An out-of-memory fatal, which no handler can catch, is still logged at shutdown',
      any(e['kind'] == 'fatal' and 'memory' in e['message'].lower() and e['file'] == 'public/ops-trial-memory.php' for e in log_entries()), log_entries()[-1:])
check('Each failure is logged once', len(log_entries()) == before + 3, len(log_entries()) - before)
plain = Client()
plain.get('/login')
check('Every page carries its request id', bool(re.fullmatch(r'[0-9a-f]{12}', plain.headers.get('X-Request-Id') or '')), plain.headers.get('X-Request-Id'))

# ── /health for an uptime monitor ─────────────────────────────────────
status, body = anon.get('/health')
check('/health answers 200 {"status":"ok"} to anyone, and nothing more', status == 200 and json.loads(body) == {'status': 'ok'}
      and anon.headers.get('Content-Type', '').startswith('application/json') and 'no-store' in (anon.headers.get('Cache-Control') or ''), (body, dict(anon.headers)))

# ── the scheduled jobs record their runs; old error logs are purged ────
old = logs + '/errors-2000-01-01.log'
open(old, 'w').write('{"at":"2000-01-01T00:00:00+00:00"}\n')
today = logs + '/errors-%s.log' % datetime.date.today().isoformat()
code, out = cli('run-workflows.php')
w = next(r for r in runs() if r['kind'] == 'workflows')
check('The workflow pass records its run', code == 0 and w['status'] == 'ok' and json.loads(w['detail'])['error_logs_purged'] >= 1, (out, w))
check('and deletes error logs past 30 days, keeping the recent ones', not os.path.exists(old) and os.path.exists(today))
code, out = cli('run-report-schedules.php')
check('The report schedules record their run', code == 0 and runs()[0]['kind'] == 'report_schedules' and runs()[0]['status'] == 'ok', out)

# ── System health, for administrators ─────────────────────────────────
admin = Client()
admin.login('admin@test.invalid')
status, body = admin.get('/system-health')
state = dict(re.findall(r'data-check="([a-z_]+)" data-status="([a-z]+)"', body))
check('System health lists every check with its state', status == 200 and {'database', 'storage', 'disk', 'debug', 'encryption_key', 'backup', 'restore_drill', 'workflows', 'report_schedules', 'errors'} <= set(state), state)
check('It reports what is true of this test install: database and storage fine, debug on (failing), the workflows just ran',
      state['database'] == 'ok' and state['storage'] == 'ok' and state['debug'] == 'fail' and state['workflows'] == 'ok' and state['encryption_key'] == 'ok', state)
check('and the errors of the week, grouped by the place in the code', state['errors'] == 'warn' and 'data-error=' in body and 'Trial failure in a page' in body and 'public/ops-trial-throw.php:1' in body)
check('and the record of the runs', 'data-run="workflows" data-status="ok"' in body and 'data-run="report_schedules"' in body)
rec = Client()
rec.login('sr-recruiter@test.invalid')
check('A recruiter does not see System health', rec.get('/system-health')[0] == 403)

# ── the health check for cron ─────────────────────────────────────────
code, out = cli('health-check.php')
check('The cron health check prints every check and exits 1 when one fails', code == 1 and 'FAIL debug' in out and 'OK   database' in out, out)
code, out = cli('health-check.php', '--quiet')
check('With --quiet it prints only what needs looking at, so cron mails only then', code == 1 and 'FAIL debug' in out and 'OK   database' not in out, out)
check('Its runs are recorded too', runs()[0]['kind'] == 'health_check' and runs()[0]['status'] == 'failed')

# ── translations ─────────────────────────────────────────────────────
status, body = admin.get('/system-health?lang=fr')
check('System health in French', 'État du système' in body and 'Sauvegardes' in body)
status, body = admin.get('/system-health?lang=es')
check('and in Spanish', 'Estado del sistema' in body and 'Copias de seguridad' in body)
admin.get('/system-health?lang=en')

for f in ['ops-trial-throw.php', 'ops-trial-undefined.php', 'ops-trial-memory.php']:
    os.remove(app + '/public/' + f)
json.dump(results, open('work/ops-http-results.json', 'w'), indent=1)
