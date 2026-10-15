"""P5-M46 through the screens: a printable job poster with a QR code that opens the application page.
  - Hiring and Requisitions link to it; it is a page of its own (no menu), with the facts in large type
    and the QR code for the site's configured address, a print button and the QR code alone as an image;
  - the Requisitions QR code also uses the configured address, not the address the office browses from;
  - only the recruiting desk opens it.
Runs after availability_db.php, on the walkthrough fixture.
"""
import http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
wk = json.load(open('work/walkthrough.json'))
php = os.environ.get('PHP_BIN', 'php')
results = []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def get(self, path):
        try:
            with self.opener.open(base + path) as r:
                status, body, url = r.status, r.read().decode('utf-8', 'replace'), r.geturl()
        except urllib.error.HTTPError as e:
            status, body, url = e.code, e.read().decode('utf-8', 'replace'), ''
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body, url

    def login(self, email, password='TestPassword123!'):
        self.get('/login')
        self.opener.open(urllib.request.Request(base + '/login', urllib.parse.urlencode({'_csrf': self.token, 'email': email, 'password': password}).encode()))


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:700] if detail else '')
    results.append(label)
    print('PASS ' + label)


app_url = subprocess.run([php, '-r', 'require "work/test-app/app/bootstrap.php"; global $config; echo rtrim((string) $config["app_url"], "/");'],
                         check=True, capture_output=True, text=True).stdout.strip()
rec = Client(); rec.login('wk-rec@test.invalid')
status, body, url = rec.get('/hiring?v=%d' % wk['vA'])
check('Hiring links the requisition\'s job poster', 'id="poster-link"' in body and ('/poster?id=%d' % wk['vA']) in body)
status, body, url = rec.get('/requisitions')
check('Requisitions links it too, and its QR code uses the site\'s configured address',
      ('data-poster="%d"' % wk['vA']) in body and 'location.origin' not in body and json.dumps(app_url) in body, re.findall(r'new QRCode\(el,\{text:[^+]*', body)[:1])

status, body, url = rec.get('/poster?id=%d' % wk['vA'])
check('The poster is a page of its own, without the menu', status == 200 and body.lstrip().lower().startswith('<!doctype html>') and 'class="sidebar' not in body and 'id="poster"' in body, body[:200])
check('with the facts in large type: the job, the pay an hour, the per diem, the openings',
      'Walk machinist req' in body and '$45.00' in body and 'an hour' in body and 'per diem, a day' in body and 'openings' in body, re.findall(r'class="facts">(.*?)</div>\s*</div>', body, re.S)[:1])
check('and the QR code for the application page, at the site\'s configured address',
      ('data-url="%s/apply?id=%d"' % (app_url, wk['vA'])) in body and 'Scan to apply' in body and '/assets/qrcode.min.js' in body)
check('It prints, and saves the QR code as an image', 'window.print()' in body and 'id="qr-download"' in body and '@media print' in body)
status, body, url = rec.get('/poster?id=%d&qr=1' % wk['vA'])
check('The QR code alone, to put on a website', 'class="qr-alone"' in body and 'id="poster"' not in body)
status, body, url = rec.get('/poster?id=999999')
check('A requisition that does not exist has no poster', status == 404 or 'does not exist' in body, status)

sup = Client(); sup.login('wk-sup@test.invalid')
status, body, url = sup.get('/poster?id=%d' % wk['vA'])
check('Only the recruiting desk opens it', 'id="poster"' not in body, (status, url))

json.dump(results, open('work/po-http-results.json', 'w'), indent=1)
