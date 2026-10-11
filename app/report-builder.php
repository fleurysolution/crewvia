<?php
/**
 * The report builder (P4-M03): saved reports on the P4-M01 reports, their
 * export under a policy per report, schedules, and delivery inside Crewvia.
 *
 *   saved report   a source report, the columns kept, a period (fixed, or
 *                  relative to the day it runs: this month, last month...),
 *                  up to five conditions on its rows, an order, a limit.
 *                  Private to its owner unless shared, and even shared it
 *                  opens only for someone who may read its source report.
 *   export         a CSV file of what the screen shows. Whoever may read a
 *                  report may export it, unless an administrator has
 *                  narrowed export to some roles. Every file is logged
 *                  with its filters, row count and SHA-256. Cells a
 *                  spreadsheet would run as a formula are neutralised.
 *   schedule       daily, weekly or monthly at an hour, for named people
 *                  who may both read and export the report.
 *   delivery       install/run-report-schedules.php (by cron, or an
 *                  administrator's "run what is due") runs each due
 *                  schedule once per slot, stores the file with its
 *                  SHA-256 and tells each recipient in Crewvia. Nothing is
 *                  emailed. The recipient downloads it signed in, within
 *                  30 days, and only while still allowed to; the file is
 *                  checked against its fingerprint first. After 30 days
 *                  the file is erased.
 */

declare(strict_types=1);

require_once __DIR__ . '/reports.php';

const RB_DELIVERY_DAYS = 30;

function rb_relative_periods(): array
{
    return ['fixed' => 'Fixed dates', 'today' => 'Today', 'this_week' => 'This week', 'last_week' => 'Last week', 'this_month' => 'This month',
            'last_month' => 'Last month', 'last_30_days' => 'The last 30 days', 'this_year' => 'This year', 'last_year' => 'Last year'];
}

function rb_operators(): array
{
    return ['eq' => 'is', 'ne' => 'is not', 'contains' => 'contains', 'gt' => 'more than', 'ge' => 'at least', 'lt' => 'less than', 'le' => 'at most',
            'empty' => 'is empty', 'filled' => 'is not empty'];
}

/** The dates a period stands for on a day. Weeks end on Saturday, as pay weeks do. */
function rb_resolve_period(array $filters, string $today): array
{
    $mode = (string) ($filters['period'] ?? 'fixed');
    $week = week_ending($today);
    [$from, $to] = match ($mode) {
        'today'        => [$today, $today],
        'this_week'    => [date('Y-m-d', strtotime($week . ' -6 days')), $today],
        'last_week'    => [date('Y-m-d', strtotime($week . ' -13 days')), date('Y-m-d', strtotime($week . ' -7 days'))],
        'this_month'   => [substr($today, 0, 7) . '-01', $today],
        'last_month'   => [date('Y-m-01', strtotime(substr($today, 0, 7) . '-01 -1 month')), date('Y-m-t', strtotime(substr($today, 0, 7) . '-01 -1 month'))],
        'last_30_days' => [date('Y-m-d', strtotime($today . ' -29 days')), $today],
        'this_year'    => [substr($today, 0, 4) . '-01-01', $today],
        'last_year'    => [((int) substr($today, 0, 4) - 1) . '-01-01', ((int) substr($today, 0, 4) - 1) . '-12-31'],
        default        => [valid_date((string) ($filters['from'] ?? '')) ? (string) $filters['from'] : substr($today, 0, 7) . '-01',
                           valid_date((string) ($filters['to'] ?? '')) ? (string) $filters['to'] : $today],
    };
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
    $asof = $mode === 'fixed' && valid_date((string) ($filters['asof'] ?? '')) ? (string) $filters['asof'] : $to;
    $year = $mode === 'fixed' && preg_match('/^\d{4}$/', (string) ($filters['year'] ?? '')) ? (int) $filters['year'] : (int) substr($to, 0, 4);

    return ['from' => $from, 'to' => $to, 'asof' => $asof, 'year' => $year, 'job_id' => (int) ($filters['job_id'] ?? 0)];
}

/** May a role read a report? Administrators read all. */
function rb_role_reads(string $role, string $source): bool
{
    $r = reports_catalog()[$source] ?? null;

    return $r !== null && ($role === 'admin' || in_array($role, $r['roles'], true));
}

/** The roles that may export a report: the policy, or its readers. */
function rb_export_roles(string $source): array
{
    $p = val('SELECT roles FROM report_export_policy WHERE source = ?', [$source]);

    return $p !== null ? array_values(array_filter(explode(',', (string) $p))) : (reports_catalog()[$source]['roles'] ?? []);
}

