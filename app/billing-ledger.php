<?php
/**
 * P5-M100: verifiable billing.
 *
 * Crewvia is billed per person actually paid in a calendar month: anyone with at least one day validated by a
 * supervisor (a validated clock-in or an approved attendance day) or in a pay cycle (an approved or paid weekly
 * timesheet, a payroll adjustment), counted once whatever the number of sites. A person assigned who never worked
 * costs nothing; candidates and office staff never produce such a record.
 *
 * The ledger (billing_events) is append-only: each source record is written once, and each line carries the
 * SHA-256 of the previous one, so a line removed or changed breaks the chain. The database refuses UPDATE and
 * DELETE on it (triggers, install/upgrade.php). On the 1st, the month before is sealed (billing_closings): people,
 * events and a hash, stored and never recomputed. Fleury reads the sealed figure through a signed endpoint that
 * carries no personal data (contract v1.1, section 7), and keeps its own copy of the hash.
 */

declare(strict_types=1);

const BL_GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

/** The price: per person per month, and the monthly minimum (server config may override). */
function bl_rate(): float
{
    global $config;

    return (float) ($config['billing_rate_per_person'] ?? 10);
}

function bl_minimum(): float
{
    global $config;

    return (float) ($config['billing_monthly_minimum'] ?? 2500);
}

/** What a month comes to: people x rate, never under the minimum. */
function bl_amount(int $people): float
{
    return round(max($people * bl_rate(), bl_minimum()), 2);
}

function bl_valid_month(string $month): bool
{
    return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month);
}

/** The hash of one ledger line, over every field that matters, in a fixed order. */
function bl_line_hash(string $prev, int $chainNo, string $period, string $occurredOn, string $kind, int $candidateId, string $sourceTable, int $sourceId): string
{
    return hash('sha256', implode('|', [$prev, $chainNo, $period, $occurredOn, $kind, $candidateId, $sourceTable, $sourceId]));
}

/**
 * Writes the source records not yet in the ledger, oldest first, once each. Safe to run any time and from several
 * places at once (a named lock serialises the chain). Returns the number of lines written.
 */
