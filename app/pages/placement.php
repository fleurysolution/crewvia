<?php
/** One deployed engineer: everything RSS owes them, on one page. */

require_role('recruiter','hotels','payroll');

$id = (int) ($_GET['id'] ?? 0);

$p = row(
    "SELECT p.*, c.full_name, c.phone, c.email, c.discipline, c.city, c.state, c.degree,
            j.title AS job_title, j.strike_live, j.pay_rule_set_id
     FROM placements p
     JOIN candidates c ON c.id = p.candidate_id
     JOIN jobs j       ON j.id = p.job_id
     WHERE p.id = ?", [$id]);

if (! $p) {
    http_response_code(404);
    render('404', ['path' => 'that placement']);
    exit;
}

// Walkthrough finding 22: nothing could change a placement's dates once it
// existed, and one made at acceptance had none. Arrivals, roll call and pay
// all read them. Recruiting and Logistics set them; each change is logged.
// P5-M84: HR puts the person in a group, under its supervisor - or marks them the one who leads it.
// P5-M97: Send home - the assignment ends, with everything that goes with it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'send_home') {
    require_once __DIR__ . '/../send-home.php';
    $shBack = (string) ($_POST['back'] ?? '/');
    $shBack = str_starts_with($shBack, '/') && ! str_starts_with($shBack, '//') && ! preg_match('/[\r\n]/', $shBack) ? $shBack : '/';
    $why = send_home((int) ($_POST['placement_id'] ?? 0), $_POST, uid());
    if ($why !== null) {
        refuse(422, $why, $shBack);
    }
    flash(t('Sent home. The flight home, the ADP termination and anything still out are with the people who finish them.'));
    redirect($shBack);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'group') {
    require_role('recruiter', 'hotels');
    $group = mb_substr(trim((string) ($_POST['crew_group'] ?? '')), 0, 60);
    $leads = ! empty($_POST['leads_group']) ? 1 : 0;
    $sup = (int) ($_POST['supervisor_id'] ?? 0);
    if ($sup && ! val("SELECT id FROM users WHERE id = ? AND is_active = 1 AND role IN ('supervisor','admin')", [$sup])) {
        refuse(422, t('Choose a supervisor.'), '/placements/' . $id . '#group');
    }
    if (val('SELECT placement_id FROM assignment_details WHERE placement_id = ?', [$id])) {
        q('UPDATE assignment_details SET crew_group = ?, leads_group = ?, supervisor_id = ? WHERE placement_id = ?', [$group ?: null, $leads, $sup ?: null, $id]);
    } else {
        // The trade is required on the row: it comes from the line of the scope the person was placed on.
        $trade = (string) (val('SELECT ol.role_title FROM placements p2 JOIN job_order_lines ol ON ol.id = p2.order_line_id WHERE p2.id = ?', [$id]) ?: '');
        q('INSERT INTO assignment_details (placement_id, trade, crew_group, leads_group, supervisor_id) VALUES (?,?,?,?,?)', [$id, $trade, $group ?: null, $leads, $sup ?: null]);
    }
    if ($sup) {
        require_once __DIR__ . '/../notices.php';
        notify_user($sup, t(':name joins your crew:group.', ['name' => $p['full_name'], 'group' => $group !== '' ? ' (' . $group . ')' : '']), '/my-team');
    }
    log_activity('set the group and supervisor', 'placement', $id, trim($group . ($leads ? ' (leads it)' : '')));
    flash(t('Saved: group and supervisor.'));
    redirect('/placements/' . $id . '#group');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'dates') {
    require_role('recruiter', 'hotels');
    $start = trim((string) ($_POST['start_date'] ?? ''));
    $end   = trim((string) ($_POST['end_date'] ?? ''));
    $valid = static fn(string $d): bool => $d === '' || (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
    if (! $valid($start) || ! $valid($end) || ($start !== '' && $end !== '' && $end < $start)) {
        flash(t('Check the dates: the end cannot come before the start.'), 'err');
        redirect('/placements/' . $id);
    }
    q('UPDATE placements SET start_date = ?, end_date = ? WHERE id = ?', [$start ?: null, $end ?: null, $id]);
    $was = ($p['start_date'] ?? '—') . ' → ' . ($p['end_date'] ?? '—');
    $now = ($start ?: '—') . ' → ' . ($end ?: '—');
    q('INSERT INTO candidate_events (candidate_id, user_id, event_type, detail) VALUES (?, ?, ?, ?)', [(int) $p['candidate_id'], uid(), 'placement dates', $was . ' became ' . $now]);
    log_activity('changed placement dates', 'placement', $id, $was . ' became ' . $now);
    flash(t('Dates saved.'));
    redirect('/placements/' . $id);
}

// Where this person's terms come from when their placement did not record
// its own: the line of the scope of work they were recruited against. There
// is no project-wide rate to fall back to any more, because one rate was
// never what the client agreed - a line per trade is.
require_once __DIR__ . '/../scope.php';

$line = scope_line_for_placement((int) $p['candidate_id'], (int) $p['job_id'],
                                 isset($p['order_line_id']) ? (int) $p['order_line_id'] : null);

// Whether the line was recorded when the placement was made, or is the
// fallback guess from the latest application. The page says which.
$p['line_recorded'] = ! empty($p['order_line_id']) && $line
                      && (int) $line['id'] === (int) $p['order_line_id'];

$job = [
    'pay_rate'        => $line['pay_rate'] ?? null,
    'bill_rate'       => $line['bill_rate'] ?? null,
    'per_diem_rate'   => $line['per_diem_rate'] ?? null,
    'guarantee_hours' => $line['guarantee_hours'] ?? null,
    'strike_hours'    => $line['strike_guarantee_hours'] ?? null,
    'strike_live'     => $p['strike_live'],
    // Weeks on this page are paid under the project's pay rules, as on Hours.
    'pay_rule_set_id' => $p['pay_rule_set_id'],
];

// The views read these the way they always did.
$p['job_pay']        = $job['pay_rate'];
$p['job_bill']       = $job['bill_rate'];
$p['job_per_diem']   = $job['per_diem_rate'];
$p['guarantee_hours'] = $p['guarantee_hours'] ?? $job['guarantee_hours'];
$p['strike_hours']   = $job['strike_hours'];
$p['line_role']      = $line['role_title'] ?? null;

$lodge = row('SELECT l.*, h.name AS hotel, h.phone AS hotel_phone, h.address
              FROM lodging l JOIN hotels h ON h.id = l.hotel_id
              WHERE l.placement_id = ? AND l.status <> ? ORDER BY l.id DESC LIMIT 1',
             [$id, 'cancelled']);

$legs = rows('SELECT * FROM travel WHERE placement_id = ? AND status <> ? ORDER BY direction',
             [$id, 'cancelled']);

require_once __DIR__ . '/../messaging.php';
$textPanel = msg_panel((int) $p['candidate_id'], $id);
// P5-M84: the person's group and supervisor, and the groups already on the project.
$groupRow = row('SELECT crew_group, leads_group, supervisor_id FROM assignment_details WHERE placement_id = ?', [$id]) ?: ['crew_group' => null, 'leads_group' => 0, 'supervisor_id' => null];
$groupNames = array_column(rows("SELECT DISTINCT d.crew_group FROM assignment_details d JOIN placements p2 ON p2.id = d.placement_id WHERE p2.job_id = ? AND d.crew_group IS NOT NULL AND d.crew_group <> '' ORDER BY d.crew_group", [(int) $p['job_id']]), 'crew_group');
$supervisorChoices = rows("SELECT id, name FROM users WHERE is_active = 1 AND role IN ('supervisor','admin') ORDER BY role = 'admin', name");
if(!can('payroll')) { render('placement-logistics',compact('p','lodge','legs','textPanel','groupRow','groupNames','supervisorChoices'));exit; }
$sheets = rows('SELECT ts.*,s.result_json AS snapshot_json FROM timesheets ts LEFT JOIN pay_snapshots s ON s.timesheet_id=ts.id WHERE placement_id = ? ORDER BY week_ending DESC LIMIT 12', [$id]);


$totals = ['pay' => 0.0, 'bill' => 0.0, 'hours' => 0.0];

foreach ($sheets as $s) {
    $m = week_money($s, $p, $job);
    $totals['pay']   += $m['pay_total'];
    $totals['bill']  += $m['bill_total'];
    $totals['hours'] += $m['worked'];
}

// Pay information, so it is read only past the payroll check above.
$origin = ! empty($p['vacancy_id'])
    ? row('SELECT id, title FROM vacancies WHERE id = ? AND job_id = ?',
          [(int) $p['vacancy_id'], (int) $p['job_id']])
    : null;

$rateChanges = rows('SELECT r.*, u.name AS changed_by_name FROM placement_rate_changes r
                     LEFT JOIN users u ON u.id = r.changed_by
                     WHERE r.placement_id = ? ORDER BY r.id DESC LIMIT 50', [$id]);

$pageTitle = $p['full_name'] . ' · '.$config['app_name'];
render('placement', compact('p','lodge','legs','sheets','job','totals','origin','line','rateChanges','textPanel','groupRow','groupNames','supervisorChoices'));
