<?php
/**
 * A worked example: one agreement, its scope of work, and ten people on it.
 *
 *   php install/demo-crewvia.php            add what is missing
 *   php install/demo-crewvia.php --remove   take it all out again
 *
 * Why this exists. Every screen in this application answers a question
 * about a real situation - who is still to place on the engineer line, who
 * has nobody to call them, which hotel block is nearly full - and an empty
 * installation answers all of them with a dash. Walking the product, or
 * showing it to somebody, needs a situation to walk through.
 *
 * Everything it creates is obviously fictitious: the people are named
 * Demo, their email addresses are at example.invalid, which can never
 * receive mail, and their telephone numbers are in the 555 range reserved
 * for fiction. Nothing here is a real person.
 *
 * Safe to run twice: it looks for its own records by name and adds only
 * what is missing. --remove deletes exactly what it created and nothing
 * else, so a demonstration can be cleared off a live workspace.
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/scope.php';
require_once __DIR__ . '/../app/placement-rates.php';

if (PHP_SAPI !== 'cli') {
    exit('Command line only.');
}

const DEMO_CLIENT  = 'Demo Steel Works';
const DEMO_PROJECT = 'Demo strike coverage — Gary plant';
const DEMO_MARK    = 'demo-crewvia';

$remove = in_array('--remove', $argv, true);

/** Say what happened, in the same shape as the installer and the upgrade. */
$say = static function (string $line): void { echo $line . PHP_EOL; };

// ── taking it out again ─────────────────────────────────────────────────
if ($remove) {
    $job = row('SELECT id FROM jobs WHERE title = ?', [DEMO_PROJECT]);

    if (! $job) {
        $say('Nothing to remove: the demonstration is not on this workspace.');
        exit(0);
    }

    $jobId = (int) $job['id'];

    db()->beginTransaction();

    // Children first: this database declines to orphan a row, which is
    // exactly the behaviour that makes deleting in the wrong order fail
    // halfway and leave a mess.
    q('DELETE l FROM lodging l JOIN placements p ON p.id = l.placement_id WHERE p.job_id = ?', [$jobId]);
    q('DELETE t FROM travel t JOIN placements p ON p.id = t.placement_id WHERE p.job_id = ?', [$jobId]);
    q('DELETE d FROM assignment_details d JOIN placements p ON p.id = d.placement_id WHERE p.job_id = ?', [$jobId]);
    q('DELETE FROM placements WHERE job_id = ?', [$jobId]);
    q('DELETE a FROM applications a JOIN vacancies v ON v.id = a.vacancy_id WHERE v.job_id = ?', [$jobId]);
    q('DELETE r FROM requisition_publication r JOIN vacancies v ON v.id = r.vacancy_id WHERE v.job_id = ?', [$jobId]);
    q('DELETE FROM vacancies WHERE job_id = ?', [$jobId]);
    q('DELETE FROM job_order_lines WHERE job_id = ?', [$jobId]);

    $people = rows('SELECT id FROM candidates WHERE source = ?', [DEMO_MARK]);

    foreach ($people as $person) {
        q('DELETE FROM candidate_calls WHERE candidate_id = ?', [(int) $person['id']]);
        q('DELETE FROM candidate_skills WHERE candidate_id = ?', [(int) $person['id']]);

        // employee_profiles points at candidates without ON DELETE CASCADE,
        // so the person cannot be removed while a profile holds them. The
        // register puts a profile on anybody it blocks.
        q('DELETE FROM employee_profiles WHERE candidate_id = ?', [(int) $person['id']]);
        q('DELETE FROM candidate_events WHERE candidate_id = ?', [(int) $person['id']]);
        q('DELETE FROM candidates WHERE id = ?', [(int) $person['id']]);
    }

    q('DELETE FROM jobs WHERE id = ?', [$jobId]);
    q('DELETE FROM clients WHERE name = ? AND NOT EXISTS
         (SELECT 1 FROM jobs j WHERE j.client_id = clients.id)', [DEMO_CLIENT]);

    db()->commit();

    $say('Demonstration removed: 1 agreement, ' . count($people) . ' people.');
    exit(0);
}

// Whose name the demonstration records carry. Every contact was made by
// somebody, and the column says so, so the oldest administrator stands in.
$actor = val("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1");

if (! $actor) {
    exit('No administrator on this workspace yet. Run the installer first.' . PHP_EOL);
}

$actor = (int) $actor;

// ── the client and the agreement ────────────────────────────────────────
db()->beginTransaction();

$client = row('SELECT id FROM clients WHERE name = ?', [DEMO_CLIENT]);

if (! $client) {
    q('INSERT INTO clients(name) VALUES (?)', [DEMO_CLIENT]);
    $client = ['id' => (int) db()->lastInsertId()];
    $say('Client created: ' . DEMO_CLIENT);
}

$job = row('SELECT id FROM jobs WHERE title = ?', [DEMO_PROJECT]);

if (! $job) {
    q("INSERT INTO jobs (client_id, title, description, order_reference,
                         site_name, site_city, site_state,
                         lodging_provided, travel_provided, transport_provided,
                         starts_on, ends_on, status, headcount_target, strike_live)
       VALUES (?,?,?,?,?,?,?,1,1,1,?,?,'active',0,0)", [
        (int) $client['id'],
        DEMO_PROJECT,
        'Provide skilled labour support to the Demo Steel Works plant at Gary, Indiana '
        . 'for the duration of the work stoppage, in accordance with the scope of work. '
        . 'The agency supplies lodging, travel to and from site, and transport on site.',
        'PO-DEMO-4417',
        'Gary plant', 'Gary', 'IN',
        date('Y-m-d', strtotime('-10 days')),
        date('Y-m-d', strtotime('+80 days')),
    ]);

    $job = ['id' => (int) db()->lastInsertId()];
    $say('Agreement created: ' . DEMO_PROJECT);
}

