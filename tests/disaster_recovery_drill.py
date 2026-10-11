"""P4-M07: backup, restore drill and disaster recovery with the shipped
tools (install/backup.php, install/restore.php), on the isolated test
server only, after the whole suite has filled the test database.

  1. A backup: database, documents, manifest; the encryption key nowhere in it.
  2. A damaged copy, a forged manifest and a wrong key are refused.
  3. The drill restores into an empty scratch database, compares every
     table, decrypts a record, checks every document, and empties it again;
     it refuses a database that is not empty and the live one.
  4. The disaster: the test database dropped and the documents folder
     deleted. /health says so. The live restore brings both back, and the
     application works: sign-in, pages, the same counts, the same documents,
     the audit log still append-only. The time is measured.
  5. A restore with its safety backup; retention.
The rest of the suite then runs on the restored database.
"""
import glob, gzip, hashlib, http.cookiejar, json, os, pathlib, re, shutil, subprocess, sys, tarfile, time, urllib.error, urllib.parse, urllib.request

php = os.environ.get('PHP_BIN', 'php')
bin_dir = os.environ['MYSQL_BIN_DIR']
mysql = [os.path.join(bin_dir, 'mysql.exe'), '--host=127.0.0.1', '--port=3308', '--user=root']
app = pathlib.Path('work/test-app')
storage = app / 'storage'
backups = pathlib.Path('work/dr-backups')
base = 'http://127.0.0.1:8097'
env = dict(os.environ, MYSQL_BIN_DIR=bin_dir)
results, measured = [], {}


def check(label, condition, detail=''):
    if not condition:
        print('FAIL ' + label + (' - ' + str(detail)[:800] if detail else ''), flush=True)
        sys.exit(1)
    results.append(label)
    print('PASS ' + label, flush=True)


def tool(script, *args):
    started = time.perf_counter()
    p = subprocess.run([php, str(app / 'install' / script), *map(str, args)], capture_output=True, text=True, env=env)
    return p.returncode, p.stdout + p.stderr, time.perf_counter() - started


def sql(statement, db=''):
    return subprocess.run(mysql + ([db] if db else []) + ['-N', '-e', statement], capture_output=True, text=True)


def scalar(statement, db='rss_ops_test'):
    return sql(statement, db).stdout.strip()


def fetch(path, opener=None, data=None):
    opener = opener or urllib.request.build_opener()
    try:
        with opener.open(urllib.request.Request(base + path, urllib.parse.urlencode(data).encode() if data else None), timeout=60) as r:
            return r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()


def sign_in(email):
    o = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    _, body = fetch('/login', o)
    token = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)[1]
    status, body = fetch('/login', o, {'_csrf': token, 'email': email, 'password': 'TestPassword123!'})
    return o, status == 200 and '<title>Sign in' not in body


def storage_state():
    out = {}
    for f in storage.rglob('*'):
        if f.is_file() and not f.name.startswith('.health-') and 'tenants' not in f.relative_to(storage).parts[:1]:
            out[f.relative_to(storage).as_posix()] = hashlib.sha256(f.read_bytes()).hexdigest()
    return out


def latest():
    return sorted(backups.glob('crewvia-*'))[-1]


shutil.rmtree(backups, ignore_errors=True)
key = re.search(r"'encryption_key'=>'([^']+)'", (app / 'config.php').read_text())[1]

