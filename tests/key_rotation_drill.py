"""GAP-1002: rotating the encryption key, on the isolated test server, after
the whole suite has filled the test database.

  1. --check counts every encrypted value and document; one that opens with
     no key stops the rotation before anything changes.
  2. A limited start: the backup, the new key in config.php with the old one
     beside it, part of the data re-encrypted. Meanwhile the site reads both
     (documents under either key download), and the health check says a
     rotation is under way; a second --start is refused.
  3. --resume finishes: everything opens with the new key and decrypts to
     exactly what it was, the old key opens nothing, and it is gone from
     config.php. The site signs in and serves documents.
  4. The backup taken before the rotation is refused with the new key, and
     accepted once the old key is set back as previous_encryption_key.
The rest of the suite then runs under the new key.
"""
import hashlib, http.cookiejar, json, os, pathlib, re, shutil, subprocess, sys, urllib.error, urllib.parse, urllib.request

php = os.environ.get('PHP_BIN', 'php')
app = pathlib.Path('work/test-app')
config = app / 'config.php'
backups = pathlib.Path('work/kr-backups')
base = 'http://127.0.0.1:8097'
env = dict(os.environ, MYSQL_BIN_DIR=os.environ['MYSQL_BIN_DIR'])
results = []


def check(label, condition, detail=''):
    if not condition:
        print('FAIL ' + label + (' - ' + str(detail)[:800] if detail else ''), flush=True)
        sys.exit(1)
    results.append(label)
    print('PASS ' + label, flush=True)


def tool(*args):
    p = subprocess.run([php, str(app / 'install' / 'rotate-key.php'), *map(str, args)], capture_output=True, text=True, env=env)
    return p.returncode, p.stdout + p.stderr


def probe(*args):
    return json.loads(subprocess.run([php, 'work/key_rotation_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout)


def key_in_config(name='encryption_key'):
    m = re.search(r"['\"]%s['\"]\s*=>\s*['\"]([A-Za-z0-9+/=]+)['\"]" % name, config.read_text())
    return m[1] if m else None


def admin():
    # Many suites sign in as this account; its per-account sign-in limit is cleared first.
    probe('reset-login', 'admin@test.invalid')
    o = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    body = o.open(base + '/login').read().decode()
    token = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)[1]
    body = o.open(base + '/login', urllib.parse.urlencode({'_csrf': token, 'email': 'admin@test.invalid', 'password': 'TestPassword123!'}).encode()).read().decode()
    assert '<title>Sign in' not in body, re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', body.split('<body', 1)[-1]))[:300]
    return o


def download(o, path):
    try:
        with o.open(base + path) as r:
            return r.status, r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()


shutil.rmtree(backups, ignore_errors=True)
old = key_in_config()
docs = probe('documents')
check('The test database holds encrypted documents to rotate: a resume and a proof', docs['resume'] and docs['proof'], docs)

# ── 1. the check, and what stops a rotation ────────────────────────────
code, out = tool('--check')
check('--check counts every encrypted value and document, all under the current key', code == 0 and 'Everything opens with the current key' in out
      and re.search(r'worker_bank_details\.encrypted_details\s+[1-9]\d* current key', out) and re.search(r'files/resumes\s+[1-9]\d* current key', out), out)
before = probe('digest')
check('Every one of them decrypts (%d values and documents)' % len(before), before and all(v is not None for v in before.values()))
junk = app / 'storage' / 'resumes' / 'not-encrypted'
junk.write_bytes(b'a file nobody encrypted')
code, out = tool('--check')
check('One document that opens with no key fails the check, and is named', code == 1 and 'not-encrypted' in out and '1 unreadable' in out, out)
config_before = config.read_bytes()
code, out = tool('--start', '--dir=' + str(backups))
check('and stops the rotation before anything changes: no backup, the same config.php', code == 1 and 'nothing was changed' in out
      and config.read_bytes() == config_before and not backups.exists(), out)
junk.unlink()

# ── 2. a limited start: both keys, the site keeps working ───────────────
code, out = tool('--start', '--dir=' + str(backups), '--limit=5')
new = key_in_config()
check('A start takes a backup first', 'Backup before the rotation: crewvia-' in out and len(list(backups.glob('crewvia-*'))) == 1, out)
check('then writes a new key into config.php, keeping the old one beside it', new and new != old and key_in_config('previous_encryption_key') == old)
check('Limited to 5, it re-encrypts 5 and says the rotation is under way (exit 3)', code == 3 and 'Re-encrypted 5 value(s)' in out and 'still need the previous key' in out, out)
check('Neither key is printed', old not in out and new not in out)
o = admin()
status, resume = download(o, '/resumes?download=%d' % docs['resume']['id'])
check('Meanwhile the site serves a resume, whichever key it is under', status == 200 and hashlib.sha256(resume).hexdigest() == docs['resume']['file_hash'])
status, proof = download(o, '/proofs?download=%d' % docs['proof']['id'])
check('and a proof document', status == 200 and hashlib.sha256(proof).hexdigest() == docs['proof']['file_hash'])
hc = subprocess.run([php, str(app / 'install' / 'health-check.php')], capture_output=True, text=True)
check('The health check says a rotation is under way', 'WARN key_rotation' in hc.stdout, hc.stdout)
code, out = tool('--start', '--dir=' + str(backups))
check('A second start is refused while one is under way', code == 1 and 'already under way' in out and key_in_config('previous_encryption_key') == old, out)

# ── 3. resume, finish ───────────────────────────────────────────────────
code, out = tool('--resume')
check('--resume re-encrypts the rest and completes', code == 0 and 'Rotation complete' in out, out)
check('The previous key is gone from config.php; the new one stays', key_in_config('previous_encryption_key') is None and key_in_config() == new)
after = probe('digest')
check('Every value and document decrypts to exactly what it was', after == before, [k for k in before if before[k] != after.get(k)][:5])
check('The old key opens none of them any more', probe('opens', old) == 0)
check('The new key opens all of them', probe('opens', new) == len(before))
code, out = tool('--check')
check('--check agrees', code == 0 and 'Everything opens with the current key' in out, out)
o = admin()
status, resume = download(o, '/resumes?download=%d' % docs['resume']['id'])
check('An administrator signs in after the rotation and downloads the resume, byte for byte', status == 200 and hashlib.sha256(resume).hexdigest() == docs['resume']['file_hash'])
code, out = tool('--resume')
check('A resume with no rotation under way is refused', code == 1 and 'no rotation is under way' in out, out)

# ── 4. the backup taken before ──────────────────────────────────────────
pre = sorted(backups.glob('crewvia-*'))[0]
p = subprocess.run([php, str(app / 'install' / 'restore.php'), '--check', str(pre)], capture_output=True, text=True, env=env)
check('The backup taken before the rotation is refused with the new key alone', p.returncode == 1 and 'as previous_encryption_key' in p.stdout + p.stderr, p.stdout + p.stderr)
rotated = config.read_bytes()
try:
    config.write_bytes(rotated.replace(b"'encryption_key'=>'" + new.encode() + b"'", b"'encryption_key'=>'" + new.encode() + b"','previous_encryption_key'=>'" + old.encode() + b"'"))
    p = subprocess.run([php, str(app / 'install' / 'restore.php'), '--check', str(pre)], capture_output=True, text=True, env=env)
finally:
    config.write_bytes(rotated)
check('and accepted once the old key is set back as previous_encryption_key', p.returncode == 0 and 'is whole' in p.stdout, p.stdout + p.stderr)

shutil.rmtree(backups, ignore_errors=True)
json.dump(results, open('work/kr-results.json', 'w'), indent=1)
