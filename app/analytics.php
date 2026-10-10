<?php
/**
 * Dashboards (P4-M02): recruitment conversion, placement rates, worker
 * utilization, client revenue, project profitability, the financial
 * position and procurement. Read from the records and the ledger each
 * time; nothing is stored.
 *
 *   recruitment    applications made in a period and how far each got:
 *                  screening, interview, offer, acceptance; by source and
 *                  by project; days from application to acceptance
 *   placement      per project: positions ordered, people on assignment at
 *                  the end of the period, fill rate; assignments offered in
 *                  the period and how many were taken up or fell through
 *   utilization    per assignment: hours worked on approved sheets against
 *                  the hours expected (the assignment guarantee, or 40 a
 *                  week) for the weeks it ran in the period; who is on the
 *                  bench
 *   revenue        per client, from the ledger: revenue net of credit
 *                  notes, cash received, open receivables, overdue now
 *   profitability  per project, to date: the Project costs figures
 *                  (P3-M01): revenue, direct cost, margins, budget, open
 *                  commitments
 *   financial      cash, this month and the year to date, receivables and
 *                  payables with what is overdue, the last twelve months
 *   procurement    requests and orders in a period: awaiting approval,
 *                  approved value, approval time, delivered and rejected,
 *                  billed, bills on hold; by category and vendor
 *
 * Who reads which follows the pages behind it: recruiting reads its own,
 * payroll the money, the hotel desk and payroll procurement.
 */

declare(strict_types=1);

require_once __DIR__ . '/statements.php';
require_once __DIR__ . '/balances.php';
require_once __DIR__ . '/project-costing.php';
require_once __DIR__ . '/procurement.php';
require_once __DIR__ . '/matching.php';
require_once __DIR__ . '/reports.php';

function analytics_catalog(): array
{
    return [
        'recruitment'   => ['title' => 'Recruitment conversion', 'roles' => ['recruiter'], 'filters' => ['period', 'project'], 'ledger' => false,
                            'about' => 'Applications made in the period and how far each got: screening, interview, offer, acceptance.'],
        'placement'     => ['title' => 'Placement rates', 'roles' => ['recruiter'], 'filters' => ['period', 'project'], 'ledger' => false,
                            'about' => 'Positions ordered against people on assignment, and how many assignments offered were taken up.'],
        'utilization'   => ['title' => 'Worker utilization', 'roles' => ['recruiter', 'payroll'], 'filters' => ['period', 'project'], 'ledger' => false,
                            'about' => 'Hours worked on approved sheets against the hours each assignment was expected to give, and who is on the bench.'],
        'revenue'       => ['title' => 'Client revenue', 'roles' => ['payroll'], 'filters' => ['period'], 'ledger' => true,
                            'about' => 'Revenue, cash received and receivables per client, from the ledger.'],
        'profitability' => ['title' => 'Project profitability', 'roles' => ['payroll'], 'filters' => [], 'ledger' => false,
                            'about' => 'Each project to date: revenue, direct cost, margins, budget and open commitments, as Project costs computes them.'],
        'financial'     => ['title' => 'Financial dashboard', 'roles' => ['payroll'], 'filters' => ['asof'], 'ledger' => true,
                            'about' => 'Cash, results for the month and the year, receivables and payables, and the last twelve months.'],
        'procurement'   => ['title' => 'Procurement dashboard', 'roles' => ['hotels', 'payroll'], 'filters' => ['period', 'project'], 'ledger' => false,
                            'about' => 'Requests and orders: waiting for approval, approved, delivered, billed, held; by category and vendor.'],
    ];
}

function analytics_allowed(string $key): bool
{
    $d = analytics_catalog()[$key] ?? null;

    return $d !== null && can(...$d['roles']);
}

function analytics_pct(float $part, float $whole): ?float
{
    return $whole > 0 ? round(100 * $part / $whole, 1) : null;
}