function rb_role_exports(string $role, string $source): bool
{
    return $role === 'admin' || (rb_role_reads($role, $source) && in_array($role, rb_export_roles($source), true));
}

function rb_can_export(string $source): bool
{
    return rb_role_exports((string) (user()['role'] ?? ''), $source);
}

/** A saved report this person may open, or null. */
function rb_definition(int $id): ?array
{
    $d = row('SELECT * FROM report_definitions WHERE id = ? AND archived_at IS NULL', [$id]);
    if (! $d || ! report_allowed((string) $d['source'])) {
        return null;
    }
    if ((int) $d['owner_id'] !== uid() && ! (int) $d['shared'] && ! can('admin')) {
        return null;
    }
    foreach (['columns', 'filters', 'conditions'] as $k) {
        $d[$k] = json_decode((string) $d[$k . '_json'], true) ?: [];
    }

    return $d;
}

function rb_may_change(array $d): bool
{
    return (int) $d['owner_id'] === uid() || can('admin');
}

/** Check and tidy what the form sent. Returns [definition fields, refusal]. */
function rb_input(array $in): array
{
    $name = trim((string) ($in['name'] ?? ''));
    $source = (string) ($in['source'] ?? '');
    $catalog = reports_catalog();
    if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
        return [null, t('Name the report in 3 to 120 characters.')];
    }
    if (! isset($catalog[$source]) || ! report_allowed($source)) {
        return [null, t('Choose a report you may read.')];
    }
    $columns = report_run_columns($source);
    $keep = array_values(array_intersect(array_keys($columns), array_map('strval', (array) ($in['columns'] ?? []))));
    if (! $keep) {
        return [null, t('Keep at least one column.')];
    }
    $period = (string) ($in['period'] ?? 'fixed');
    if (! isset(rb_relative_periods()[$period])) {
        return [null, t('Choose a period.')];
    }
    $filters = ['period' => $period, 'job_id' => (int) ($in['job_id'] ?? 0)];
    foreach (['from', 'to', 'asof'] as $k) {
        $v = trim((string) ($in[$k] ?? ''));
        if ($v !== '' && ! valid_date($v)) {
            return [null, t('Dates are written as year-month-day.')];
        }
        $filters[$k] = $v;
    }
    $filters['year'] = preg_match('/^\d{4}$/', (string) ($in['year'] ?? '')) ? (string) $in['year'] : '';
    if ($filters['job_id'] > 0 && ! val('SELECT COUNT(*) FROM jobs WHERE id = ?', [$filters['job_id']])) {
        return [null, t('That project does not exist.')];
    }
    $conditions = [];
    foreach ((array) ($in['cond_column'] ?? []) as $i => $col) {
        $col = (string) $col;
        $op = (string) (($in['cond_op'] ?? [])[$i] ?? '');
        $value = trim((string) (($in['cond_value'] ?? [])[$i] ?? ''));
        if ($col === '') {
            continue;
        }
        if (! isset($columns[$col]) || ! isset(rb_operators()[$op])) {
            return [null, t('A condition names a column of the report and how to compare it.')];
        }
        if (! in_array($op, ['empty', 'filled'], true) && $value === '') {
            return [null, t('Give the value each condition compares with.')];
        }
        if (in_array($op, ['gt', 'ge', 'lt', 'le'], true) && ! is_numeric($value)) {
            return [null, t('More than, at least, less than and at most compare with a number.')];
        }
        $conditions[] = [$col, $op, mb_substr($value, 0, 190)];
    }
    if (count($conditions) > 5) {
        return [null, t('At most five conditions.')];
    }
    $sort = (string) ($in['sort_column'] ?? '');
    if ($sort !== '' && ! isset($columns[$sort])) {
        return [null, t('Sort by a column of the report.')];
    }
    $limit = (int) ($in['row_limit'] ?? 1000);
    if ($limit < 1 || $limit > 5000) {
        return [null, t('Show between 1 and 5,000 rows.')];
    }

    return [['name' => $name, 'source' => $source, 'columns' => $keep, 'filters' => $filters, 'conditions' => $conditions,
             'sort_column' => $sort === '' ? null : $sort, 'sort_dir' => ($in['sort_dir'] ?? '') === 'desc' ? 'desc' : 'asc',
             'row_limit' => $limit, 'shared' => ! empty($in['shared']) ? 1 : 0], null];
}

/** The columns of a report, without running it. */
function report_run_columns(string $source): array
{
    static $cache = [];
    if (! isset($cache[$source])) {
        $cache[$source] = report_run($source, ['from' => '', 'to' => '', 'asof' => '', 'year' => 0, 'job_id' => 0, 'columns_only' => true])['columns'];
    }

    return $cache[$source];
}

