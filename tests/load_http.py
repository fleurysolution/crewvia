"""P4-M07: many people at once, on a private Apache with mod_php (threads,
.htaccess, as production runs), against the test database after the
10,000-worker scale fixture.

  - 40 people sign in at the same moment, each in their own session;
  - they then use the busiest pages at once: 600 requests, every one must
    answer 200 with no PHP error and no error page;
  - every audit-log read they made is in the audit log (no write lost);
  - 12 payroll people accept the same bank change at the same instant:
    exactly one succeeds, the others are refused, one bank record results;
  - 20 wrong passwords for one account at the same instant: at most 10
    reach the password check, the rest are throttled, all are logged, and
    then the right password is refused too;
  - no error is written to the error log during all of it.

A lock-up would show as an error page or a database error, which fails the
first checks; the times themselves are recorded, not asserted.

The times are measured on this machine (Windows, local MariaDB). They show
the application stays correct under concurrency and give an order of
magnitude; they are not a capacity figure for the production server.
"""
import concurrent.futures, http.cookiejar, json, os, re, statistics, subprocess, sys, threading, time, urllib.error, urllib.parse, urllib.request

sys.path.insert(0, 'work')
import apache_server

PORT = 8096
base = 'http://127.0.0.1:%d' % PORT
php = os.environ.get('PHP_BIN', 'php')
results = []


def check(label, condition, detail=''):
    if not condition:
        print('FAIL ' + label + (' - ' + str(detail)[:600] if detail else ''), flush=True)
        if os.path.exists('work/apache-error.log'):
            print('Apache error log (last lines):\n' + ''.join(open('work/apache-error.log', errors='replace').readlines()[-25:]), flush=True)
        raise SystemExit(1)
    results.append(label)
    print('PASS ' + label, flush=True)


