"""P1-M07: backup and restore drill, on the isolated test database only.

After the whole suite has filled rss_ops_test:
  1. dump it with mysqldump, as a backup is taken on the server
  2. restore the dump into a fresh database, rss_ops_restore
  3. prove the copy is the original: the same tables, the same row
     counts, and the same CHECKSUM TABLE for every one
Nothing here touches anything but the isolated server on port 3308.
"""
import json, os, pathlib, subprocess, sys

bin_dir = pathlib.Path(os.environ['MYSQL_BIN_DIR'])
cli = [str(bin_dir / 'mysql.exe'), '--host=127.0.0.1', '--port=3308', '--user=root']
dump_file = pathlib.Path('work/backup-drill.sql')
results = []


def check(label, condition, detail=''):
    if not condition:
        print('FAIL ' + label + (' - ' + str(detail)[:400] if detail else ''), flush=True)
        sys.exit(1)
    results.append(label)
    print('PASS ' + label, flush=True)


def sql(database, query):
    out = subprocess.run(cli + [database, '-N', '-B', '-e', query], check=True, capture_output=True, text=True)
    return [line.split('\t') for line in out.stdout.splitlines() if line]


with dump_file.open('wb') as f:
    subprocess.run([str(bin_dir / 'mysqldump.exe'), '--host=127.0.0.1', '--port=3308', '--user=root',
                    '--single-transaction', '--routines', '--triggers', 'rss_ops_test'], check=True, stdout=f)
check('Backup: the test database dumps', dump_file.stat().st_size > 10000, dump_file.stat().st_size)

subprocess.run(cli + ['-e', 'DROP DATABASE IF EXISTS rss_ops_restore; CREATE DATABASE rss_ops_restore CHARACTER SET utf8mb4'], check=True)
with dump_file.open('rb') as f:
    subprocess.run(cli + ['rss_ops_restore'], check=True, stdin=f)
check('Restore: the dump loads into a fresh database', True)

tables = [r[0] for r in sql('rss_ops_test', 'SHOW TABLES')]
restored = [r[0] for r in sql('rss_ops_restore', 'SHOW TABLES')]
check('Restore: the same %d tables' % len(tables), tables == restored, (len(tables), len(restored)))

def fingerprint(database):
    """Every table's row count and checksum, in two statements, not two per table."""
    counts = sql(database, ' UNION ALL '.join("SELECT '%s', COUNT(*) FROM `%s`" % (t, t) for t in tables))
    sums = sql(database, 'CHECKSUM TABLE ' + ', '.join('`%s`' % t for t in tables))
    return ({name: int(n) for name, n in counts}, {name.split('.', 1)[1]: value for name, value in sums})


counts_a, sums_a = fingerprint('rss_ops_test')
counts_b, sums_b = fingerprint('rss_ops_restore')
check('Restore: every table was counted and checksummed', len(counts_a) == len(tables) == len(sums_a) == len(counts_b) == len(sums_b),
      (len(tables), len(counts_a), len(sums_a), len(counts_b), len(sums_b)))
different = [t for t in tables if counts_a[t] != counts_b[t] or sums_a[t] != sums_b[t]]
check('Restore: no table differs', not different, different[:5])
rows = sum(counts_a.values())
check('Restore: every table has the same rows and the same checksum (%d rows)' % rows, rows > 0)

# The comparison has to be able to fail: change one value in the copy and
# it must be seen.
subprocess.run(cli + ['rss_ops_restore', '-e', "UPDATE users SET name = CONCAT(name, ' changed') ORDER BY id LIMIT 1"], check=True)
_, sums_c = fingerprint('rss_ops_restore')
check('Restore: a single changed value in the copy is detected', sums_c['users'] != sums_a['users'])

subprocess.run(cli + ['-e', 'DROP DATABASE rss_ops_restore'], check=True)
dump_file.unlink()
json.dump(results, open('work/m07-backup-results.json', 'w'), indent=1)