/** Run a saved report on a day: the source report, then the builder's columns, conditions, order and limit. */
function rb_run(array $d, string $today): array
{
    $filters = rb_resolve_period($d['filters'], $today);
    $result = report_run((string) $d['source'], $filters);
    $columns = array_intersect_key($result['columns'], array_flip($d['columns']));
    $numeric = static fn(string $type): bool => in_array($type, ['money', 'int', 'num', 'pct'], true);
    $rows = array_values(array_filter($result['rows'], static function (array $r) use ($d, $result, $numeric): bool {
        foreach ($d['conditions'] as [$col, $op, $value]) {
            $v = $r[$col] ?? null;
            $type = $result['columns'][$col][1] ?? 'text';
            $text = mb_strtolower(trim((string) $v));
            $want = mb_strtolower($value);
            $ok = match ($op) {
                'empty'    => $v === null || $text === '',
                'filled'   => ! ($v === null || $text === ''),
                'contains' => $want !== '' && str_contains($text, $want),
                'eq'       => $numeric($type) && is_numeric($value) ? $v !== null && abs((float) $v - (float) $value) < 0.005 : $text === $want,
                'ne'       => $numeric($type) && is_numeric($value) ? $v === null || abs((float) $v - (float) $value) >= 0.005 : $text !== $want,
                'gt'       => $v !== null && (float) $v > (float) $value,
                'ge'       => $v !== null && (float) $v >= (float) $value,
                'lt'       => $v !== null && (float) $v < (float) $value,
                'le'       => $v !== null && (float) $v <= (float) $value,
                default    => false,
            };
            if (! $ok) {
                return false;
            }
        }
        return true;
    }));
    if ($d['sort_column']) {
        $col = (string) $d['sort_column'];
        $isNum = $numeric($result['columns'][$col][1] ?? 'text');
        usort($rows, static function ($a, $b) use ($col, $isNum, $d): int {
            $x = $a[$col] ?? null;
            $y = $b[$col] ?? null;
            $c = $isNum ? [(float) ($x ?? -INF)] <=> [(float) ($y ?? -INF)] : strcasecmp((string) $x, (string) $y);
            return $d['sort_dir'] === 'desc' ? -$c : $c;
        });
    }
    $total = count($rows);
    $rows = array_slice($rows, 0, (int) $d['row_limit']);
    // Totals of what is kept: a count, and the sum of each amount, number or count column.
    $first = array_key_first($columns);
    $totals = [$first => count($rows)];
    foreach ($columns as $c => [$label, $type]) {
        if ($c !== $first && in_array($type, ['money', 'int', 'num'], true)) {
            $totals[$c] = $type === 'int' ? (int) array_sum(array_map(fn($r) => (int) ($r[$c] ?? 0), $rows)) : round(array_sum(array_map(fn($r) => (float) ($r[$c] ?? 0), $rows)), 2);
        }
    }
    $notes = $result['notes'];
    if ($total > count($rows)) {
        $notes[] = t('The first :n of :m rows, as the report limit says.', ['n' => count($rows), 'm' => $total]);
    }

    return ['columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'notes' => $notes, 'filters' => $filters];
}

/** The file: a header of column names, then raw values; dates as year-month-day, amounts as numbers. */
function rb_csv(array $result): string
{
    $out = fopen('php://temp', 'r+');
    fputcsv($out, array_map(fn($c) => csv_cell($c[0]), array_values($result['columns'])), ',', '"', '');
    foreach ($result['rows'] as $r) {
        $line = [];
        foreach ($result['columns'] as $c => [$label, $type]) {
            $v = $r[$c] ?? null;
            // Numbers stay numbers (a negative one is not a formula); text is neutralised.
            $line[] = $v === null ? '' : (in_array($type, ['money', 'int', 'num', 'pct'], true)
                ? (is_float($v) ? number_format($v, 2, '.', '') : (string) (int) $v) : csv_cell((string) $v));
        }
        fputcsv($out, $line, ',', '"', '');
    }
    rewind($out);
    $csv = (string) stream_get_contents($out);
    fclose($out);

    return $csv;
}

function rb_log_export(string $kind, string $source, ?int $definitionId, ?int $deliveryId, array $params, int $rows, string $sha): void
{
    q('INSERT INTO report_exports (kind, source, definition_id, delivery_id, params_json, row_count, sha256, user_id) VALUES (?,?,?,?,?,?,?,?)',
      [$kind, $source, $definitionId, $deliveryId, json_encode($params), $rows, $sha, uid()]);
    log_activity($kind === 'download' ? 'exported a report' : 'downloaded a delivered report', 'report', $definitionId ?? 0, $source . ' ' . http_build_query($params) . ' sha256=' . $sha);
}

// ── schedules ────────────────────────────────────────────────────────────

/** The next time a schedule is due after a moment. */
function rb_next_run(array $s, string $after): string
{
    $t = strtotime($after);
    $hour = (int) $s['run_hour'];
    for ($day = 0; $day < 400; $day++) {
        $d = strtotime(date('Y-m-d', $t) . " +$day days");
        $at = strtotime(date('Y-m-d', $d) . sprintf(' %02d:00:00', $hour));
        $fits = match ((string) $s['frequency']) {
            'daily'   => true,
            'weekly'  => (int) date('w', $d) === (int) $s['weekday'],
            'monthly' => (int) date('j', $d) === (int) $s['monthday'],
            default   => false,
        };
        if ($fits && $at > $t) {
            return date('Y-m-d H:i:s', $at);
        }
    }

    throw new RuntimeException('No next run within 400 days');
}

/** Why a person may not receive a report, or null. */
function rb_recipient_refusal(array $u, string $source): ?string
{
    if (! (int) $u['is_active']) {
        return 'account inactive';
    }
    if (! rb_role_reads((string) $u['role'], $source)) {
        return 'may not read this report';
    }
    if (! rb_role_exports((string) $u['role'], $source)) {
        return 'may not export this report';
    }

    return null;
}

/** Check a schedule form. Returns [fields, refusal]. */
function rb_schedule_input(array $d, array $in): array
{
    $freq = (string) ($in['frequency'] ?? '');
    if (! in_array($freq, ['daily', 'weekly', 'monthly'], true)) {
        return [null, t('Choose how often it runs.')];
    }
    $hour = (int) ($in['run_hour'] ?? -1);
    if ($hour < 0 || $hour > 23) {
        return [null, t('Choose the hour it runs, 0 to 23.')];
    }
    $weekday = $freq === 'weekly' ? (int) ($in['weekday'] ?? -1) : null;
    $monthday = $freq === 'monthly' ? (int) ($in['monthday'] ?? 0) : null;
    if ($freq === 'weekly' && ($weekday < 0 || $weekday > 6)) {
        return [null, t('Choose the day of the week.')];
    }
    if ($freq === 'monthly' && ($monthday < 1 || $monthday > 28)) {
        return [null, t('Choose a day of the month from 1 to 28, so that every month has it.')];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['recipients'] ?? [])))));
    if (! $ids || count($ids) > 20) {
        return [null, t('Choose between 1 and 20 people to receive it.')];
    }
    foreach ($ids as $id) {
        $u = row('SELECT id, name, role, is_active FROM users WHERE id = ?', [$id]);
        if (! $u || in_array($u['role'], ['worker', 'supervisor', 'client'], true)) {
            return [null, t('Reports go to staff only.')];
        }
        if ($why = rb_recipient_refusal($u, (string) $d['source'])) {
            return [null, t(':name may not receive this report: :why.', ['name' => $u['name'], 'why' => t($why)])];
        }
    }

    return [['frequency' => $freq, 'weekday' => $weekday, 'monthday' => $monthday, 'run_hour' => $hour, 'recipients' => $ids], null];
}