def fixture(*args):
    out = subprocess.run([php, 'work/load_fixture.php', *args], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if args else out


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def request(self, path, values=None):
        data = urllib.parse.urlencode({'_csrf': self.token, **values}).encode() if values is not None else None
        started = time.perf_counter()
        try:
            with self.opener.open(urllib.request.Request(base + path, data), timeout=120) as r:
                status, body = r.status, r.read().decode()
        except urllib.error.HTTPError as e:
            status, body = e.code, e.read().decode()
        except (ConnectionError, OSError) as e:
            # A connection cut mid-answer is a failure to report, not a crash of the test.
            status, body = 0, 'connection error: %r' % e
        elapsed = time.perf_counter() - started
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        broken = any(s in body for s in ['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'id="error-reference"'])
        return status, body, elapsed, broken

    def login(self, email, password='TestPassword123!'):
        self.request('/login')
        status, body, elapsed, broken = self.request('/login', {'email': email, 'password': password})
        return status == 200 and '<title>Sign in' not in body and not broken


def error_log_lines():
    total = 0
    for root, _, files in os.walk('work/test-app/storage'):
        for f in files:
            if f.startswith('errors-') and f.endswith('.log'):
                total += sum(1 for _ in open(os.path.join(root, f), encoding='utf-8'))
    return total


def pct(values, p):
    values = sorted(values)
    return values[min(len(values) - 1, int(round(p / 100 * (len(values) - 1))))]


print(fixture(), end='', flush=True)
fx = json.load(open('work/load.json'))
errors_before = error_log_lines()
server = apache_server.start('work/test-app/public', 'work', PORT, threads=64)
try:
    # The production .htaccess rules, which PHP's built-in server ignores.
    def code(path):
        try:
            with urllib.request.urlopen(base + path, timeout=30) as r:
                return r.status
        except urllib.error.HTTPError as e:
            return e.code
    check('Under Apache the shipped .htaccess rules hold: routes reach index.php, .htaccess and .sql files are refused',
          code('/health') == 200 and code('/login') == 200 and code('/.htaccess') == 403 and code('/schema.sql') == 403)

    # ── 40 sign-ins at the same moment ──────────────────────────────────
    desk = [Client() for _ in range(40)]
    with concurrent.futures.ThreadPoolExecutor(40) as pool:
        signed = list(pool.map(lambda ic: ic[1].login('load-desk-%02d@test.invalid' % (ic[0] + 1)), enumerate(desk)))
    check('40 people sign in at the same moment, each in their own session', all(signed), signed.count(False))
    # P5-M58: /assets is a screen and a folder; production answered the folder (403) and the screen never opened.
    status, body, _, broken = desk[0].request('/assets')
    check('Under Apache /assets opens the equipment screen, and the folder still serves its files',
          status == 200 and not broken and '<h1' in body and 'Assets' in body and code('/assets/crewvia-ui.css') == 200, status)

    # ── what one view costs the database, on a 1,000-person project ──────
    def statements(page):
        desk[0].request(page)                      # warm
        before = int(fixture('questions'))
        status, _, _, broken = desk[0].request(page)
        return int(fixture('questions')) - before - 1, status == 200 and not broken
    for page in ['/hours', '/roster']:
        n, ok = statements(page)
        check('%s for a 1,000-person project asks the database %d times, not once per person' % (page, n), ok and n < 300, n)

    # ── the busiest pages, all at once ───────────────────────────────────
    pages = ['/', '/overview', '/employees', '/candidates?q=synthetic', '/manning', '/roster', '/recruitment', '/hours', '/organization', '/client-orders',
             '/employees?q=synthetic-3-1', '/recruitment?page=5', '/people', '/audit-log', '/reports']
    plan = [(c, pages[(i + j) % len(pages)]) for i, c in enumerate(desk) for j in range(15)]
    audit_requests = sum(1 for _, p in plan if p == '/audit-log')
    timings, failures, by_page = [], [], {}
    lock = threading.Lock()

    def run(user_index):
        c = desk[user_index]
        for j in range(15):
            page = pages[(user_index + j) % len(pages)]
            status, body, elapsed, broken = c.request(page)
            with lock:
                timings.append(elapsed)
                by_page.setdefault(page, []).append(elapsed)
                if status != 200 or broken:
                    failures.append((page, status, body[:200]))

    started = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(40) as pool:
        list(pool.map(run, range(40)))
    wall = time.perf_counter() - started
    check('600 page requests by 40 people at once: every one answers, with no PHP error and no error page', len(timings) == 600 and not failures, failures[:3])
    p50, p95, p99, slowest = statistics.median(timings), pct(timings, 95), pct(timings, 99), max(timings)
    print('PASS Measured on this machine: %.1f requests a second; median %.2f s, 95th percentile %.2f s, 99th %.2f s, slowest %.2f s'
          % (len(timings) / wall, p50, p95, p99, slowest), flush=True)
    reads = fixture('read')
    check('Every audit-log read made under load is in the audit log: %d of %d' % (reads['audit_reads'], audit_requests), reads['audit_reads'] == audit_requests, reads)

    # ── one bank change, twelve people accepting it at the same instant ──
    pay = [Client() for _ in range(12)]
    with concurrent.futures.ThreadPoolExecutor(12) as pool:
        check('12 payroll people signed in', all(pool.map(lambda ic: ic[1].login('load-pay-%02d@test.invalid' % (ic[0] + 1)), enumerate(pay))))
    for c in pay:
        c.request('/change-requests')
    barrier = threading.Barrier(12)

    def decide(c):
        barrier.wait()
        return c.request('/change-requests', {'do': 'bank', 'request_id': fx['bank_request'], 'decision': 'approve', 'note': '', 'matched': '1'})[0]

    with concurrent.futures.ThreadPoolExecutor(12) as pool:
        codes = list(pool.map(decide, pay))
    reads = fixture('read')
    check('Twelve people accept the same bank change at the same instant: one succeeds, eleven are told it is decided',
          codes.count(200) == 1 and codes.count(422) == 11, codes)
    check('and the result is one approval, one bank record, one decision in the audit log',
          reads['bank_request']['status'] == 'approved' and reads['bank_rows'] == 1 and reads['bank_decisions_logged'] == 1, reads)

    # ── twenty wrong passwords for one account at the same instant ──────
    guessers = [Client() for _ in range(20)]
    for c in guessers:
        c.request('/login')
    barrier = threading.Barrier(20)

    def guess(c):
        barrier.wait()
        status, body, _, _ = c.request('/login', {'email': fx['target_email'], 'password': 'WrongGuess-%d' % id(c)})
        return '<title>Sign in' in body

    with concurrent.futures.ThreadPoolExecutor(20) as pool:
        refused = list(pool.map(guess, guessers))
    reads = fixture('read')
    check('Twenty wrong passwords at the same instant are all refused, and the attempt count is exactly 20',
          all(refused) and reads['login_attempts'] == 20, (refused.count(False), reads))
    # The limiter counts each attempt atomically, then reads the count back; in a race
    # it can read a count others have already raised, so it throttles early, never late.
    check('The limit holds under the race: at most 10 reach the password check, the rest are throttled, every one in the audit log (%d checked, %d throttled)'
          % (reads['failed_logged'], reads['throttled_logged']),
          reads['failed_logged'] <= 10 and reads['failed_logged'] + reads['throttled_logged'] == 20, reads)
    check('After that, even the right password is refused for this account', not Client().login(fx['target_email']))

    errors_after = error_log_lines()
    check('Nothing was written to the error log during the load', errors_after == errors_before, errors_after - errors_before)
    json.dump({'server': 'Apache 2.4 with mod_php, 64 threads, on the Windows test machine; MariaDB local', 'scale': '10,000 synthetic workers, 10 projects',
               'people_at_once': 40, 'requests': len(timings), 'seconds': round(wall, 2), 'requests_per_second': round(len(timings) / wall, 1),
               'median_s': round(p50, 3), 'p95_s': round(p95, 3), 'p99_s': round(p99, 3), 'slowest_s': round(slowest, 3),
               'by_page_median_s': {k: round(statistics.median(v), 3) for k, v in sorted(by_page.items())},
               'capacity_claim': False, 'checks': results}, open('work/load-results.json', 'w'), indent=1)
finally:
    apache_server.stop(server)