$jobId = (int) $job['id'];

// ── the scope of work: four trades, four sets of terms ──────────────────
$scope = [
    ['Plant engineer',       'mechanical',      12, 'Days, 12 hours',   68.00, 102.00, 45.00, 50, 60],
    ['Mechanical technician', 'mechanical',     40, 'Rotating, 12 hours', 42.00, 66.00, 40.00, 50, 60],
    ['Instrument technician', 'instrumentation', 8, 'Days, 10 hours',   47.00, 72.00, 40.00, 50, 60],
    ['General labourer',      'other',          60, 'Nights, 12 hours', 22.50, 38.00, 35.00, 40, 60],
];

$lines = [];

foreach ($scope as $order => $line) {
    [$role, $discipline, $quantity, $shift, $pay, $bill, $diem, $guarantee, $strike] = $line;

    $existing = row('SELECT id FROM job_order_lines WHERE job_id = ? AND role_title = ?',
                    [$jobId, $role]);

    if ($existing) {
        $lines[$role] = (int) $existing['id'];
        continue;
    }

    q('INSERT INTO job_order_lines
         (job_id, role_title, discipline, quantity, shift, pay_rate, bill_rate,
          per_diem_rate, guarantee_hours, strike_guarantee_hours, overtime_after,
          overtime_multiplier, sort_order)
       VALUES (?,?,?,?,?,?,?,?,?,?,40,1.5,?)',
      [$jobId, $role, $discipline, $quantity, $shift, $pay, $bill, $diem,
       $guarantee, $strike, $order + 1]);

    $lines[$role] = (int) db()->lastInsertId();
    $say('  scope line: ' . $quantity . ' x ' . $role . ' at ' . money($pay) . ' an hour');
}

q('UPDATE jobs SET headcount_target =
     (SELECT COALESCE(SUM(quantity), 0) FROM job_order_lines WHERE job_id = ?)
   WHERE id = ?', [$jobId, $jobId]);

// ── a requisition against each line, open to applicants ─────────────────
$requisitions = [];

foreach ($lines as $role => $lineId) {
    $line = scope_line($lineId, $jobId);

    $existing = row('SELECT id FROM vacancies WHERE job_id = ? AND order_line_id = ?',
                    [$jobId, $lineId]);

    if ($existing) {
        $requisitions[$role] = (int) $existing['id'];
        continue;
    }

    q('INSERT INTO vacancies (job_id, order_line_id, title, description, discipline,
                              openings, shift, requirements, starts_on, is_open)
       VALUES (?,?,?,?,?,?,?,?,?,1)',
      [$jobId, $lineId, $role,
       'Short-notice industrial assignment at a steel plant in Gary, Indiana. '
       . 'Lodging, travel and transport on site are provided, with a daily allowance '
       . 'on top of the hourly rate. Work is continuous for the duration of the stoppage.',
       // Part of the line, not all of it: a demonstration where every
       // trade is already fully ordered cannot show a requisition being
       // raised, which is the thing a recruiter does most.
       $line['discipline'], max(1, (int) ceil((int) $line['quantity'] / 2)), $line['shift'],
       "Valid photo identification\nRight to work in the United States\nSteel-toe boots and hard hat",
       date('Y-m-d', strtotime('-7 days'))]);

    $requisitions[$role] = (int) db()->lastInsertId();

    q('INSERT INTO requisition_publication (vacancy_id, published_on, expires_on)
       VALUES (?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))',
      [$requisitions[$role]]);

    $say('  requisition open: ' . $role);
}