/**
 * Run every schedule due at $now. Each slot runs once: the slot is claimed
 * by moving the schedule's next run forward, and the delivery is unique per
 * slot. Returns what was done, line by line, for the log.
 */
function rb_run_due(string $now): array
{
    $done = [];
    // Files past their 30 days are erased; the record of them stays.
    $expired = q("UPDATE report_deliveries SET csv = NULL, status = 'expired' WHERE status = 'ready' AND expires_at <= ?", [$now])->rowCount();
    if ($expired) {
        $done[] = "Expired $expired delivered file(s).";
    }
    foreach (rows('SELECT s.*, d.name, d.source, d.owner_id FROM report_schedules s JOIN report_definitions d ON d.id = s.definition_id
                   WHERE s.active = 1 AND s.next_run_at <= ? ORDER BY s.next_run_at, s.id', [$now]) as $s) {
        $next = rb_next_run($s, $now);
        if (q('UPDATE report_schedules SET next_run_at = ?, last_run_at = ? WHERE id = ? AND next_run_at = ?', [$next, $now, (int) $s['id'], $s['next_run_at']])->rowCount() !== 1) {
            continue;
        }
        $def = row('SELECT * FROM report_definitions WHERE id = ?', [(int) $s['definition_id']]);
        $owner = row('SELECT id, name, role, is_active FROM users WHERE id = ?', [(int) $def['owner_id']]);
        $expires = date('Y-m-d H:i:s', strtotime($now . ' +' . RB_DELIVERY_DAYS . ' days'));
        $fail = static function (string $why) use ($s, $def, $expires, &$done): void {
            q("INSERT IGNORE INTO report_deliveries (schedule_id, definition_id, run_for, period_label, status, note, expires_at) VALUES (?,?,?,?, 'failed', ?, ?)",
              [(int) $s['id'], (int) $def['id'], $s['next_run_at'], '', $why, $expires]);
            $done[] = 'Schedule ' . $s['id'] . ': not run, ' . $why . '.';
        };
        if ($def['archived_at'] !== null) {
            $fail('the saved report was archived');
            continue;
        }
        if (! $owner || ! (int) $owner['is_active'] || ! rb_role_reads((string) $owner['role'], (string) $def['source'])) {
            $fail('its owner may no longer read the report');
            continue;
        }
        foreach (['columns', 'filters', 'conditions'] as $k) {
            $def[$k] = json_decode((string) $def[$k . '_json'], true) ?: [];
        }
        $result = rb_run($def, substr($now, 0, 10));
        $csv = rb_csv($result);
        $f = $result['filters'];
        $label = $f['from'] === $f['to'] ? $f['from'] : $f['from'] . ' .. ' . $f['to'];
        // The slot is unique: if it was delivered already, it is not delivered again.
        if (q("INSERT IGNORE INTO report_deliveries (schedule_id, definition_id, run_for, period_label, status, csv, sha256, row_count, expires_at) VALUES (?,?,?,?, 'ready', ?, ?, ?, ?)",
              [(int) $s['id'], (int) $def['id'], $s['next_run_at'], $label, $csv, hash('sha256', $csv), count($result['rows']), $expires])->rowCount() !== 1) {
            $done[] = 'Schedule ' . $s['id'] . ': the slot ' . $s['next_run_at'] . ' was delivered already.';
            continue;
        }
        $delivery = (int) db()->lastInsertId();
        $sent = 0;
        foreach (json_decode((string) $s['recipients_json'], true) ?: [] as $uid) {
            $u = row('SELECT id, name, role, is_active FROM users WHERE id = ?', [(int) $uid]);
            $why = $u ? rb_recipient_refusal($u, (string) $def['source']) : 'account removed';
            if ($u === null) {
                continue;
            }
            q('INSERT INTO report_delivery_recipients (delivery_id, user_id, skipped_reason, notified_at) VALUES (?,?,?,?)', [$delivery, (int) $uid, $why, $why ? null : $now]);
            if (! $why) {
                q('INSERT INTO notifications (user_id, message, target) VALUES (?,?,?)', [(int) $uid, mb_substr('Scheduled report ready: ' . $def['name'] . ' (' . $label . ')', 0, 255), '/report-builder?delivery=' . $delivery]);
                $sent++;
            }
        }
        $done[] = 'Schedule ' . $s['id'] . ': delivered "' . $def['name'] . '" for ' . $label . ', ' . count($result['rows']) . ' row(s), to ' . $sent . ' recipient(s).';
    }

    return $done;
}

/** A delivery this person may download now, or the refusal. Returns [delivery, status, message]. */
function rb_delivery_for_download(int $id): array
{
    $d = row('SELECT r.*, d.name, d.source FROM report_deliveries r JOIN report_definitions d ON d.id = r.definition_id WHERE r.id = ?', [$id]);
    $mine = $d ? row('SELECT * FROM report_delivery_recipients WHERE delivery_id = ? AND user_id = ? AND skipped_reason IS NULL', [$id, uid()]) : null;
    if (! $d || ! $mine) {
        return [null, 404, t('There is no report delivered to you under that number.')];
    }
    if ($d['status'] !== 'ready' || (string) $d['expires_at'] <= date('Y-m-d H:i:s')) {
        return [null, 410, t('That delivery has expired: its file was erased after :n days. The report itself can be run again.', ['n' => RB_DELIVERY_DAYS])];
    }
    if (! rb_can_export((string) $d['source']) || ! report_allowed((string) $d['source'])) {
        return [null, 403, t('You may no longer read or export this report, so its delivery is closed to you.')];
    }
    if (! hash_equals((string) $d['sha256'], hash('sha256', (string) $d['csv']))) {
        return [null, 409, t('This file no longer matches its recorded fingerprint. Nothing was downloaded.')];
    }

    return [$d, 200, ''];
}
