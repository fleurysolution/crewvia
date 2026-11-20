"""Every milestone that changes behaviour has its report (from P5-M100 on).

A commit whose subject starts with "P5-M<n>" and that touches app/ or install/ must be covered by a file in
docs/implementation/:
  - a report whose name covers n: P5-M114_REPORT.md, or a range such as P5-M97-M99_REPORT.md;
  - or any document there that names "P5-M<n>" (the staging runbook covers P5-M107).
A suffixed commit (P5-M110b) is covered by its milestone's report.

Run from the repository root. Without git history (a copied tree), it says so and checks nothing.
"""
import pathlib
import re
import subprocess
import sys

FIRST = 100  # reports have been kept for every milestone since P5-M100
root = pathlib.Path(__file__).resolve().parent.parent
if len(sys.argv) > 1:
    root = pathlib.Path(sys.argv[1]).resolve()
docs = root / 'docs' / 'implementation'

try:
    log = subprocess.run(['git', '-C', str(root), 'log', '--format=%x1e%h %s', '--name-only'],
                         capture_output=True, text=True, encoding='utf-8', check=True).stdout
except (OSError, subprocess.CalledProcessError):
    print('SKIP No git history here: the report check runs from the repository.')
    sys.exit(0)

covered = set()
texts = {}
for f in docs.glob('*.md'):
    m = re.match(r'P5-M(\d+)(?:-M(\d+))?_REPORT\.md$', f.name)
    if m:
        lo = int(m.group(1)); hi = int(m.group(2) or lo)
        covered.update(range(lo, hi + 1))
    texts[f.name] = f.read_text(encoding='utf-8', errors='replace')

missing = []
checked = 0
for block in log.split('\x1e'):
    lines = [l for l in block.strip().splitlines() if l.strip()]
    if not lines:
        continue
    head, files = lines[0], lines[1:]
    m = re.match(r'(\w+) P5-M(\d+)[a-z]?\b', head)
    if not m or int(m.group(2)) < FIRST:
        continue
    if not any(f.startswith(('app/', 'install/')) for f in files):
        continue
    n = int(m.group(2))
    checked += 1
    if n in covered or any(re.search(r'P5-M%d(?![0-9])' % n, t) for t in texts.values()):
        continue
    missing.append(head)

if missing:
    print('FAIL A milestone changed the code without its report in docs/implementation/:')
    for h in missing:
        print('   ', h)
    sys.exit(1)
print('PASS Every milestone since P5-M%d that changed the code has its report (%d commits checked)' % (FIRST, checked))