// ── ten people, spread across the trades and across the pipeline ────────
//
// The spread is the point: a demonstration where everybody is at the same
// stage shows none of the work. There is somebody nobody has called, a
// couple part-way through screening, one offered, and two already placed.
// What each of them can do, which is the question a recruiter is asked:
// "find me the welders". Several per person, as in life.
$skillsFor = [
    'Demo Amara Okafor'   => ['millwright', 'maintenance'],
    'Demo Luis Hernández' => ['pipefitter', 'welder'],
    'Demo Priya Raman'    => ['instrument_tech', 'electrician'],
    'Demo Kwame Boateng'  => ['millwright', 'machinist', 'welder'],
    'Demo Sofia Petrova'  => ['machine_operator', 'forklift'],
    'Demo Tomas Novak'    => ['welder', 'tank_welder', 'rigger'],
    'Demo Grace Mwangi'   => ['general_labour', 'forklift'],
    'Demo Ibrahim Diallo' => ['general_labour'],
    'Demo Elena Vasquez'  => ['instrument_tech', 'cnc_operator'],
    'Demo Marcus Bell'    => ['millwright', 'boilermaker', 'welder'],
];

$people = [
    ['Demo Amara Okafor',    'Plant engineer',        'mechanical',      'Gary',        'IN', 'new',       null],
    ['Demo Luis Hernández',  'Plant engineer',        'mechanical',      'Hammond',     'IN', 'contacted', 'reached'],
    ['Demo Priya Raman',     'Instrument technician', 'instrumentation', 'Chicago',     'IL', 'screening', 'reached'],
    ['Demo Kwame Boateng',   'Mechanical technician', 'mechanical',      'East Chicago','IN', 'screening', 'emailed'],
    ['Demo Sofia Petrova',   'Mechanical technician', 'mechanical',      'Merrillville','IN', 'contacted', 'voicemail'],
    ['Demo Tomas Novak',     'Mechanical technician', 'mechanical',      'Portage',     'IN', 'offered',   'reached'],
    ['Demo Grace Mwangi',    'General labourer',      'other',           'Gary',        'IN', 'new',       null],
    ['Demo Ibrahim Diallo',  'General labourer',      'other',           'Chicago',     'IL', 'contacted', 'callback'],
    ['Demo Elena Vasquez',   'Instrument technician', 'instrumentation', 'Valparaiso',  'IN', 'placed',    'reached'],
    ['Demo Marcus Bell',     'Plant engineer',        'mechanical',      'Gary',        'IN', 'placed',    'reached'],
];

$applicationStage = [
    'new'       => 'new',
    'contacted' => 'new',
    'screening' => 'screening',
    'offered'   => 'offered',
    'placed'    => 'accepted',
];

$added = 0;