function bl_sync(): int
{
    $sources = [
        // a day validated by the supervisor at the clock-in
        ['clock_ins', 'day_validated', "SELECT c.id, c.work_date AS d, p.candidate_id FROM clock_ins c JOIN placements p ON p.id = c.placement_id
            LEFT JOIN billing_events b ON b.source_table = 'clock_ins' AND b.source_id = c.id
            WHERE c.status = 'validated' AND b.id IS NULL ORDER BY c.work_date, c.id"],
        // a day approved on Attendance (entered for or by the worker, reviewed by the supervisor)
        ['attendance_records', 'day_validated', "SELECT a.id, a.work_date AS d, p.candidate_id FROM attendance_records a JOIN placements p ON p.id = a.placement_id
            LEFT JOIN billing_events b ON b.source_table = 'attendance_records' AND b.source_id = a.id
            WHERE a.status = 'approved' AND b.id IS NULL ORDER BY a.work_date, a.id"],
        // a week in the pay cycle
        ['timesheets', 'pay_cycle', "SELECT t.id, t.week_ending AS d, p.candidate_id FROM timesheets t JOIN placements p ON p.id = t.placement_id
            LEFT JOIN billing_events b ON b.source_table = 'timesheets' AND b.source_id = t.id
            WHERE t.status IN ('approved','paid') AND b.id IS NULL ORDER BY t.week_ending, t.id"],
        // money paid through a pay period that corrects or adds to a week
        ['payroll_adjustments', 'pay_cycle', "SELECT j.id, r.week_ending AS d, j.candidate_id FROM payroll_adjustments j JOIN payroll_runs r ON r.id = j.run_id
            LEFT JOIN billing_events b ON b.source_table = 'payroll_adjustments' AND b.source_id = j.id
            WHERE b.id IS NULL ORDER BY r.week_ending, j.id"],
    ];
    $pending = [];
    foreach ($sources as [$table, $kind, $sql]) {
        try {
            foreach (rows($sql) as $r) {
                $pending[] = ['table' => $table, 'kind' => $kind, 'id' => (int) $r['id'], 'd' => (string) $r['d'], 'cid' => (int) $r['candidate_id']];
            }
        } catch (Throwable $e) {
            // A source table this installation does not have yet adds nothing.
        }
    }
    if (! $pending) {
        return 0;
    }
    usort($pending, static fn($a, $b) => [$a['d'], $a['table'], $a['id']] <=> [$b['d'], $b['table'], $b['id']]);

    if ((int) val("SELECT GET_LOCK('crewvia_billing_ledger', 15)") !== 1) {
        return 0;
    }
    $written = 0;
    try {
        $last = row('SELECT chain_no, hash FROM billing_events ORDER BY chain_no DESC LIMIT 1');
        $chainNo = $last ? (int) $last['chain_no'] : 0;
        $prev = $last ? (string) $last['hash'] : BL_GENESIS;
        foreach ($pending as $e) {
            if (val('SELECT id FROM billing_events WHERE source_table = ? AND source_id = ?', [$e['table'], $e['id']])) {
                continue;
            }
            $chainNo++;
            $period = substr($e['d'], 0, 7);
            $hash = bl_line_hash($prev, $chainNo, $period, $e['d'], $e['kind'], $e['cid'], $e['table'], $e['id']);
            q('INSERT INTO billing_events (chain_no, period_month, occurred_on, kind, candidate_id, source_table, source_id, prev_hash, hash) VALUES (?,?,?,?,?,?,?,?,?)',
              [$chainNo, $period, $e['d'], $e['kind'], $e['cid'], $e['table'], $e['id'], $prev, $hash]);
            $prev = $hash;
            $written++;
        }
    } finally {
        val("SELECT RELEASE_LOCK('crewvia_billing_ledger')");
    }

    return $written;
}

/**
 * Recomputes the whole chain. Returns ['ok' => bool, 'checked' => n, 'broken_at' => chain_no|null]. Reads hashes and
 * fields only - it shows no content, only where the chain breaks.
 */
function bl_verify(): array
{
    $prev = BL_GENESIS;
    $expected = 1;
    $checked = 0;
    $stmt = db()->query('SELECT chain_no, period_month, occurred_on, kind, candidate_id, source_table, source_id, prev_hash, hash FROM billing_events ORDER BY chain_no');
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $no = (int) $r['chain_no'];
        $hash = bl_line_hash($prev, $no, (string) $r['period_month'], (string) $r['occurred_on'], (string) $r['kind'], (int) $r['candidate_id'], (string) $r['source_table'], (int) $r['source_id']);
        if ($no !== $expected || ! hash_equals($prev, (string) $r['prev_hash']) || ! hash_equals($hash, (string) $r['hash'])) {
            return ['ok' => false, 'checked' => $checked, 'broken_at' => $no !== $expected ? $expected : $no];
        }
        $prev = (string) $r['hash'];
        $expected++;
        $checked++;
    }
    // Lines cut from the end (or the table emptied, which TRUNCATE does without the triggers) leave a chain that
    // still links: every sealed month must still find its last line and its own figures.
    foreach (rows('SELECT period_month, billable_people, event_count, last_chain_no, ledger_hash FROM billing_closings ORDER BY last_chain_no') as $c) {
        $no = (int) $c['last_chain_no'];
        $lastHash = $no > 0 ? val('SELECT hash FROM billing_events WHERE chain_no = ?', [$no]) : BL_GENESIS;
        $m = row('SELECT COUNT(DISTINCT candidate_id) AS people, COUNT(*) AS events FROM billing_events WHERE period_month = ? AND chain_no <= ?', [$c['period_month'], $no]);
        if ($lastHash === null || $lastHash === false
            || ! hash_equals((string) $c['ledger_hash'], bl_closing_hash((string) $c['period_month'], (int) $m['people'], (int) $m['events'], $no, (string) $lastHash))
            || (int) $m['people'] !== (int) $c['billable_people'] || (int) $m['events'] !== (int) $c['event_count']) {
            return ['ok' => false, 'checked' => $checked, 'broken_at' => $no];
        }
    }

    return ['ok' => true, 'checked' => $checked, 'broken_at' => null];
}

/** The people and events of a month as the ledger holds them now. */
function bl_month(string $month): array
{
    $r = row('SELECT COUNT(DISTINCT candidate_id) AS people, COUNT(*) AS events FROM billing_events WHERE period_month = ?', [$month]) ?: [];

    return ['people' => (int) ($r['people'] ?? 0), 'events' => (int) ($r['events'] ?? 0)];
}

function bl_closing(string $month): ?array
{
    return row('SELECT * FROM billing_closings WHERE period_month = ?', [$month]);
}

/** The hash a close carries: its figures and the last line of the ledger it was sealed on. */
function bl_closing_hash(string $month, int $people, int $events, int $lastChainNo, string $lastLineHash): string
{
    return hash('sha256', implode('|', [$month, $people, $events, $lastChainNo, $lastLineHash]));
}

/**
 * Seals a finished month: syncs, checks the chain, then stores people, events and the hash, never to be recomputed.
 * Returns [closing row, null] or [null, why].
 */
function bl_close(string $month, ?int $by): array
{
    if (! bl_valid_month($month)) {
        return [null, t('Choose a month.')];
    }
    if ($month >= date('Y-m')) {
        return [null, t('Only a finished month is sealed.')];
    }
    if (bl_closing($month)) {
        return [null, t('This month is already sealed.')];
    }
    bl_sync();
    $check = bl_verify();
    if (! $check['ok']) {
        return [null, t('The ledger chain is broken at line :n: the month is not sealed. Contact Fleury Solutions.', ['n' => $check['broken_at']])];
    }
    $m = bl_month($month);
    $last = row('SELECT chain_no, hash FROM billing_events ORDER BY chain_no DESC LIMIT 1');
    $lastNo = $last ? (int) $last['chain_no'] : 0;
    $hash = bl_closing_hash($month, $m['people'], $m['events'], $lastNo, $last ? (string) $last['hash'] : BL_GENESIS);
    try {
        q('INSERT INTO billing_closings (period_month, billable_people, event_count, last_chain_no, ledger_hash, sealed_at, sealed_by) VALUES (?,?,?,?,?,?,?)',
          [$month, $m['people'], $m['events'], $lastNo, $hash, gmdate('Y-m-d H:i:s'), $by]);
    } catch (Throwable $e) {
        return [null, t('This month is already sealed.')];
    }
    log_activity('sealed the monthly billing count', 'billing', 0, $month . ': ' . $m['people'] . ' people');

    return [bl_closing($month), null];
}

/** Seals every finished month that has events and no close yet (the job on the 1st). Returns the months sealed. */
function bl_close_due(): array
{
    bl_sync();
    $sealed = [];
    foreach (rows('SELECT DISTINCT e.period_month FROM billing_events e LEFT JOIN billing_closings c ON c.period_month = e.period_month
                   WHERE c.id IS NULL AND e.period_month < ? ORDER BY e.period_month', [date('Y-m')]) as $r) {
        [$closing] = bl_close((string) $r['period_month'], null);
        if ($closing) {
            $sealed[] = (string) $r['period_month'];
        }
    }

    return $sealed;
}

/** Lines recorded for a sealed month after it was sealed (a day validated late): shown, never added to the close. */
function bl_after_seal(string $month): int
{
    $c = bl_closing($month);

    return $c ? (int) val('SELECT COUNT(*) FROM billing_events WHERE period_month = ? AND chain_no > ?', [$month, (int) $c['last_chain_no']]) : 0;
}

/**
 * The statement of a month, for RSS: one line per person counted - name, projects, days validated, weeks in the pay
 * cycle. A sealed month lists the people of its sealed lines only.
 */
function bl_statement(string $month): array
{
    $c = bl_closing($month);
    $limit = $c ? (int) $c['last_chain_no'] : PHP_INT_MAX;
    $rows = rows("SELECT e.candidate_id, c.full_name,
                         SUM(e.kind = 'day_validated') AS days, SUM(e.kind = 'pay_cycle') AS pay_lines,
                         MIN(e.occurred_on) AS first_day, MAX(e.occurred_on) AS last_day
                  FROM billing_events e LEFT JOIN candidates c ON c.id = e.candidate_id
                  WHERE e.period_month = ? AND e.chain_no <= ?
                  GROUP BY e.candidate_id, c.full_name ORDER BY c.full_name", [$month, $limit]);
    foreach ($rows as &$r) {
        $r['projects'] = implode(', ', array_column(rows("SELECT DISTINCT j.title FROM billing_events e
              JOIN placements p ON (e.source_table IN ('clock_ins','attendance_records','timesheets') AND p.id = (
                   CASE e.source_table WHEN 'clock_ins' THEN (SELECT placement_id FROM clock_ins WHERE id = e.source_id)
                                       WHEN 'attendance_records' THEN (SELECT placement_id FROM attendance_records WHERE id = e.source_id)
                                       ELSE (SELECT placement_id FROM timesheets WHERE id = e.source_id) END))
              JOIN jobs j ON j.id = p.job_id
              WHERE e.period_month = ? AND e.candidate_id = ? AND e.chain_no <= ? ORDER BY j.title", [$month, (int) $r['candidate_id'], $limit]), 'title'));
        $r['full_name'] = (string) ($r['full_name'] ?? t('Record removed'));
    }

    return $rows;
}
