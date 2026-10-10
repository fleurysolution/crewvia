<?php
/**
 * Workforce and payroll reports (P4-M01).
 *
 * Ten reports, each read from the records the desks already keep; nothing
 * is stored, so every figure can be traced to the page that holds it:
 *
 *   employees     who works for the agency, on what terms, where now
 *   headcount     on assignment at the start and end of a period, starts
 *                 and ends in it, by project
 *   attendance    days marked present and absent on the roll call, and
 *                 approved leave, per person and project
 *   leave         each kind of leave per person for a year: allowed,
 *                 taken, left, waiting
 *   salary        rates and salaries in force on a date, the grade and
 *                 whether the rate sits outside its band
 *   deductions    what each pay item took, from the frozen pay of the
 *                 approved weeks
 *   payroll       each pay period: gross, deductions, employer cost,
 *                 reimbursed, net before tax, adjustments, paid
 *   loans         advances and loans: amount, repaid, balance, behind
 *   benefits      each plan: who is covered on a date, who waived, cost
 *   performance   reviews decided in a period, goals and development
 *
 * Who reads what follows the pages the figures come from: pay, deductions,
 * loans and benefits are payroll's; reviews are recruiting's; the rest is
 * both desks'. Administrators read everything. Every report read is
 * logged. Figures are before tax: taxes are calculated by ADP.
 */

declare(strict_types=1);

require_once __DIR__ . '/hr.php';
require_once __DIR__ . '/pay-periods.php';
require_once __DIR__ . '/compensation.php';
require_once __DIR__ . '/leave-calculation.php';
require_once __DIR__ . '/loans.php';

/** The reports, who reads each, and which filters it takes. */
function reports_catalog(): array
{
    return [
        'employees'   => ['title' => 'Employees', 'roles' => ['recruiter', 'payroll'], 'filters' => ['asof', 'project'],
                          'about' => 'Everyone placed by the agency: employee number, kind of employment, availability, current or last assignment.'],
        'headcount'   => ['title' => 'Headcount', 'roles' => ['recruiter', 'payroll'], 'filters' => ['period'],
                          'about' => 'People on assignment at the start and the end of a period, and who started and ended in it, by project.'],
        'attendance'  => ['title' => 'Attendance', 'roles' => ['recruiter', 'payroll'], 'filters' => ['period', 'project'],
                          'about' => 'Days marked present and absent on the roll call, and approved leave, per person.'],
        'leave'       => ['title' => 'Leave', 'roles' => ['recruiter', 'payroll'], 'filters' => ['year', 'project'],
                          'about' => 'Each kind of leave per person for the year: allowed, taken, left, and requests waiting.'],
        'salary'      => ['title' => 'Salaries and rates', 'roles' => ['payroll'], 'filters' => ['asof', 'project'],
                          'about' => 'The pay rate or salary in force on a date for each assignment, its grade, and whether it sits outside the band.'],
        'deductions'  => ['title' => 'Deductions and contributions', 'roles' => ['payroll'], 'filters' => ['period', 'project'],
                          'about' => 'What each pay item took or added in the approved weeks of a period, from the frozen pay.'],
        'payroll'     => ['title' => 'Payroll', 'roles' => ['payroll'], 'filters' => ['period'],
                          'about' => 'Each pay period: gross, deductions, employer cost, reimbursed, net before tax, adjustments, and how many sheets are paid.'],
        'loans'       => ['title' => 'Advances and loans', 'roles' => ['payroll'], 'filters' => ['project'],
                          'about' => 'Each advance and loan handed over: amount, repaid, balance, how far behind its schedule, written off.'],
        'benefits'    => ['title' => 'Benefits', 'roles' => ['payroll'], 'filters' => ['asof'],
                          'about' => 'Each plan on a date: people covered, people who waived, and the fixed cost to them and to the agency.'],
        'performance' => ['title' => 'Performance', 'roles' => ['recruiter'], 'filters' => ['period', 'project'],
                          'about' => 'Reviews approved in a period, with score and grade, and each person\'s goals and development actions.'],
    ];
}