# ── 1. the backup ────────────────────────────────────────────────────
code, out, seconds = tool('backup.php', '--dir=' + str(backups))
check('install/backup.php takes a backup', code == 0 and 'Backup: crewvia-' in out, out)
measured['backup_seconds'] = round(seconds, 2)
b = latest()
manifest = json.loads((b / 'manifest.json').read_text())
check('It holds the database, the documents, their list and a manifest', sorted(p.name for p in b.iterdir()) == ['database.sql.gz', 'manifest.json', 'storage-files.json', 'storage.tar.gz'])
check('The manifest fingerprints each file', all(hashlib.sha256((b / n).read_bytes()).hexdigest() == f['sha256'] for n, f in manifest['files'].items()))
tables = int(scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='rss_ops_test' AND table_type='BASE TABLE'"))
check('and lists every table (%d) with its rows and checksum, and the triggers' % tables, len(manifest['tables']) == tables and 'activity_no_update' in manifest['triggers']
      and manifest['tables']['candidates']['rows'] == int(scalar('SELECT COUNT(*) FROM candidates')), (len(manifest['tables']), tables))
docs = storage_state()
check('Every document is in it (%d), each with its SHA-256' % len(docs), json.loads((b / 'storage-files.json').read_text()).keys() == docs.keys() and manifest['storage_files'] == len(docs) and len(docs) > 0)
dump = gzip.open(b / 'database.sql.gz').read().decode('utf-8', 'replace')
with tarfile.open(b / 'storage.tar.gz') as t:
    archive = b''.join(t.extractfile(m).read() for m in t.getmembers() if m.isfile())
check('The encryption key is nowhere in the backup', key not in dump and key.encode() not in archive and 'encryption_key' not in dump and key not in (b / 'manifest.json').read_text())
check('The dump is complete and carries the audit log triggers', '-- Dump completed' in dump[-300:] and 'activity_no_update' in dump)

# ── 2. damage, forgery, the wrong key ─────────────────────────────────
code, out, _ = tool('restore.php', '--check', b)
check('--check finds the backup whole', code == 0 and 'is whole' in out, out)
bad = backups / 'crewvia-19990101-000000'
shutil.copytree(b, bad)
with open(bad / 'storage.tar.gz', 'ab') as f:
    f.write(b'x')
code, out, _ = tool('restore.php', '--check', bad)
check('A damaged copy is refused', code == 1 and 'storage.tar.gz does not match its fingerprint' in out, out)
shutil.rmtree(bad)
shutil.copytree(b, bad)
with open(bad / 'storage-files.json', 'a') as f:
    f.write(' ')
forged = json.loads((bad / 'manifest.json').read_text())
forged['files']['storage-files.json']['sha256'] = hashlib.sha256((bad / 'storage-files.json').read_bytes()).hexdigest()
(bad / 'manifest.json').write_text(json.dumps(forged, indent=4))
code, out, _ = tool('restore.php', '--check', bad)
check('Files and fingerprints changed together are refused: the manifest is signed', code == 1 and 'not signed by this workspace' in out, out)
shutil.rmtree(bad)
config = (app / 'config.php').read_bytes()
try:
    (app / 'config.php').write_bytes(config.replace(key.encode(), b'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB='))
    code, out, _ = tool('restore.php', '--check', b)
finally:
    (app / 'config.php').write_bytes(config)
check('With another encryption key configured, the backup is refused before anything is touched', code == 1 and 'not the one this backup was taken with' in out, out)

# ── 3. the drill ─────────────────────────────────────────────────────
sql('DROP DATABASE IF EXISTS rss_ops_drill; CREATE DATABASE rss_ops_drill')
code, out, seconds = tool('restore.php', '--drill', '--target-db=rss_ops_drill', b)
check('The restore drill passes: every table the same, a record decrypted, every document checked', code == 0 and 'Restore drill PASSED' in out and 'decrypts with the configured key' in out, out)
measured['drill_seconds'] = round(seconds, 2)
check('and leaves the scratch database empty', scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='rss_ops_drill'", '') == '0')
sql('CREATE TABLE stray (id INT)', 'rss_ops_drill')
code, out, _ = tool('restore.php', '--drill', '--target-db=rss_ops_drill', b)
check('It refuses a database that is not empty', code == 1 and 'is not empty' in out, out)
sql('DROP DATABASE rss_ops_drill')
code, out, _ = tool('restore.php', '--drill', '--target-db=rss_ops_test', b)
check('and never drills into the live database', code == 1 and 'never restores into the live database' in out, out)
code, out, _ = tool('restore.php', '--live', b)
check('A live restore without the database name repeated is refused', code == 1 and '--confirm=rss_ops_test' in out, out)

# ── 4. the disaster ──────────────────────────────────────────────────
counts = {t: scalar('SELECT COUNT(*) FROM ' + t) for t in ['users', 'candidates', 'placements', 'activity', 'payroll_exports']}
sql('DROP DATABASE rss_ops_test; CREATE DATABASE rss_ops_test')
shutil.rmtree(storage)
status, body = fetch('/health')
check('With the database and the documents gone, /health says the site is down', status == 503 and json.loads(body) == {'status': 'down'}, (status, body))
code, out, seconds = tool('restore.php', '--live', '--confirm=rss_ops_test', '--skip-safety-backup', b)
check('The live restore brings the database and the documents back', code == 0 and 'Live restore PASSED' in out, out)
measured['recovery_seconds'] = round(seconds, 2)
status, body = fetch('/health')
check('/health is up again', status == 200 and json.loads(body) == {'status': 'ok'})
check('Every count is what it was', {t: scalar('SELECT COUNT(*) FROM ' + t) for t in counts} == counts, counts)
check('Every document is back, byte for byte', storage_state() == docs)
opener, ok = sign_in('admin@test.invalid')
check('An administrator signs in and works', ok and fetch('/candidates', opener)[0] == 200 and fetch('/employees', opener)[0] == 200)
check('The audit log is still append-only', 'The audit log cannot be changed' in sql('UPDATE activity SET detail = detail LIMIT 1', 'rss_ops_test').stderr)

# ── 5. with its safety backup; retention ──────────────────────────────
before = len(list(backups.glob('crewvia-*')))
time.sleep(1)
code, out, _ = tool('restore.php', '--live', '--confirm=rss_ops_test', b)
check('A live restore first takes a safety backup of what is there', code == 0 and 'Safety backup of what was there' in out and len(list(backups.glob('crewvia-*'))) == before + 1, out)
aside = list(app.glob('storage.before-restore-*'))
check('and keeps the documents folder it replaced beside the new one', len(aside) == 1 and storage_state() == docs)
shutil.rmtree(aside[0])
for _ in range(2):
    time.sleep(1)
    tool('backup.php', '--dir=' + str(backups), '--keep=2', '--quiet')
check('Retention keeps the newest backups only', len(list(backups.glob('crewvia-*'))) == 2)
code, out, _ = tool('backup.php', '--dir=/home/someone/public_html/backups')
check('A backup folder under public_html is refused by the tool', code == 1 and 'public_html' in out, out)

print('PASS Measured here: backup %.1f s, restore drill %.1f s, recovery from total loss %.1f s (%d tables, %d documents)'
      % (measured['backup_seconds'], measured['drill_seconds'], measured['recovery_seconds'], tables, len(docs)), flush=True)
shutil.rmtree(backups, ignore_errors=True)
json.dump({'measured': measured, 'tables': tables, 'documents': len(docs), 'checks': results}, open('work/dr-results.json', 'w'), indent=1)