foreach ($people as $i => $person) {
    [$name, $role, $discipline, $city, $state, $stage, $outcome] = $person;

    if (row('SELECT id FROM candidates WHERE full_name = ?', [$name])) {
        continue;
    }

    $slug  = strtolower(preg_replace('/[^a-z]+/i', '.', substr($name, 5)));
    $email = trim($slug, '.') . '@example.invalid';
    $phone = '+1 555 01' . str_pad((string) ($i + 10), 2, '0', STR_PAD_LEFT);

    q("INSERT INTO candidates (full_name, email, phone, city, state, discipline,
                               stage, source, years_exp, notes, last_contact_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)",
      [$name, $email, $phone, $city, $state, $discipline, $stage, DEMO_MARK,
       3 + ($i % 12),
       'Fictitious record created by install/demo-crewvia.php for demonstration.',
       $outcome ? date('Y-m-d H:i:s', strtotime('-' . (1 + $i) . ' days')) : null]);

    $candidateId = (int) db()->lastInsertId();

    // What they applied for. A person on file with no application cannot be
    // screened, offered or contracted - the whole pipeline hangs off it, and
    // a candidate imported without one is a dead end on every screen.
    q("INSERT INTO applications (candidate_id, vacancy_id, stage, source)
       VALUES (?,?,?,'Demonstration data')",
      [$candidateId, $requisitions[$role], $applicationStage[$stage]]);

    if ($outcome) {
        q("INSERT INTO candidate_calls (candidate_id, user_id, outcome, note, called_at)
           VALUES (?,?,?,?,?)",
          [$candidateId, $actor, $outcome,
           'Demonstration contact record.',
           date('Y-m-d H:i:s', strtotime('-' . (1 + $i) . ' days'))]);
    }

    // The two placed people are actually on the roster, with the terms from
    // their line of the scope, so the deployment screens have something real.
    if ($stage === 'placed') {
        $terms = placement_rates($jobId, $requisitions[$role]);

        q("INSERT INTO placements (candidate_id, job_id, status, start_date, created_by,
                                   pay_rate, bill_rate, per_diem_rate, guarantee_hours)
           VALUES (?,?,'on_site',?,?,?,?,?,?)",
          [$candidateId, $jobId, date('Y-m-d', strtotime('-5 days')), $actor,
           $terms['pay_rate'], $terms['bill_rate'],
           $terms['per_diem_rate'], $terms['guarantee_hours']]);

        $placementId = (int) db()->lastInsertId();

        q('INSERT INTO assignment_details (placement_id, trade, shift_label)
           VALUES (?,?,?)', [$placementId, $role, 'Days, 12 hours']);
    }

    foreach ($skillsFor[$name] ?? [] as $slug) {
        q('INSERT IGNORE INTO candidate_skills(candidate_id, skill_slug, years, confirmed, added_by)
           VALUES (?,?,?,1,?)',
          [$candidateId, $slug, 2 + ($i % 9), $actor]);
    }

    $added++;
}

// The case RSS described: hired onto a job he should never have been on,
// gone after two days, and the only record a red line on a spreadsheet
// that nobody else could see. Here it is on the person, where the whole
// desk reads it.
$barred = row('SELECT id, full_name FROM candidates WHERE full_name = ?',
              ['Demo Ibrahim Diallo']);

if ($barred && ! val("SELECT COUNT(*) FROM employee_profiles
                      WHERE candidate_id = ? AND rehire_status IN ('ineligible','do_not_use')",
                     [(int) $barred['id']])) {
    q('INSERT IGNORE INTO employee_profiles(candidate_id) VALUES (?)', [(int) $barred['id']]);

    q("UPDATE employee_profiles
          SET rehire_status = 'do_not_use', exclusion_reason = ?, excluded_by = ?, excluded_at = ?
        WHERE candidate_id = ?",
      ['Walked off the Gary job on day two without telling anybody. '
       . 'Supervisor reported tools left on site. Do not contact again.',
       $actor, date('Y-m-d H:i:s', strtotime('-3 days')), (int) $barred['id']]);

    q('INSERT INTO candidate_events(candidate_id, user_id, event_type, detail) VALUES (?,?,?,?)',
      [(int) $barred['id'], $actor, 'rehire decision',
       'DO NOT USE - walked off the Gary job on day two.']);

    $say('  on the register: ' . $barred['full_name'] . ' (DO NOT USE)');
}

db()->commit();

$totals = scope_totals($jobId, false);

$say('People added: ' . $added);
$say('');
$say('The demonstration is ready.');
$say('  Agreement:  ' . DEMO_PROJECT);
$say('  Scope:      ' . $totals['people'] . ' people across ' . $totals['lines'] . ' trades');
$say('  A full week: ' . money($totals['weekly_pay']) . ' paid, '
     . money($totals['weekly_bill']) . ' billed, '
     . money($totals['weekly_margin']) . ' margin');
$say('');
$say('Open Projects, switch to it, and start at the scope of work.');
$say('Remove it again with: php install/demo-crewvia.php --remove');