function report_allowed(string $key): bool
{
    $r = reports_catalog()[$key] ?? null;

    return $r !== null && can(...$r['roles']);
}

/** The people placed by the agency, optionally on one project. */
function report_people(int $jobId): array
{
    $sql = "SELECT DISTINCT c.id, c.full_name, c.discipline FROM candidates c JOIN placements p ON p.candidate_id = c.id
            WHERE p.status IN ('confirmed','travelling','on_site','completed')";
    $args = [];
    if ($jobId > 0) {
        $sql .= ' AND p.job_id = ?';
        $args[] = $jobId;
    }

    return rows($sql . ' ORDER BY c.full_name, c.id', $args);
}

/** Placements that were on assignment on a date. */
function report_active_sql(string $alias = 'p'): string
{
    return "$alias.status IN ('confirmed','travelling','on_site','completed') AND $alias.start_date IS NOT NULL AND $alias.start_date <= ?
            AND ($alias.end_date IS NULL OR $alias.end_date >= ?) AND NOT ($alias.status = 'completed' AND $alias.end_date IS NULL)";
}

/**
 * Run one report. Returns columns (key => [label, type]), rows (each with
 * a 'key' that names it), totals (by column key) and notes.
 */
function report_run(string $key, array $f): array
{
    $jobId = (int) ($f['job_id'] ?? 0);
    $from = (string) $f['from'];
    $to = (string) $f['to'];
    $asof = (string) $f['asof'];
    $year = (int) $f['year'];
    $sum = static fn(array $rows, string $col): float => round(array_sum(array_map(fn($r) => (float) ($r[$col] ?? 0), $rows)), 2);
    $notes = [];

    switch ($key) {
        case 'employees':
            $columns = ['name' => ['Name', 'text'], 'number' => ['Employee number', 'text'], 'type' => ['Employment', 'label'], 'trade' => ['Trade', 'text'],
                        'availability' => ['Availability', 'label'], 'project' => ['Assignment', 'text'], 'status' => ['State', 'label'],
                        'start' => ['Started', 'date'], 'rehire' => ['Rehire', 'label']];
            $rows = [];
            foreach (report_people($jobId) as $c) {
                $e = row('SELECT * FROM employee_profiles WHERE candidate_id = ?', [(int) $c['id']]) ?: [];
                $pArgs = [(int) $c['id'], $asof];
                $pSql = "SELECT p.*, j.title FROM placements p JOIN jobs j ON j.id = p.job_id WHERE p.candidate_id = ? AND p.status IN ('confirmed','travelling','on_site','completed') AND p.start_date <= ?";
                if ($jobId > 0) {
                    $pSql .= ' AND p.job_id = ?';
                    $pArgs[] = $jobId;
                }
                $p = row($pSql . ' ORDER BY p.start_date DESC, p.id DESC LIMIT 1', $pArgs);
                if (! $p) {
                    continue;
                }
                $current = $p['end_date'] === null ? $p['status'] !== 'completed' : (string) $p['end_date'] >= $asof;
                $rows[] = ['key' => 'c' . $c['id'], 'name' => $c['full_name'], 'number' => (string) ($e['employee_number'] ?? ''),
                           'type' => (string) ($e['employment_type'] ?? ''), 'trade' => (string) $c['discipline'], 'availability' => (string) ($e['availability'] ?? ''),
                           'project' => $p['title'], 'status' => $current ? 'on assignment' : 'ended', 'start' => (string) $p['start_date'],
                           'rehire' => (string) ($e['rehire_status'] ?? '')];
            }
            $totals = ['name' => count($rows), 'status' => count(array_filter($rows, fn($r) => $r['status'] === 'on assignment'))];
            $notes[] = t('People with an assignment that had started by the date. The pay of each is in Salaries and rates, read by payroll only.');
            break;

        case 'headcount':
            $columns = ['project' => ['Project', 'text'], 'opening' => ['At the start', 'int'], 'started' => ['Started', 'int'],
                        'ended' => ['Ended', 'int'], 'closing' => ['At the end', 'int'], 'net' => ['Change', 'int']];
            $rows = [];
            foreach (rows('SELECT id, title FROM jobs ORDER BY title, id') as $j) {
                $count = static fn(string $where, array $args): int => (int) val("SELECT COUNT(DISTINCT p.candidate_id) FROM placements p WHERE p.job_id = ? AND $where", [(int) $j['id'], ...$args]);
                $opening = $count(report_active_sql(), [$from, $from]);
                $closing = $count(report_active_sql(), [$to, $to]);
                $started = $count("p.status IN ('confirmed','travelling','on_site','completed') AND p.start_date BETWEEN ? AND ?", [$from, $to]);
                $ended = $count("p.status = 'completed' AND p.end_date BETWEEN ? AND ?", [$from, $to]);
                if ($opening + $closing + $started + $ended === 0) {
                    continue;
                }
                $rows[] = ['key' => 'j' . $j['id'], 'project' => $j['title'], 'opening' => $opening, 'started' => $started, 'ended' => $ended,
                           'closing' => $closing, 'net' => $closing - $opening];
            }
            $totals = ['project' => count($rows)] + array_map(fn($c) => (int) $sum($rows, $c), array_combine(['opening', 'started', 'ended', 'closing', 'net'], ['opening', 'started', 'ended', 'closing', 'net']));
            $notes[] = t('A person on two projects counts once on each. Ended means the assignment was completed with its end date in the period.');
            break;

        case 'attendance':
            $columns = ['name' => ['Name', 'text'], 'project' => ['Project', 'text'], 'present' => ['Present', 'int'], 'absent' => ['Absent', 'int'],
                        'rate' => ['Attendance', 'pct'], 'leave' => ['Approved leave days', 'int']];
            $sql = "SELECT p.id, c.full_name, j.title,
                           (SELECT COUNT(*) FROM assignment_checkins k WHERE k.placement_id = p.id AND k.present = 1 AND k.work_date BETWEEN ? AND ?) AS present,
                           (SELECT COUNT(*) FROM assignment_checkins k WHERE k.placement_id = p.id AND k.present = 0 AND k.work_date BETWEEN ? AND ?) AS absent
                    FROM placements p JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id
                    WHERE p.status IN ('confirmed','travelling','on_site','completed')";
            $args = [$from, $to, $from, $to];
            if ($jobId > 0) {
                $sql .= ' AND p.job_id = ?';
                $args[] = $jobId;
            }
            $rows = [];
            foreach (rows($sql . ' ORDER BY c.full_name, j.title', $args) as $r) {
                $leave = 0;
                foreach (rows("SELECT starts_on, ends_on FROM time_off_requests WHERE placement_id = ? AND status = 'approved' AND starts_on <= ? AND ends_on >= ?", [(int) $r['id'], $to, $from]) as $t) {
                    $leave += leave_days_between((string) $t['starts_on'], (string) $t['ends_on'], $from, $to);
                }
                $marked = (int) $r['present'] + (int) $r['absent'];
                if ($marked === 0 && $leave === 0) {
                    continue;
                }
                $rows[] = ['key' => 'p' . $r['id'], 'name' => $r['full_name'], 'project' => $r['title'], 'present' => (int) $r['present'], 'absent' => (int) $r['absent'],
                           'rate' => $marked > 0 ? round(100 * (int) $r['present'] / $marked, 1) : null, 'leave' => $leave];
            }
            $present = (int) $sum($rows, 'present');
            $absent = (int) $sum($rows, 'absent');
            $totals = ['name' => count($rows), 'present' => $present, 'absent' => $absent, 'rate' => $present + $absent > 0 ? round(100 * $present / ($present + $absent), 1) : null,
                       'leave' => (int) $sum($rows, 'leave')];
            $notes[] = t('Attendance is present days over days marked on the roll call. A day nobody marked is not counted either way.');
            break;

        case 'leave':
            $columns = ['name' => ['Name', 'text'], 'type' => ['Kind of leave', 'text'], 'allowed' => ['Allowed', 'num'], 'taken' => ['Taken', 'num'],
                        'left' => ['Left', 'num'], 'waiting' => ['Requests waiting', 'int']];
            $types = leave_types();
            $rows = [];
            foreach (report_people($jobId) as $c) {
                foreach ($types as $slug => $type) {
                    $b = leave_balance((int) $c['id'], $slug, $year);
                    $waiting = (int) val("SELECT COUNT(*) FROM time_off_requests r JOIN placements p ON p.id = r.placement_id
                                          WHERE p.candidate_id = ? AND r.leave_type = ? AND r.status = 'pending' AND YEAR(r.starts_on) = ?", [(int) $c['id'], $slug, $year]);
                    if ((float) ($b['allowed'] ?? 0) <= 0 && (float) $b['taken'] <= 0 && $waiting === 0) {
                        continue;
                    }
                    $rows[] = ['key' => 'c' . $c['id'] . ':' . $slug, 'name' => $c['full_name'], 'type' => (string) $type['label'],
                               'allowed' => $b['allowed'], 'taken' => (float) $b['taken'], 'left' => $b['left'], 'waiting' => $waiting];
                }
            }
            $totals = ['name' => count(array_unique(array_column($rows, 'name'))), 'taken' => $sum($rows, 'taken'), 'waiting' => (int) $sum($rows, 'waiting')];
            $notes[] = t('Days, counted as the time-off page counts them: both ends included. Unlimited leave shows no allowance.');
            break;

        case 'salary':
            $columns = ['name' => ['Name', 'text'], 'project' => ['Project', 'text'], 'type' => ['Employment', 'label'], 'grade' => ['Grade', 'text'],
                        'rate' => ['Hourly rate', 'money'], 'bill' => ['Bill rate', 'money'], 'margin' => ['Margin', 'pct'], 'salary' => ['Salary per period', 'money'],
                        'band' => ['Outside band', 'label']];
            $week = week_ending($asof);
            $sql = 'SELECT p.*, c.full_name, j.title FROM placements p JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id WHERE ' . report_active_sql();
            $args = [$asof, $asof];
            if ($jobId > 0) {
                $sql .= ' AND p.job_id = ?';
                $args[] = $jobId;
            }
            $rows = [];
            foreach (rows($sql . ' ORDER BY c.full_name, j.title', $args) as $p) {
                $e = row('SELECT employment_type, salary_per_period FROM employee_profiles WHERE candidate_id = ?', [(int) $p['candidate_id']]) ?: [];
                $type = (string) ($e['employment_type'] ?? '');
                $rate = compensation_rate_on((int) $p['id'], $week) ?? (float) $p['pay_rate'];
                $salary = $type === 'salaried' ? (compensation_salary_on((int) $p['candidate_id'], $week) ?? ($e['salary_per_period'] !== null ? (float) $e['salary_per_period'] : null)) : null;
                $grade = compensation_grade_on((int) $p['candidate_id'], $asof);
                $outside = $grade && ($type === 'salaried' ? ($salary !== null && compensation_outside_band($grade, 'salary', $salary)) : compensation_outside_band($grade, 'hourly_rate', $rate));
                $bill = (float) $p['bill_rate'];
                $rows[] = ['key' => 'p' . $p['id'], 'name' => $p['full_name'], 'project' => $p['title'], 'type' => $type, 'grade' => $grade ? (string) $grade['label'] : '',
                           'rate' => $type === 'salaried' ? null : round($rate, 2), 'bill' => $bill, 'margin' => $bill > 0 && $type !== 'salaried' ? round(100 * ($bill - $rate) / $bill, 1) : null,
                           'salary' => $salary, 'band' => $outside ? 'yes' : ''];
            }
            $totals = ['name' => count($rows), 'band' => count(array_filter($rows, fn($r) => $r['band'] === 'yes'))];
            $notes[] = t('The rate is the one in force for the pay week of the date, after recorded changes. Margin is before burden and overhead (see Project costs).');
            break;

        case 'deductions':
            $columns = ['label' => ['Pay item', 'text'], 'code' => ['Code', 'text'], 'side' => ['Kind', 'label'], 'people' => ['People', 'int'],
                        'weeks' => ['Weeks', 'int'], 'amount' => ['Amount', 'money']];
            $sql = "SELECT p.candidate_id, s.result_json FROM timesheets t JOIN placements p ON p.id = t.placement_id JOIN pay_snapshots s ON s.timesheet_id = t.id
                    WHERE t.status IN ('approved','paid') AND t.week_ending BETWEEN ? AND ?";
            $args = [$from, $to];
            if ($jobId > 0) {
                $sql .= ' AND p.job_id = ?';
                $args[] = $jobId;
            }
            $items = [];
            $shortfall = 0.0;
            foreach (rows($sql, $args) as $r) {
                $g = (json_decode((string) $r['result_json'], true) ?: [])['gross_to_net'] ?? null;
                if (! $g) {
                    continue;
                }
                foreach (['deductions' => 'deduction', 'employer' => 'employer contribution'] as $list => $side) {
                    foreach ($g[$list] ?? [] as $d) {
                        $k = $side . ':' . $d['code'];
                        $items[$k] ??= ['key' => $k, 'label' => (string) $d['label'], 'code' => (string) $d['code'], 'side' => $side, 'people' => [], 'weeks' => 0, 'amount' => 0.0];
                        $items[$k]['people'][(int) $r['candidate_id']] = true;
                        $items[$k]['weeks']++;
                        $items[$k]['amount'] = round($items[$k]['amount'] + (float) $d['amount'], 2);
                    }
                }
                $shortfall += array_sum(array_map(fn($s) => (float) $s['amount'], $g['shortfalls'] ?? []));
            }
            $rows = array_map(fn($i) => ['people' => count($i['people'])] + $i, array_values($items));
            usort($rows, fn($a, $b) => [$a['side'], $a['label']] <=> [$b['side'], $b['label']]);
            $ded = array_filter($rows, fn($r) => $r['side'] === 'deduction');
            $emp = array_filter($rows, fn($r) => $r['side'] !== 'deduction');
            $totals = ['label' => count($rows), 'amount' => $sum($rows, 'amount')];
            $notes[] = t('Deductions taken :d; employer contributions :e; not taken for lack of pay :s. Taxes are withheld by ADP and are not here.',
                         ['d' => money($sum($ded, 'amount')), 'e' => money($sum($emp, 'amount')), 's' => money(round($shortfall, 2))]);
            break;

        case 'payroll':
            $columns = ['week' => ['Week ending', 'date'], 'status' => ['State', 'label'], 'people' => ['People', 'int'], 'gross' => ['Gross', 'money'],
                        'deductions' => ['Deductions', 'money'], 'employer' => ['Employer cost', 'money'], 'reimbursed' => ['Reimbursed', 'money'],
                        'net' => ['Net before tax', 'money'], 'adjustments' => ['Adjustments', 'money'], 'payable' => ['Payable', 'money'], 'paid' => ['Sheets paid', 'text']];
            $rows = [];
            foreach (rows('SELECT * FROM payroll_runs WHERE week_ending BETWEEN ? AND ? ORDER BY week_ending', [$from, $to]) as $run) {
                $s = payroll_run_summary($run);
                $sheets = array_sum(array_column($s['people'], 'sheets'));
                $paid = array_sum(array_column($s['people'], 'paid_sheets'));
                $rows[] = ['key' => 'w' . $run['week_ending'], 'week' => (string) $run['week_ending'], 'status' => (string) $run['status'], 'people' => count($s['people']),
                           'paid' => $paid . ' / ' . $sheets] + $s['totals'];
            }
            $totals = ['week' => count($rows)];
            foreach (['gross', 'deductions', 'employer', 'reimbursed', 'net', 'adjustments', 'payable'] as $c) {
                $totals[$c] = $sum($rows, $c);
            }
            $notes[] = t('Pay periods with their week ending in the range, every project. Net is before tax: ADP withholds and pays the taxes.');
            break;

        case 'loans':
            $columns = ['name' => ['Name', 'text'], 'project' => ['Project', 'text'], 'kind' => ['Kind', 'label'], 'status' => ['State', 'label'],
                        'paid_out' => ['Paid out', 'date'], 'amount' => ['Amount', 'money'], 'repaid' => ['Repaid', 'money'], 'balance' => ['Balance', 'money'],
                        'behind' => ['Behind schedule', 'money'], 'written_off' => ['Written off', 'money']];
            $sql = "SELECT a.*, c.full_name, j.title FROM wage_advances a JOIN candidates c ON c.id = a.candidate_id LEFT JOIN jobs j ON j.id = a.job_id
                    WHERE a.status IN ('approved','paid_out','cleared','written_off')";
            $args = [];
            if ($jobId > 0) {
                $sql .= ' AND a.job_id = ?';
                $args[] = $jobId;
            }
            $rows = [];
            foreach (rows($sql . ' ORDER BY c.full_name, a.id', $args) as $a) {
                $s = advance_schedule($a);
                $rows[] = ['key' => 'a' . $a['id'], 'name' => $a['full_name'], 'project' => (string) ($a['title'] ?? ''), 'kind' => (string) $a['kind'],
                           'status' => (string) $a['status'], 'paid_out' => (string) ($a['paid_out_on'] ?? ''), 'amount' => (float) $a['amount'],
                           'repaid' => (float) $s['repaid'], 'balance' => $a['status'] === 'written_off' ? 0.0 : advance_balance($a), 'behind' => (float) $s['behind'],
                           'written_off' => (float) ($a['written_off_amount'] ?? 0)];
            }
            $totals = ['name' => count($rows)];
            foreach (['amount', 'repaid', 'balance', 'behind', 'written_off'] as $c) {
                $totals[$c] = $sum($rows, $c);
            }
            $notes[] = t('Approved, paid out, repaid and written-off advances and loans. Requests not yet approved and cancelled ones are left out.');
            break;

        case 'benefits':
            $columns = ['plan' => ['Plan', 'text'], 'kind' => ['Kind', 'label'], 'provider' => ['Provider', 'text'], 'covered' => ['Covered', 'int'],
                        'waived' => ['Waived', 'int'], 'employee' => ['Employee cost per week', 'money'], 'employer' => ['Agency cost per week', 'money'],
                        'percent' => ['Paid as a share of pay', 'int']];
            $rows = [];
            foreach (rows('SELECT * FROM benefit_plans ORDER BY name, id') as $plan) {
                $on = "plan_id = ? AND starts_on <= ? AND (ends_on IS NULL OR ends_on >= ?)";
                $cov = rows("SELECT employee_amount, employer_amount, employee_percent FROM benefit_enrollments WHERE status IN ('enrolled','ended') AND $on", [(int) $plan['id'], $asof, $asof]);
                $waived = (int) val("SELECT COUNT(*) FROM benefit_enrollments WHERE status = 'waived' AND $on", [(int) $plan['id'], $asof, $asof]);
                if (! $cov && ! $waived && (int) $plan['is_active'] !== 1) {
                    continue;
                }
                $rows[] = ['key' => 'plan:' . $plan['code'], 'plan' => $plan['name'], 'kind' => (string) $plan['kind'], 'provider' => (string) $plan['provider'],
                           'covered' => count($cov), 'waived' => $waived, 'employee' => round(array_sum(array_map(fn($e) => (float) $e['employee_amount'], $cov)), 2),
                           'employer' => round(array_sum(array_map(fn($e) => (float) $e['employer_amount'], $cov)), 2),
                           'percent' => count(array_filter($cov, fn($e) => $e['employee_percent'] !== null))];
            }
            $totals = ['plan' => count($rows), 'covered' => (int) $sum($rows, 'covered'), 'waived' => (int) $sum($rows, 'waived'),
                       'employee' => $sum($rows, 'employee'), 'employer' => $sum($rows, 'employer')];
            $notes[] = t('Fixed amounts as enrolled. A share of pay varies with the week: what it took is in Deductions and contributions.');
            break;

        case 'performance':
            $columns = ['name' => ['Name', 'text'], 'reviews' => ['Reviews approved', 'int'], 'score' => ['Latest score', 'pct'], 'grade' => ['Grade', 'text'],
                        'rehire' => ['Would rehire', 'label'], 'goals_open' => ['Goals open', 'int'], 'goals_achieved' => ['Goals achieved', 'int'],
                        'goals_missed' => ['Goals missed', 'int'], 'actions_open' => ['Development open', 'int'], 'actions_overdue' => ['Development overdue', 'int']];
            $rows = [];
            $scores = [];
            foreach (report_people($jobId) as $c) {
                $cid = (int) $c['id'];
                $aSql = "SELECT a.* FROM appraisals a LEFT JOIN placements p ON p.id = a.placement_id WHERE a.candidate_id = ? AND a.status = 'approved' AND DATE(a.decided_at) BETWEEN ? AND ?";
                $aArgs = [$cid, $from, $to];
                if ($jobId > 0) {
                    $aSql .= ' AND p.job_id = ?';
                    $aArgs[] = $jobId;
                }
                $reviews = rows($aSql . ' ORDER BY a.decided_at DESC, a.id DESC', $aArgs);
                $g = row("SELECT COALESCE(SUM(status = 'open'), 0) AS o, COALESCE(SUM(status = 'achieved' AND DATE(closed_at) BETWEEN ? AND ?), 0) AS a,
                                 COALESCE(SUM(status = 'missed' AND DATE(closed_at) BETWEEN ? AND ?), 0) AS m FROM performance_goals WHERE candidate_id = ?", [$from, $to, $from, $to, $cid]);
                $d = row("SELECT COALESCE(SUM(status = 'open'), 0) AS o, COALESCE(SUM(status = 'open' AND due_on < CURDATE()), 0) AS late FROM development_actions WHERE candidate_id = ?", [$cid]);
                if (! $reviews && ! (int) $g['o'] && ! (int) $g['a'] && ! (int) $g['m'] && ! (int) $d['o']) {
                    continue;
                }
                $last = $reviews[0] ?? null;
                if ($last && $last['score_percent'] !== null) {
                    $scores[] = (float) $last['score_percent'];
                }
                $rows[] = ['key' => 'c' . $cid, 'name' => $c['full_name'], 'reviews' => count($reviews), 'score' => $last && $last['score_percent'] !== null ? (float) $last['score_percent'] : null,
                           'grade' => (string) ($last['grade'] ?? ''), 'rehire' => $last === null || $last['would_rehire'] === null ? '' : ((int) $last['would_rehire'] === 1 ? 'yes' : 'no'),
                           'goals_open' => (int) $g['o'], 'goals_achieved' => (int) $g['a'], 'goals_missed' => (int) $g['m'],
                           'actions_open' => (int) $d['o'], 'actions_overdue' => (int) $d['late']];
            }
            $totals = ['name' => count($rows), 'reviews' => (int) $sum($rows, 'reviews'), 'score' => $scores ? round(array_sum($scores) / count($scores), 1) : null];
            foreach (['goals_open', 'goals_achieved', 'goals_missed', 'actions_open', 'actions_overdue'] as $c) {
                $totals[$c] = (int) $sum($rows, $c);
            }
            $notes[] = t('Reviews counted on the day they were approved. The total score is the average of the latest scores shown.');
            break;

        default:
            throw new InvalidArgumentException('Unknown report');
    }

    return ['columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'notes' => $notes];
}