/** One table: columns, rows, totals, notes. */
function analytics_table(string $id, string $title, array $columns, array $rows, array $totals = [], array $notes = []): array
{
    return ['id' => $id, 'title' => $title, 'columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'notes' => $notes];
}

/** Run one dashboard: headline figures (kpis) and tables. */
function analytics_run(string $key, array $f): array
{
    $jobId = (int) ($f['job_id'] ?? 0);
    $from = (string) $f['from'];
    $to = (string) $f['to'];
    $asof = (string) $f['asof'];
    $sum = static fn(array $rows, string $col): float => round(array_sum(array_map(fn($r) => (float) ($r[$col] ?? 0), $rows)), 2);
    $kpis = [];
    $tables = [];

    switch ($key) {
        case 'recruitment':
            $levels = ['new' => 0, 'screening' => 1, 'interview' => 2, 'offered' => 3, 'accepted' => 4];
            $sql = 'SELECT a.id, a.stage, a.source, a.created_at, j.id AS job_id, j.title FROM applications a JOIN vacancies v ON v.id = a.vacancy_id JOIN jobs j ON j.id = v.job_id
                    WHERE DATE(a.created_at) BETWEEN ? AND ?';
            $args = [$from, $to];
            if ($jobId > 0) {
                $sql .= ' AND j.id = ?';
                $args[] = $jobId;
            }
            $apps = rows($sql . ' ORDER BY a.id', $args);
            $reach = [];
            $days = [];
            foreach ($apps as $a) {
                $lvl = $levels[$a['stage']] ?? 0;
                foreach (rows('SELECT stage, created_at FROM application_events WHERE application_id = ? ORDER BY id', [(int) $a['id']]) as $ev) {
                    $lvl = max($lvl, $levels[$ev['stage']] ?? 0);
                    if ($ev['stage'] === 'accepted' && ! isset($days[$a['id']])) {
                        // Calendar days between the dates: a clock change must not shave one off.
                        $days[$a['id']] = (int) round((strtotime(substr((string) $ev['created_at'], 0, 10)) - strtotime(substr((string) $a['created_at'], 0, 10))) / 86400);
                    }
                }
                $reach[(int) $a['id']] = $lvl;
            }
            $n = count($apps);
            $steps = [['applied', 'Applied', 0], ['screening', 'Screening', 1], ['interview', 'Interview', 2], ['offered', 'Offer', 3], ['accepted', 'Accepted', 4]];
            $rows = [];
            $prev = $n;
            foreach ($steps as [$k, $label, $lvl]) {
                $c = count(array_filter($reach, fn($r) => $r >= $lvl));
                $rows[] = ['key' => $k, 'step' => $label, 'reached' => $c, 'of_applied' => analytics_pct($c, $n), 'of_previous' => $lvl === 0 ? null : analytics_pct($c, $prev)];
                $prev = $c;
            }
            $accepted = $rows[4]['reached'];
            $rejected = count(array_filter($apps, fn($a) => $a['stage'] === 'rejected'));
            $withdrawn = count(array_filter($apps, fn($a) => $a['stage'] === 'withdrawn'));
            sort($days);
            $median = $days ? (count($days) % 2 ? $days[intdiv(count($days), 2)] : round(($days[count($days) / 2 - 1] + $days[count($days) / 2]) / 2, 1)) : null;
            $kpis = [['applications', 'Applications', $n, 'int'], ['accepted', 'Accepted', $accepted, 'int'], ['conversion', 'Applied to accepted', analytics_pct($accepted, $n), 'pct'],
                     ['rejected', 'Rejected', $rejected, 'int'], ['withdrawn', 'Withdrawn', $withdrawn, 'int'],
                     ['open', 'Still open', $n - $accepted - $rejected - $withdrawn, 'int'], ['median_days', 'Median days to acceptance', $median, 'num']];
            $tables[] = analytics_table('funnel', 'Funnel', ['step' => ['Stage', 'text'], 'reached' => ['Reached', 'int'], 'of_applied' => ['Of applications', 'pct'],
                                                            'of_previous' => ['Of the stage before', 'pct']], $rows, [],
                                        [t('An application counts at every stage it reached, even if it was later rejected or withdrawn.')]);
            $group = static function (string $field) use ($apps, $reach): array {
                $g = [];
                foreach ($apps as $a) {
                    $k = $field === 'source' ? (trim((string) $a['source']) !== '' ? (string) $a['source'] : 'unknown') : (string) $a['title'];
                    $g[$k] ??= ['key' => $field . ':' . ($field === 'source' ? $k : $a['job_id']), 'name' => $k, 'applications' => 0, 'offered' => 0, 'accepted' => 0];
                    $g[$k]['applications']++;
                    $g[$k]['offered'] += $reach[(int) $a['id']] >= 3 ? 1 : 0;
                    $g[$k]['accepted'] += $reach[(int) $a['id']] >= 4 ? 1 : 0;
                }
                foreach ($g as &$r) {
                    $r['conversion'] = analytics_pct($r['accepted'], $r['applications']);
                }
                unset($r);
                usort($g, fn($x, $y) => [$y['applications'], $x['name']] <=> [$x['applications'], $y['name']]);
                return $g;
            };
            $gcols = ['name' => ['Name', 'text'], 'applications' => ['Applications', 'int'], 'offered' => ['Offered', 'int'], 'accepted' => ['Accepted', 'int'], 'conversion' => ['Applied to accepted', 'pct']];
            $tables[] = analytics_table('by-source', 'By source', $gcols, $group('source'));
            $tables[] = analytics_table('by-project', 'By project', $gcols, $group('project'));
            break;

        case 'placement':
            $rows = [];
            foreach (rows('SELECT j.id, j.title, j.headcount_target FROM jobs j' . ($jobId > 0 ? ' WHERE j.id = ' . $jobId : '') . ' ORDER BY j.title, j.id') as $j) {
                $lines = (int) val('SELECT COALESCE(SUM(quantity), 0) FROM job_order_lines WHERE job_id = ?', [(int) $j['id']]);
                $ordered = $lines > 0 ? $lines : (int) $j['headcount_target'];
                $filled = (int) val('SELECT COUNT(DISTINCT p.candidate_id) FROM placements p WHERE p.job_id = ? AND ' . report_active_sql(), [(int) $j['id'], $to, $to]);
                $made = rows('SELECT status FROM placements WHERE job_id = ? AND DATE(created_at) BETWEEN ? AND ?', [(int) $j['id'], $from, $to]);
                $taken = count(array_filter($made, fn($p) => ! in_array($p['status'], ['offered', 'cancelled'], true)));
                $fell = count(array_filter($made, fn($p) => $p['status'] === 'cancelled'));
                $waiting = count(array_filter($made, fn($p) => $p['status'] === 'offered'));
                if ($ordered === 0 && $filled === 0 && ! $made) {
                    continue;
                }
                $rows[] = ['key' => 'j' . $j['id'], 'project' => $j['title'], 'ordered' => $ordered, 'filled' => $filled, 'fill_rate' => analytics_pct($filled, $ordered),
                           'open' => max(0, $ordered - $filled), 'offered' => count($made), 'taken' => $taken, 'fell' => $fell, 'waiting' => $waiting,
                           'take_up' => analytics_pct($taken, $taken + $fell)];
            }
            $tot = array_combine(['ordered', 'filled', 'open', 'offered', 'taken', 'fell', 'waiting'], array_map(fn($c) => (int) $sum($rows, $c), ['ordered', 'filled', 'open', 'offered', 'taken', 'fell', 'waiting']));
            $tot['fill_rate'] = analytics_pct($tot['filled'], $tot['ordered']);
            $tot['take_up'] = analytics_pct($tot['taken'], $tot['taken'] + $tot['fell']);
            $tot['project'] = count($rows);
            $kpis = [['ordered', 'Positions ordered', $tot['ordered'], 'int'], ['filled', 'On assignment at the end', $tot['filled'], 'int'], ['fill_rate', 'Fill rate', $tot['fill_rate'], 'pct'],
                     ['offered', 'Assignments offered', $tot['offered'], 'int'], ['take_up', 'Taken up', $tot['take_up'], 'pct']];
            $tables[] = analytics_table('placement-table', 'By project', ['project' => ['Project', 'text'], 'ordered' => ['Ordered', 'int'], 'filled' => ['On assignment', 'int'],
                'fill_rate' => ['Fill rate', 'pct'], 'open' => ['Still to fill', 'int'], 'offered' => ['Offered in the period', 'int'], 'taken' => ['Taken up', 'int'],
                'fell' => ['Fell through', 'int'], 'waiting' => ['Waiting for an answer', 'int'], 'take_up' => ['Take-up', 'pct']], $rows, $tot,
                [t('Ordered is the scope of work, or the headcount target when the project has no scope lines. Take-up counts answered offers only.')]);
            break;

        case 'utilization':
            $sql = "SELECT p.*, c.full_name, j.title FROM placements p JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id
                    WHERE p.status IN ('confirmed','travelling','on_site','completed') AND p.start_date IS NOT NULL AND p.start_date <= ?
                      AND (p.end_date IS NULL OR p.end_date >= ?) AND NOT (p.status = 'completed' AND p.end_date IS NULL)";
            $args = [$to, $from];
            if ($jobId > 0) {
                $sql .= ' AND p.job_id = ?';
                $args[] = $jobId;
            }
            $rows = [];
            foreach (rows($sql . ' ORDER BY c.full_name, j.title', $args) as $p) {
                $weeks = 0;
                for ($w = week_ending($from); $w <= week_ending($to); $w = date('Y-m-d', strtotime($w . ' +7 days'))) {
                    $weekStart = date('Y-m-d', strtotime($w . ' -6 days'));
                    if ((string) $p['start_date'] <= $w && ($p['end_date'] === null || (string) $p['end_date'] >= $weekStart)) {
                        $weeks++;
                    }
                }
                $per = (int) ($p['guarantee_hours'] ?? 0) > 0 ? (int) $p['guarantee_hours'] : 40;
                $t = row("SELECT COALESCE(SUM(hours_worked), 0) AS worked, COALESCE(SUM(paid_leave_hours), 0) AS leave_h FROM timesheets
                          WHERE placement_id = ? AND status IN ('approved','paid') AND week_ending BETWEEN ? AND ?", [(int) $p['id'], week_ending($from), week_ending($to)]);
                $expected = $weeks * $per;
                $rows[] = ['key' => 'p' . $p['id'], 'name' => $p['full_name'], 'project' => $p['title'], 'weeks' => $weeks, 'expected' => (float) $expected,
                           'worked' => round((float) $t['worked'], 2), 'leave' => round((float) $t['leave_h'], 2), 'utilization' => analytics_pct((float) $t['worked'], $expected)];
            }
            $bench = rows("SELECT c.id, c.full_name FROM employee_profiles e JOIN candidates c ON c.id = e.candidate_id WHERE e.availability = 'available'
                           AND NOT EXISTS (SELECT 1 FROM placements p WHERE p.candidate_id = c.id AND " . report_active_sql() . ') ORDER BY c.full_name', [$to, $to]);
            $exp = $sum($rows, 'expected');
            $wrk = $sum($rows, 'worked');
            $kpis = [['people', 'Assignments', count($rows), 'int'], ['expected', 'Hours expected', $exp, 'num'], ['worked', 'Hours worked', $wrk, 'num'],
                     ['utilization', 'Utilization', analytics_pct($wrk, $exp), 'pct'], ['bench', 'Available, not on assignment', count($bench), 'int']];
            $tables[] = analytics_table('utilization-table', 'By assignment', ['name' => ['Name', 'text'], 'project' => ['Project', 'text'], 'weeks' => ['Weeks', 'int'],
                'expected' => ['Hours expected', 'num'], 'worked' => ['Hours worked', 'num'], 'leave' => ['Paid leave hours', 'num'], 'utilization' => ['Utilization', 'pct']], $rows,
                ['name' => count($rows), 'expected' => $exp, 'worked' => $wrk, 'leave' => $sum($rows, 'leave'), 'utilization' => analytics_pct($wrk, $exp)],
                [t('Expected hours are the assignment guarantee each week it ran in the period, or 40 when it has none. Worked hours are from approved sheets.')]);
            $tables[] = analytics_table('bench', 'On the bench', ['name' => ['Name', 'text']], array_map(fn($b) => ['key' => 'c' . $b['id'], 'name' => $b['full_name']], $bench),
                ['name' => count($bench)], [t('Marked available on their employee profile, with no assignment running at the end of the period.')]);
            break;

        case 'revenue':
            $ledger = static function (string $account, string $expr, string $start, string $end): array {
                $out = [];
                foreach (rows("SELECT l.party, SUM($expr) AS v FROM gl_lines l JOIN gl_journals j ON j.id = l.journal_id
                               WHERE j.status = 'posted' AND l.account_key = ? AND j.posted_on BETWEEN ? AND ? AND l.party IS NOT NULL GROUP BY l.party", [$account, $start, $end]) as $r) {
                    $out[(string) $r['party']] = round((float) $r['v'], 2);
                }
                return $out;
            };
            $revenue = $ledger('revenue', 'l.credit - l.debit', $from, $to);
            $received = $ledger('bank', 'l.debit - l.credit', $from, $to);
            $open = $ledger('accounts_receivable', 'l.debit - l.credit', '1000-01-01', $to);
            $overdue = [];
            foreach (bal_aging('ar') as $p) {
                $overdue[(string) $p['label']] = round($p['open'] - $p['current'], 2);
            }
            $clients = array_column(rows('SELECT name FROM clients'), 'name');
            $names = array_values(array_intersect($clients, array_unique(array_merge(array_keys($revenue), array_keys($open)))));
            $total = round(array_sum(array_intersect_key($revenue, array_flip($names))), 2);
            $rows = [];
            foreach ($names as $n) {
                $r = $revenue[$n] ?? 0.0;
                if (abs($r) < 0.005 && abs($open[$n] ?? 0) < 0.005) {
                    continue;
                }
                $rows[] = ['key' => 'client:' . $n, 'client' => $n, 'revenue' => $r, 'share' => analytics_pct($r, $total), 'received' => $received[$n] ?? 0.0,
                           'open' => $open[$n] ?? 0.0, 'overdue' => $overdue[$n] ?? 0.0];
            }
            usort($rows, fn($x, $y) => [$y['revenue'], $x['client']] <=> [$x['revenue'], $y['client']]);
            $days = max(1, (int) round((strtotime($to) - strtotime($from)) / 86400) + 1);
            $openTotal = $sum($rows, 'open');
            $kpis = [['revenue', 'Revenue', $total, 'money'], ['received', 'Received', $sum($rows, 'received'), 'money'], ['open', 'Receivables at the end', $openTotal, 'money'],
                     ['overdue', 'Overdue today', $sum($rows, 'overdue'), 'money'], ['top_share', 'Largest client', $rows ? $rows[0]['share'] : null, 'pct'],
                     ['dso', 'Days of sales outstanding', $total > 0 ? round($openTotal / $total * $days, 1) : null, 'num']];
            $tables[] = analytics_table('revenue-table', 'By client', ['client' => ['Client', 'text'], 'revenue' => ['Revenue', 'money'], 'share' => ['Share', 'pct'],
                'received' => ['Received', 'money'], 'open' => ['Receivables at the end', 'money'], 'overdue' => ['Overdue today', 'money']], $rows,
                ['client' => count($rows), 'revenue' => $total, 'received' => $sum($rows, 'received'), 'open' => $openTotal, 'overdue' => $sum($rows, 'overdue')],
                [t('From the ledger: revenue net of credit notes, posted in the period. Overdue is as of today, from Receivables and payables.')]);
            break;

        case 'profitability':
            $rows = [];
            foreach (rows('SELECT j.id, j.title, j.status, c.name AS client FROM jobs j LEFT JOIN clients c ON c.id = j.client_id ORDER BY j.title, j.id') as $j) {
                $costs = project_costs((int) $j['id']);
                $a = $costs['actual'];
                $p = project_profit($a);
                $budget = project_budget((int) $j['id']);
                if (abs($a['revenue']) < 0.005 && abs($p['direct']) < 0.005 && ! $budget) {
                    continue;
                }
                $budgetCost = round(array_sum(array_diff_key($budget, ['revenue' => 0])), 2);
                $rows[] = ['key' => 'j' . $j['id'], 'project' => $j['title'], 'client' => (string) $j['client'], 'status' => (string) $j['status'],
                           'revenue' => (float) $a['revenue'], 'direct' => $p['direct'], 'gross' => $p['gross'], 'gross_pct' => $p['gross_pct'],
                           'overhead' => (float) $a['overhead'], 'net' => $p['net'], 'net_pct' => $p['net_pct'],
                           'budget_revenue' => isset($budget['revenue']) ? (float) $budget['revenue'] : null, 'budget_cost' => $budget ? $budgetCost : null,
                           'committed' => (float) $costs['committed']];
            }
            usort($rows, fn($x, $y) => [$y['net'], $x['project']] <=> [$x['net'], $y['project']]);
            $rev = $sum($rows, 'revenue');
            $tot = ['project' => count($rows), 'revenue' => $rev, 'direct' => $sum($rows, 'direct'), 'gross' => $sum($rows, 'gross'), 'overhead' => $sum($rows, 'overhead'),
                    'net' => $sum($rows, 'net'), 'committed' => $sum($rows, 'committed')];
            $tot['gross_pct'] = analytics_pct($tot['gross'], $rev);
            $tot['net_pct'] = analytics_pct($tot['net'], $rev);
            $kpis = [['revenue', 'Revenue to date', $rev, 'money'], ['gross', 'Gross margin', $tot['gross'], 'money'], ['gross_pct', 'Gross margin', $tot['gross_pct'], 'pct'],
                     ['net', 'Net after overhead', $tot['net'], 'money'], ['losing', 'Projects losing money', count(array_filter($rows, fn($r) => $r['net'] < 0)), 'int']];
            $tables[] = analytics_table('profit-table', 'By project', ['project' => ['Project', 'text'], 'client' => ['Client', 'text'], 'status' => ['State', 'label'],
                'revenue' => ['Revenue', 'money'], 'direct' => ['Direct cost', 'money'], 'gross' => ['Gross margin', 'money'], 'gross_pct' => ['Gross %', 'pct'],
                'overhead' => ['Overhead', 'money'], 'net' => ['Net', 'money'], 'net_pct' => ['Net %', 'pct'], 'budget_revenue' => ['Budgeted revenue', 'money'],
                'budget_cost' => ['Budgeted cost', 'money'], 'committed' => ['Committed, not billed', 'money']], $rows, $tot,
                [t('To date, as the Project costs page computes each project: labour with burden from approved sheets, purchases, hotels, claims, and overhead at its rate.')]);
            break;

        case 'financial':
            $monthStart = substr($asof, 0, 7) . '-01';
            $yearStart = substr($asof, 0, 4) . '-01-01';
            $bank = round((float) val("SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM gl_lines l JOIN gl_journals j ON j.id = l.journal_id
                                       WHERE j.status = 'posted' AND l.account_key = 'bank' AND j.posted_on <= ?", [$asof]), 2);
            $mtd = stmt_income($monthStart, $asof);
            $ytd = stmt_income($yearStart, $asof);
            $ar = bal_aging('ar');
            $ap = bal_aging('ap');
            $open = static fn(array $aging): float => round(array_sum(array_column($aging, 'open')), 2);
            $late = static fn(array $aging): float => round(array_sum(array_map(fn($p) => $p['open'] - $p['current'], $aging)), 2);
            $kpis = [['cash', 'Cash', $bank, 'money'], ['revenue_mtd', 'Revenue this month', $mtd['income']['total'], 'money'], ['net_mtd', 'Net this month', $mtd['net'], 'money'],
                     ['revenue_ytd', 'Revenue this year', $ytd['income']['total'], 'money'], ['net_ytd', 'Net this year', $ytd['net'], 'money'],
                     ['ar', 'Receivables', $open($ar), 'money'], ['ar_late', 'Receivables overdue', $late($ar), 'money'],
                     ['ap', 'Payables', $open($ap), 'money'], ['ap_late', 'Payables overdue', $late($ap), 'money']];
            $rows = [];
            for ($i = 11; $i >= 0; $i--) {
                $m = date('Y-m', strtotime($monthStart . " -$i months"));
                [$s, $e] = period_bounds($m);
                $e = min($e, $asof);
                $inc = stmt_income($s, $e);
                $rows[] = ['key' => 'm' . $m, 'month' => $m, 'revenue' => $inc['income']['total'], 'expenses' => $inc['expense']['total'], 'net' => $inc['net'],
                           'cash' => round((float) val("SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM gl_lines l JOIN gl_journals j ON j.id = l.journal_id
                                                       WHERE j.status = 'posted' AND l.account_key = 'bank' AND j.posted_on <= ?", [$e]), 2)];
            }
            $tables[] = analytics_table('months', 'The last twelve months', ['month' => ['Month', 'text'], 'revenue' => ['Revenue', 'money'], 'expenses' => ['Expenses', 'money'],
                'net' => ['Net', 'money'], 'cash' => ['Cash at the end', 'money']], $rows,
                ['month' => count($rows), 'revenue' => $sum($rows, 'revenue'), 'expenses' => $sum($rows, 'expenses'), 'net' => $sum($rows, 'net')],
                [t('From the ledger, as the financial statements read it. Receivables and payables are open today, from Receivables and payables.')]);
            break;

        case 'procurement':
            $where = $jobId > 0 ? ' AND o.job_id = ' . $jobId : '';
            $rWhere = $jobId > 0 ? ' AND r.job_id = ' . $jobId : '';
            $requested = (int) val("SELECT COUNT(*) FROM purchase_requests r WHERE r.status = 'requested'" . $rWhere);
            $waiting = row("SELECT COUNT(*) AS n, COALESCE(SUM(o.total), 0) AS v FROM purchase_orders o WHERE o.status = 'awaiting_approval'" . $where);
            $orders = rows("SELECT o.*, r.category,
                                   (SELECT COALESCE(SUM(x.quantity), 0) FROM purchase_receipts x WHERE x.purchase_order_id = o.id) AS received,
                                   (SELECT COALESCE(SUM(x.rejected_quantity), 0) FROM purchase_receipts x WHERE x.purchase_order_id = o.id) AS rejected,
                                   (SELECT COALESCE(SUM(v.amount), 0) FROM vendor_invoices v WHERE v.purchase_order_id = o.id) AS billed
                            FROM purchase_orders o JOIN purchase_requests r ON r.id = o.request_id
                            WHERE o.status IN ('approved','closed') AND DATE(o.decided_at) BETWEEN ? AND ?" . $where, [$from, $to]);
            $value = round(array_sum(array_column($orders, 'total')), 2);
            $hours = array_map(fn($o) => (strtotime((string) $o['decided_at']) - strtotime((string) $o['created_at'])) / 86400, $orders);
            $qty = array_sum(array_map(fn($o) => (float) $o['quantity'], $orders));
            $recv = array_sum(array_map(fn($o) => min((float) $o['received'], (float) $o['quantity']), $orders));
            $held = 0;
            foreach (bills_open() as $b) {
                if (($jobId === 0 || (int) $b['job_id'] === $jobId) && ! $b['match']['matched'] && $b['match']['clearance'] === null) {
                    $held++;
                }
            }
            $kpis = [['requested', 'Requests waiting for an order', $requested, 'int'], ['awaiting', 'Orders waiting for approval', (int) $waiting['n'], 'int'],
                     ['awaiting_value', 'Value waiting for approval', round((float) $waiting['v'], 2), 'money'], ['approved', 'Orders approved', count($orders), 'int'],
                     ['approved_value', 'Value approved', $value, 'money'], ['approval_days', 'Average days to approve', $hours ? round(array_sum($hours) / count($hours), 1) : null, 'num'],
                     ['delivered', 'Delivered', analytics_pct($recv, $qty), 'pct'], ['rejected', 'Quantity rejected', round(array_sum(array_column($orders, 'rejected')), 2), 'num'],
                     ['billed', 'Billed', round(array_sum(array_column($orders, 'billed')), 2), 'money'], ['held', 'Bills on hold', $held, 'int']];
            $group = static function (string $field) use ($orders): array {
                $g = [];
                foreach ($orders as $o) {
                    $k = (string) $o[$field];
                    $g[$k] ??= ['key' => $field . ':' . $k, 'name' => $k, 'orders' => 0, 'value' => 0.0, 'billed' => 0.0, 'qty' => 0.0, 'recv' => 0.0];
                    $g[$k]['orders']++;
                    $g[$k]['value'] = round($g[$k]['value'] + (float) $o['total'], 2);
                    $g[$k]['billed'] = round($g[$k]['billed'] + (float) $o['billed'], 2);
                    $g[$k]['qty'] += (float) $o['quantity'];
                    $g[$k]['recv'] += min((float) $o['received'], (float) $o['quantity']);
                }
                foreach ($g as &$r) {
                    $r['delivered'] = analytics_pct($r['recv'], $r['qty']);
                }
                unset($r);
                usort($g, fn($x, $y) => [$y['value'], $x['name']] <=> [$x['value'], $y['name']]);
                return $g;
            };
            $cols = ['name' => ['Name', 'text'], 'orders' => ['Orders', 'int'], 'value' => ['Value', 'money'], 'billed' => ['Billed', 'money'], 'delivered' => ['Delivered', 'pct']];
            $cat = array_map(fn($r) => ['name' => $r['name']] + $r, $group('category'));
            $tables[] = analytics_table('by-category', 'By category', ['name' => ['Category', 'label']] + $cols, $cat,
                ['name' => count($cat), 'orders' => count($orders), 'value' => $value, 'billed' => round(array_sum(array_column($orders, 'billed')), 2)],
                [t('Orders approved in the period, on the project that raised them.')]);
            $tables[] = analytics_table('by-vendor', 'By vendor', $cols, $group('vendor_name'));
            break;

        default:
            throw new InvalidArgumentException('Unknown dashboard');
    }

    return ['kpis' => $kpis, 'tables' => $tables];
}
