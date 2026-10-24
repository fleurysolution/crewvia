<?php
/**
 * P5-M109: separate real records from the demonstration workspace before it is flushed.
 *
 * Real requisitions were raised on a demonstration project (RSS's live jobs on "Plant 1"), so their applications and
 * the real people placed on that project hang from a demo record: install/demo-flush.php would remove them with it.
 * This moves the given requisitions - with their scope lines (copied when a demo requisition shares one), the
 * project-level rows of their applications, and the REAL people placed on that project - to a real project.
 *
 *   php install/demo-separate.php --from=6 --to=1 --vacancies=23,24,25,26 --expect-applications=431
 *       dry run: shows everything, changes nothing
 *   ... --execute
 *       does it, in one transaction
 *
 * Take a backup first, and prove it restores (install/restore.php --drill). In one transaction; ROLLED BACK if any
 * count differs from what was announced, if --expect-applications does not match, or if, read again afterwards, a
 * moved requisition, application or placement is still under the demonstration project or would still go with a flush.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/demo.php';

$opt = getopt('', ['from:', 'to:', 'vacancies:', 'expect-applications:', 'execute']);
$from = (int) ($opt['from'] ?? 0);
$to = (int) ($opt['to'] ?? 0);
$vacancies = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($opt['vacancies'] ?? ''))))));
$expect = isset($opt['expect-applications']) ? (int) $opt['expect-applications'] : null;
$execute = isset($opt['execute']);
$say = static fn(string $s) => print($s . PHP_EOL);
$in = static fn(array $ids): string => $ids ? implode(',', array_map('intval', $ids)) : '0';
$demoIds = static fn(string $t): array => array_map('intval', array_column(rows('SELECT record_id FROM demo_records WHERE table_name = ?', [$t]), 'record_id'));

// ── checks before anything ────────────────────────────────────────────────────
$fromJob = row('SELECT id, title FROM jobs WHERE id = ?', [$from]);
$toJob = row('SELECT id, title FROM jobs WHERE id = ?', [$to]);
if (! $fromJob || ! $toJob || ! $vacancies) { $say('Usage: --from=<demo project id> --to=<real project id> --vacancies=<ids> [--expect-applications=N] [--execute]'); exit(2); }
if (! in_array($from, $demoIds('jobs'), true)) { $say('Refused: project #' . $from . ' is not a demonstration project.'); exit(2); }
if (in_array($to, $demoIds('jobs'), true)) { $say('Refused: project #' . $to . ' is a demonstration project; the target must be real.'); exit(2); }
$found = array_map('intval', array_column(rows('SELECT id FROM vacancies WHERE job_id = ? AND id IN (' . $in($vacancies) . ')', [$from]), 'id'));
if (count($found) !== count($vacancies)) { $say('Refused: not every requisition given is on project #' . $from . ' (found: ' . implode(',', $found) . ').'); exit(2); }

$demoPeople = $demoIds('candidates');
$apps = array_map('intval', array_column(rows('SELECT id FROM applications WHERE vacancy_id IN (' . $in($vacancies) . ')'), 'id'));
$perReq = rows('SELECT v.id, v.title, (SELECT COUNT(*) FROM applications a WHERE a.vacancy_id = v.id) AS apps FROM vacancies v WHERE v.id IN (' . $in($vacancies) . ') ORDER BY v.id');
$realPlacements = array_map('intval', array_column(rows('SELECT id FROM placements WHERE job_id = ? AND candidate_id NOT IN (' . $in($demoPeople) . ')', [$from]), 'id'));
$lines = array_map('intval', array_column(rows('SELECT DISTINCT order_line_id FROM vacancies WHERE id IN (' . $in($vacancies) . ') AND order_line_id IS NOT NULL'), 'order_line_id'));
$shared = $lines ? array_map('intval', array_column(rows('SELECT DISTINCT order_line_id FROM vacancies WHERE order_line_id IN (' . $in($lines) . ') AND id NOT IN (' . $in($vacancies) . ')'), 'order_line_id')) : [];
$ownLines = array_values(array_diff($lines, $shared));

$say('From "' . $fromJob['title'] . '" (#' . $from . ', demonstration) to "' . $toJob['title'] . '" (#' . $to . ', real).');
foreach ($perReq as $r) { $say('  requisition #' . $r['id'] . ' ' . $r['title'] . ': ' . $r['apps'] . ' application(s)'); }
$say('Applications: ' . count($apps) . ($expect !== null ? ' (expected ' . $expect . ')' : '') . '; of people in the demonstration: ' . (int) val('SELECT COUNT(*) FROM applications WHERE id IN (' . $in($apps) . ') AND candidate_id IN (' . $in($demoPeople) . ')'));
$say('Scope lines: moved ' . ($ownLines ? implode(', ', $ownLines) : 'none') . '; copied (also used by a demo requisition) ' . ($shared ? implode(', ', $shared) : 'none'));
$say('Real people placed on the demo project: ' . count($realPlacements) . ($realPlacements ? ' - ' . implode('; ', array_map(fn($r) => $r['full_name'] . ' (placement #' . $r['id'] . ', ' . $r['status'] . ')', rows('SELECT p.id, p.status, c.full_name FROM placements p JOIN candidates c ON c.id = p.candidate_id WHERE p.id IN (' . $in($realPlacements) . ')'))) : ''));
if ($expect !== null && $expect !== count($apps)) { $say('Refused: ' . count($apps) . ' applications found, ' . $expect . ' expected. Nothing changed.'); exit(1); }

// ── what moves: every project-level row that belongs to what moves ──────────────
$schema = demo_schema();
$moves = ['vacancies' => ['id IN (' . $in($vacancies) . ')', count($vacancies)]];
if ($ownLines) { $moves['job_order_lines'] = ['id IN (' . $in($ownLines) . ')', count($ownLines)]; }
if ($realPlacements) { $moves['placements'] = ['id IN (' . $in($realPlacements) . ')', count($realPlacements)]; }
foreach ($schema as $table => $info) {
    if (isset($moves[$table]) || ! in_array('job_id', $info['columns'], true) || in_array($table, ['jobs', 'demo_records', 'job_order_lines'], true)) {
        continue;
    }
    $own = [];
    if (in_array('vacancy_id', $info['columns'], true)) { $own[] = 'vacancy_id IN (' . $in($vacancies) . ')'; }
    if (in_array('application_id', $info['columns'], true)) { $own[] = 'application_id IN (' . $in($apps) . ')'; }
    if (in_array('placement_id', $info['columns'], true) && $realPlacements) { $own[] = 'placement_id IN (' . $in($realPlacements) . ')'; }
    if (! $own) { continue; }
    $where = 'job_id = ' . $from . ' AND (' . implode(' OR ', $own) . ')';
    if ($n = (int) val('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $where)) { $moves[$table] = [$where, $n]; }
}
$say('');
$say('Rows that move to project #' . $to . ':');
foreach ($moves as $table => [$where, $n]) { $say('  ' . str_pad($table, 32) . $n); }
if ($shared) { $say('  ' . str_pad('job_order_lines (copied)', 32) . count($shared)); }

$before = demo_real_at_risk(demo_plan());
$say('');
$say('A flush TODAY would remove these records of real people: ' . ($before ? json_encode($before) : 'none'));
if (! $execute) {
    $say('Dry run: nothing changed. Add --execute to move (after a backup proven by install/restore.php --drill).');
    exit(0);
}

db()->beginTransaction();
try {
    // A line a demo requisition also uses is copied to the real project; the moved requisitions point at the copy.
    $lineCols = array_values(array_filter($schema['job_order_lines']['columns'] ?? [], fn($c) => $c !== 'id'));
    foreach ($shared as $lineId) {
        $cols = implode(',', array_map(fn($c) => '`' . $c . '`', $lineCols));
        $sel = implode(',', array_map(fn($c) => $c === 'job_id' ? (string) $to : '`' . $c . '`', $lineCols));
        q('INSERT INTO job_order_lines (' . $cols . ') SELECT ' . $sel . ' FROM job_order_lines WHERE id = ?', [$lineId]);
        $copy = (int) db()->lastInsertId();
        q('UPDATE vacancies SET order_line_id = ? WHERE order_line_id = ? AND id IN (' . $in($vacancies) . ')', [$copy, $lineId]);
    }
    foreach ($moves as $table => [$where, $n]) {
        $done = q('UPDATE `' . $table . '` SET job_id = ? WHERE ' . $where, [$to])->rowCount();
        if ($done !== $n) { throw new RuntimeException($table . ': ' . $n . ' announced, ' . $done . ' moved'); }
    }
    // Read again, from the database, not from what was meant to move.
    $still = (int) val('SELECT COUNT(*) FROM vacancies WHERE id IN (' . $in($vacancies) . ') AND job_id <> ?', [$to]);
    $appsNow = (int) val('SELECT COUNT(*) FROM applications a JOIN vacancies v ON v.id = a.vacancy_id WHERE v.id IN (' . $in($vacancies) . ') AND v.job_id = ?', [$to]);
    $placeNow = $realPlacements ? (int) val('SELECT COUNT(*) FROM placements WHERE id IN (' . $in($realPlacements) . ') AND job_id = ?', [$to]) : 0;
    if ($still || $appsNow !== count($apps) || $placeNow !== count($realPlacements)) {
        throw new RuntimeException('read again: ' . $still . ' requisition(s) not moved, ' . $appsNow . '/' . count($apps) . ' applications, ' . $placeNow . '/' . count($realPlacements) . ' placements');
    }
    $plan = demo_plan();
    $planIds = static fn(string $t): array => array_map(fn($r) => (int) $r['id'], $plan[$t] ?? []);
    $leak = count(array_intersect($planIds('vacancies'), $vacancies)) + count(array_intersect($planIds('applications'), $apps)) + count(array_intersect($planIds('placements'), $realPlacements));
    if ($leak) { throw new RuntimeException('a flush would still remove ' . $leak . ' of the moved records'); }
    db()->commit();
} catch (Throwable $e) {
    if (db()->inTransaction()) { db()->rollBack(); }
    $say('ROLLED BACK, nothing changed: ' . $e->getMessage());
    exit(1);
}
log_activity('separated real records from the demonstration', 'job', $to, implode(',', $vacancies) . ' from #' . $from . ', ' . count($apps) . ' applications');
$after = demo_real_at_risk(demo_plan());
$say('Moved: ' . count($vacancies) . ' requisitions, ' . count($apps) . ' applications (read again), ' . count($realPlacements) . ' placements.');
$say('A flush now would remove these records of real people: ' . ($after ? json_encode($after) . ' - still to resolve before any flush (demo-flush refuses while there are some).' : 'none.'));
exit(0);
