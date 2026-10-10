<?php
/**
 * P4-M02 fixture, on the isolated test database only. Records dated in
 * 2016, a year no other test uses, on four projects of their own, so
 * analytics_http.py can expect exact figures:
 *
 *   March      recruitment: six applications on the hiring project
 *              placement: four positions ordered, offers made, one fell through
 *              utilization: two assignments with approved sheets
 *   April      revenue: two clients, a credit note, a payment, a hotel bill paid in part
 *   to date    profitability: a project with costs and a budget, nothing earned
 *   May        procurement: three orders on the buying project, one bill over its order
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n"); exit(2); }

$results = [];
function check(string $label, bool $condition, string $detail = ''): void
{
    global $results;
    if (! $condition) { fwrite(STDERR, 'FAIL ' . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL); exit(1); }
    $results[] = $label;
    echo 'PASS ' . $label . PHP_EOL;
}

check('Nothing is dated 2016 yet', ! val("SELECT COUNT(*) FROM applications WHERE created_at BETWEEN '2016-01-01' AND '2016-12-31 23:59:59'")
      && ! val("SELECT COUNT(*) FROM gl_journals WHERE posted_on BETWEEN '2016-01-01' AND '2016-12-31'"));

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'payroll' => 'payroll', 'recruiter' => 'recruiter', 'hotels' => 'hotels'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Analytics ' . $k, 'an-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
$admin = $ids['admin'];

$client = static function (string $name): int {
    q('INSERT INTO clients(name) VALUES (?)', [$name]);
    return (int) db()->lastInsertId();
};
$c1 = $client('Analytics client');
$c2 = $client('Analytics second client');
$job = static function (int $clientId, string $title, int $overhead = 0): int {
    q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status,overhead_percent) VALUES (?,?,'Synthetic project for the dashboards.',30,50,0,0,0,'active',?)",
      [$clientId, $title, $overhead]);
    return (int) db()->lastInsertId();
};
$hire = $job($c1, 'P4-M02 hiring project');
$other = $job($c2, 'P4-M02 other project');
$costed = $job($c1, 'P4-M02 costed project', 10);
$buying = $job($c1, 'P4-M02 buying project');

$person = static function (string $name): int {
    q('INSERT INTO candidates(full_name,email,stage) VALUES (?,?,?)', [$name, strtolower(str_replace(' ', '.', $name)) . '@test.invalid', 'new']);
    return (int) db()->lastInsertId();
};

// ── recruitment, March 2016 ──────────────────────────────────────────────
q("INSERT INTO vacancies(job_id,title,description,is_open,openings) VALUES (?,'Electrician','Synthetic vacancy.',1,4)", [$hire]);
$vac = (int) db()->lastInsertId();
q("INSERT INTO vacancies(job_id,title,description,is_open,openings) VALUES (?,'Helper','Synthetic vacancy.',1,1)", [$other]);
$vacOther = (int) db()->lastInsertId();
$apply = static function (int $vacancy, string $name, string $stage, string $source, string $created, array $events) use ($person, $admin): int {
    $c = $person($name);
    q('INSERT INTO applications(candidate_id,vacancy_id,stage,source,created_at) VALUES (?,?,?,?,?)', [$c, $vacancy, $stage, $source, $created]);
    $id = (int) db()->lastInsertId();
    foreach ($events as [$st, $at]) {
        q('INSERT INTO application_events(application_id,user_id,stage,note,created_at) VALUES (?,?,?,?,?)', [$id, $admin, $st, '', $at]);
    }
    return $c;
};
$cA1 = $apply($vac, 'Alma Funnel', 'accepted', 'referral', '2016-03-01 09:00:00',
              [['screening', '2016-03-02 09:00:00'], ['interview', '2016-03-04 09:00:00'], ['offered', '2016-03-08 09:00:00'], ['accepted', '2016-03-11 09:00:00']]);
$apply($vac, 'Bram Funnel', 'rejected', 'referral', '2016-03-02 09:00:00', [['screening', '2016-03-03 09:00:00'], ['interview', '2016-03-05 09:00:00'], ['rejected', '2016-03-07 09:00:00']]);
$apply($vac, 'Cleo Funnel', 'withdrawn', 'job board', '2016-03-03 09:00:00', [['screening', '2016-03-04 09:00:00'], ['offered', '2016-03-09 09:00:00'], ['withdrawn', '2016-03-10 09:00:00']]);
$apply($vac, 'Dino Funnel', 'screening', 'job board', '2016-03-04 09:00:00', [['screening', '2016-03-05 09:00:00']]);
$apply($vac, 'Elsa Funnel', 'new', '', '2016-03-06 09:00:00', []);
$cA6 = $apply($vac, 'Fritz Funnel', 'accepted', 'job board', '2016-03-05 09:00:00',
              [['screening', '2016-03-06 09:00:00'], ['interview', '2016-03-10 09:00:00'], ['offered', '2016-03-20 09:00:00'], ['accepted', '2016-03-25 09:00:00']]);
$apply($vacOther, 'Gina Funnel', 'new', 'job board', '2016-03-07 09:00:00', []);

// ── placement and utilization, March 2016 ────────────────────────────────
q("INSERT INTO job_order_lines(job_id,role_title,discipline,quantity,pay_rate,bill_rate,sort_order) VALUES (?,'Electrician','electrical',4,30,50,1)", [$hire]);
$place = static function (int $cand, string $status, string $start, string $created, int $guarantee = 0) use ($hire, $admin): int {
    q('INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate,created_by,created_at,guarantee_hours) VALUES (?,?,?,?,30,50,?,?,?)', [$cand, $hire, $status, $start, $admin, $created, $guarantee]);
    return (int) db()->lastInsertId();
};
$cVet = $person('Hugo Steady');
$p0 = $place($cVet, 'on_site', '2016-01-04', '2016-01-02 09:00:00');
$p1 = $place($cA1, 'on_site', '2016-03-14', '2016-03-12 09:00:00', 50);
$p2 = $place($cA6, 'confirmed', '2016-04-04', '2016-03-26 09:00:00');
$p3 = $place($person('Ivan Gone'), 'cancelled', '2016-03-28', '2016-03-20 09:00:00');
$p4 = $place($person('Jade Pending'), 'offered', '2016-04-11', '2016-03-28 09:00:00');
$sheet = static function (int $placement, string $week, float $hours, float $leave = 0) use ($admin): void {
    q("INSERT INTO timesheets(placement_id,week_ending,hours_worked,paid_leave_hours,status,approved_by,approved_at) VALUES (?,?,?,?,'approved',?,NOW())", [$placement, $week, $hours, $leave, $admin]);
};
foreach ([['2016-03-05', 40], ['2016-03-12', 40], ['2016-03-19', 30], ['2016-03-26', 40, 8]] as $s) {
    $sheet($p0, $s[0], $s[1], $s[2] ?? 0);
}
$sheet($p1, '2016-03-19', 50);
$sheet($p1, '2016-03-26', 50);
$bench = $person('Kim Bench');
q("INSERT INTO employee_profiles(candidate_id,employment_type,availability) VALUES (?,'hourly','available')", [$bench]);

// ── revenue, April 2016 ──────────────────────────────────────────────────
$invoice = static function (int $jobId, float $total, string $issued) use ($admin): int {
    q("INSERT INTO client_invoices(job_id,reference,starts_on,ends_on,total,details_json,status,created_by,created_at,issued_at,due_on) VALUES (?,?,?,?,?,'{}','issued',?,?,?,?)",
      [$jobId, 'AN-' . strtoupper(bin2hex(random_bytes(3))), $issued, $issued, $total, $admin, $issued . ' 09:00:00', $issued . ' 10:00:00', date('Y-m-d', strtotime($issued . ' +30 days'))]);
    return (int) db()->lastInsertId();
};
$i1 = $invoice($hire, 10000, '2016-04-05');
$invoice($other, 5000, '2016-04-10');
q("INSERT INTO ar_credits(invoice_id,amount,issued_on,reason,created_by) VALUES (?,1000,'2016-04-20','Short shipment of hours',?)", [$i1, $admin]);
q("INSERT INTO ar_payments(client_id,received_on,amount,method,reference,created_by) VALUES (?,'2016-04-25',6000,'check','AN-CHK-1',?)", [$c1, $admin]);
q('INSERT INTO ar_allocations(payment_id,invoice_id,amount,created_by) VALUES (?,?,6000,?)', [(int) db()->lastInsertId(), $i1, $admin]);
q("INSERT INTO hotels(name) VALUES ('Analytics inn')");
$hotel = (int) db()->lastInsertId();
q("INSERT INTO vendor_invoices(job_id,hotel_id,vendor_name,reference,amount,due_on,status) VALUES (?,?,'Analytics inn',?,2000,'2016-04-15','approved')", [$hire, $hotel, 'ANB-' . bin2hex(random_bytes(4))]);
$hotelBill = (int) db()->lastInsertId();
q("INSERT INTO ap_payments(vendor_name,received_on,amount,method,reference,created_by) VALUES ('Analytics inn','2016-04-28',1500,'ach','AN-ACH-1',?)", [$admin]);
q('INSERT INTO ap_allocations(payment_id,invoice_id,amount,created_by) VALUES (?,?,1500,?)', [(int) db()->lastInsertId(), $hotelBill, $admin]);

// ── profitability, to date ───────────────────────────────────────────────
$cCost = $person('Lena Costed');
q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate,created_by) VALUES (?,?,'on_site','2016-02-01',30,50,?)", [$cCost, $costed, $admin]);
$pCost = (int) db()->lastInsertId();
q("INSERT INTO worker_documents(candidate_id,uploaded_by,document_type,original_name,storage_name,mime_type,file_hash) VALUES (?,?,'receipt','r.pdf',?,'application/pdf',?)",
  [$cCost, $admin, bin2hex(random_bytes(32)), str_repeat('0', 64)]);
q("INSERT INTO expense_claims(placement_id,user_id,category,amount,receipt_document_id,status,payer,paid_at) VALUES (?,?,'other',400,?,'paid','agency','2016-02-10 09:00:00')", [$pCost, $admin, (int) db()->lastInsertId()]);
q("INSERT INTO vendor_invoices(job_id,vendor_name,reference,amount,due_on,status) VALUES (?,'Costed supplies',?,600,'2016-02-20','approved')", [$costed, 'ANC-' . bin2hex(random_bytes(4))]);
foreach ([['revenue', 5000], ['labour', 2000]] as [$cat, $amt]) {
    q('INSERT INTO project_budget_changes(job_id,category,amount,reason,changed_by,changed_at) VALUES (?,?,?,?,?,NOW())', [$costed, $cat, $amt, 'Plan', $admin]);
}
$unit = (int) val('SELECT id FROM procurement_units ORDER BY id LIMIT 1');
$request = static function (int $jobId, string $category, string $title, string $status, float $qty) use ($unit, $admin): int {
    q('INSERT INTO purchase_requests(job_id,category,title,quantity,unit_id,status,requested_by) VALUES (?,?,?,?,?,?,?)', [$jobId, $category, $title, $qty, $unit, $status, $admin]);
    return (int) db()->lastInsertId();
};
$order = static function (int $jobId, int $req, string $vendor, float $qty, float $price, string $status, string $created, ?string $decided) use ($unit, $admin): int {
    q('INSERT INTO purchase_orders(reference,job_id,request_id,vendor_name,quantity,unit_id,unit_price,total,status,created_by,created_at,decided_by,decided_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
      ['AN-PO-' . bin2hex(random_bytes(3)), $jobId, $req, $vendor, $qty, $unit, $price, round($qty * $price, 2), $status, $admin, $created, $decided ? $admin : null, $decided]);
    return (int) db()->lastInsertId();
};
$order($costed, $request($costed, 'vehicle', 'Pickup', 'ordered', 1), 'Costed rentals', 1, 700, 'approved', '2016-02-01 09:00:00', '2016-02-02 09:00:00');

// ── procurement, May 2016 ────────────────────────────────────────────────
$request($buying, 'safety_equipment', 'Gloves', 'requested', 10);
$o1 = $order($buying, $request($buying, 'vehicle', 'Van', 'ordered', 2), 'Wheels Co', 2, 500, 'approved', '2016-05-02 10:00:00', '2016-05-04 10:00:00');
$o2 = $order($buying, $request($buying, 'safety_equipment', 'Harnesses', 'ordered', 3), 'Gear Co', 3, 100, 'approved', '2016-05-10 10:00:00', '2016-05-14 10:00:00');
$order($buying, $request($buying, 'vehicle', 'Trailer', 'ordered', 1), 'Wheels Co', 1, 250, 'awaiting_approval', '2016-05-20 10:00:00', null);
q("INSERT INTO purchase_receipts(purchase_order_id,quantity,received_on,received_by,rejected_quantity,rejection_reason) VALUES (?,1,'2016-05-06',?,1,'Damaged')", [$o1, $admin]);
q("INSERT INTO purchase_receipts(purchase_order_id,quantity,received_on,received_by,rejected_quantity) VALUES (?,3,'2016-05-16',?,0)", [$o2, $admin]);
q("INSERT INTO vendor_invoices(job_id,vendor_name,reference,amount,due_on,status,purchase_order_id) VALUES (?,'Wheels Co',?,400,'2016-06-01','received',?)", [$buying, 'ANV-' . bin2hex(random_bytes(4)), $o1]);
q("INSERT INTO vendor_invoices(job_id,vendor_name,reference,amount,due_on,status,purchase_order_id) VALUES (?,'Gear Co',?,500,'2016-06-01','received',?)", [$buying, 'ANV-' . bin2hex(random_bytes(4)), $o2]);

file_put_contents(__DIR__ . '/analytics.json', json_encode(['ids' => $ids, 'hire' => $hire, 'other' => $other, 'costed' => $costed, 'buying' => $buying,
    'p0' => $p0, 'p1' => $p1, 'bench' => $bench]));
check('The P4-M02 fixture is in place', (int) val('SELECT COUNT(*) FROM jobs WHERE id IN (?,?,?,?)', [$hire, $other, $costed, $buying]) === 4);
file_put_contents(__DIR__ . '/an-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
