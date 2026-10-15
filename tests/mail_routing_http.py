"""P5-M47 through the screens: Email delivery shows and sets where candidates' emails go.
  - test mode, the test inbox and the reply address are shown and saved by an administrator;
  - the queue shows, for each queued invitation, where it will really be delivered;
  - a test inbox without {first}, or a bad reply address, is refused; only an administrator opens the page.
Runs after mail_routing_db.php (test mode on), on the walkthrough fixture.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
php = os.environ.get('PHP_BIN', 'php')
results = []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                return r.status, r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace')

    def get(self, path):
        status, body = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body

    def login(self, email, password='TestPassword123!'):
        self.get('/login')
        self.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': password}).encode()))

    def post(self, path, values, page):
        self.get(page)
        return self.open(urllib.request.Request(base + path, urllib.parse.urlencode({'_csrf': self.token, **values}).encode(), {'Accept': 'text/html'}))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS ' + label)


def setting(key):
    return subprocess.run([php, '-r', 'require "work/test-app/app/bootstrap.php"; echo (string) val("SELECT setting_value FROM platform_settings WHERE setting_key = ?", [$argv[1]]);', key],
                          check=True, capture_output=True, text=True).stdout


admin = Client(); admin.login('wk-admin@test.invalid')
status, body = admin.get('/email-delivery')
check('Email delivery says candidates\' emails are in test mode, with the test inbox and who answers',
      'id="candidate-mail"' in body and 'Test mode' in body and 'solutionsfleury+{first}@gmail.com' in body and 'pa@rsinc.com' in body)
queued = re.findall(r'<tr><td>([^<]+)</td><td class="small">(.*?)</td>', body)
check('The queue shows where each invitation will really go: the test inbox, not the candidate',
      queued and all('solutionsfleury+' in to for _, to in queued if '@test.invalid' in _ and not _.startswith('wk-')), queued[:3])

status, body = admin.post('/email-delivery', {'do': 'routing', 'test_mode': '1', 'test_to': 'solutionsfleury@gmail.com', 'reply_to': 'pa@rsinc.com'}, '/email-delivery')
check('A test inbox without {first} is refused, nothing changed', '{first}' in body and setting('candidate_mail_test_to') == 'solutionsfleury+{first}@gmail.com')
status, body = admin.post('/email-delivery', {'do': 'routing', 'test_mode': '1', 'test_to': 'solutionsfleury+{first}@gmail.com', 'reply_to': 'not an email'}, '/email-delivery')
check('A reply address that is not an email is refused', setting('candidate_mail_reply_to') == 'pa@rsinc.com')

status, body = admin.post('/email-delivery', {'do': 'routing', 'test_to': 'solutionsfleury+{first}@gmail.com', 'reply_to': 'pa@rsinc.com'}, '/email-delivery')
check('Unticked, test mode goes off and the page says candidates now receive their emails', setting('candidate_mail_test') == '0' and 'Live' in body)
status, body = admin.post('/email-delivery', {'do': 'routing', 'test_mode': '1', 'test_to': 'solutionsfleury+{first}@gmail.com', 'reply_to': 'pa@rsinc.com'}, '/email-delivery')
check('and back on', setting('candidate_mail_test') == '1' and 'Test mode' in body)

rec = Client(); rec.login('wk-rec@test.invalid')
status, body = rec.get('/email-delivery')
check('Only an administrator opens it', 'id="candidate-mail"' not in body, status)

json.dump(results, open('work/mr-http-results.json', 'w'), indent=1)
