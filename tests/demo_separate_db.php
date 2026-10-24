<?php
/**
 * P5-M109 on the test database: real requisitions raised on a demonstration project, as on production's "Plant 1".
 *  - demo-flush refuses while real people's records hang from the demonstration;
 *  - demo-separate: a wrong expected count refuses, the dry run changes nothing, --execute moves the requisition, its
 *    applications, the real person placed there, and copies the scope line a demo requisition also uses;
 *  - afterwards a flush would take none of them.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/demo.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
$check = static function (string $label, bool $ok, $detail = ''): void {
    if (! $ok) { fwrite(STDERR, "FAIL $label " . json_encode($detail) . "\n"); exit(1); }
    echo "PASS $label\n";
};
$run = static function (string $script, array $args) : array {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/test-app/install/' . $script) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};
demo_registry_ready();
// This test ends with a real flush: the registry must hold nothing but what it creates.
$check('The demonstration registry is empty before this test', (int) val('SELECT COUNT(*) FROM demo_records') === 0);

q("INSERT INTO clients (name) VALUES ('Separate demo client')");
$demoClient = (int) db()->lastInsertId(); demo_mark('clients', $demoClient);
q("INSERT INTO jobs (client_id, title, status, site_city, site_state) VALUES (?, 'Separate demo plant', 'active', 'Baytown', 'TX')", [$demoClient]);
$demoJob = (int) db()->lastInsertId(); demo_mark('jobs', $demoJob);
q("INSERT INTO clients (name) VALUES ('Separate real client')");
q("INSERT INTO jobs (client_id, title, status, site_city, site_state) VALUES (?, 'Separate real project', 'planning', 'Baytown', 'TX')", [(int) db()->lastInsertId()]);
$realJob = (int) db()->lastInsertId();
q("INSERT INTO job_order_lines (job_id, role_title, quantity, pay_rate, bill_rate) VALUES (?, 'Separate engineer', 10, 50, 70)", [$demoJob]);
$line = (int) db()->lastInsertId();
q("INSERT INTO vacancies (job_id, order_line_id, title, description) VALUES (?, ?, 'Separate demo engineer', 'demo')", [$demoJob, $line]);
$demoVac = (int) db()->lastInsertId();
q("INSERT INTO vacancies (job_id, order_line_id, title, description) VALUES (?, ?, 'Separate REAL engineer', 'real')", [$demoJob, $line]);
$realVac = (int) db()->lastInsertId();
$person = static function (string $name, bool $demo): int {
    q("INSERT INTO candidates (full_name, email, stage) VALUES (?, ?, 'new')", [$name, strtolower(str_replace(' ', '.', $name)) . '@test.invalid']);
    $id = (int) db()->lastInsertId();
    if ($demo) { demo_mark('candidates', $id); }
    return $id;
};
$realIds = [];
foreach (['Separate Real One', 'Separate Real Two', 'Separate Real Three'] as $n) {
    $c = $person($n, false);
    q('INSERT INTO applications (candidate_id, vacancy_id) VALUES (?, ?)', [$c, $realVac]);
    $realIds[] = $c;
}
$demoPerson = $person('Separate Demo Person', true);
q('INSERT INTO applications (candidate_id, vacancy_id) VALUES (?, ?)', [$demoPerson, $demoVac]);
// As production's placements #1013/#1014: the real placement names the scope line a demo requisition shares.
q("INSERT INTO placements (candidate_id, job_id, vacancy_id, order_line_id, status, start_date, pay_rate, bill_rate) VALUES (?, ?, ?, ?, 'offered', '2026-11-02', 50, 70)", [$realIds[0], $demoJob, $realVac, $line]);
$realPlacement = (int) db()->lastInsertId();
// As production's "Owner Number 1": a demonstration account used to work on real applicants.
q("INSERT INTO users (name, email, password_hash, role, must_change_pw) VALUES ('Separate Owner Demo', 'separate-owner@test.invalid', 'x', 'admin', 0)");
$demoUser = (int) db()->lastInsertId(); demo_mark('users', $demoUser);
q("INSERT INTO candidate_events (candidate_id, user_id, event_type, detail) VALUES (?, ?, 'note', 'about a real person')", [$realIds[1], $demoUser]);
$realEvent = (int) db()->lastInsertId();
q("INSERT INTO candidate_calls (candidate_id, user_id, outcome, note) VALUES (?, ?, 'reached', 'real call')", [$realIds[1], $demoUser]);
$realCall = (int) db()->lastInsertId();
q("INSERT INTO candidate_events (candidate_id, user_id, event_type, detail) VALUES (?, ?, 'note', 'about the demo person')", [$demoPerson, $demoUser]);
$demoEvent = (int) db()->lastInsertId();
q("INSERT INTO notifications (user_id, message, target) VALUES (?, 'demo notice', '/')", [$demoUser]);
$demoNotice = (int) db()->lastInsertId();

[$code, $out] = $run('demo-flush.php', []);
$check('demo-flush refuses while real people hang from the demonstration', $code === 1 && str_contains($out, 'REFUSED') && str_contains($out, 'applications'), $out);

[$code, $out] = $run('demo-separate.php', ['--from=' . $demoJob, '--to=' . $realJob, '--vacancies=' . $realVac, '--expect-applications=4']);
$check('A wrong expected count is refused, nothing changed', $code === 1 && (int) val('SELECT job_id FROM vacancies WHERE id = ?', [$realVac]) === $demoJob, $out);
[$code, $out] = $run('demo-separate.php', ['--from=' . $demoJob, '--to=' . $realJob, '--vacancies=' . $realVac, '--expect-applications=3']);
$lines = (int) val('SELECT COUNT(*) FROM job_order_lines');
$check('The dry run does the move, checks it, and changes nothing', $code === 0 && str_contains($out, 'Dry run: the move was done and checked') && str_contains($out, 'copied (also used by a demo requisition) ' . $line)
    && (int) val('SELECT job_id FROM vacancies WHERE id = ?', [$realVac]) === $demoJob && (int) val('SELECT order_line_id FROM placements WHERE id = ?', [$realPlacement]) === $line, $out);
[$code, $out] = $run('demo-separate.php', ['--to=' . $demoJob, '--from=' . $realJob, '--vacancies=' . $realVac]);
$check('Moving INTO a demonstration project is refused', $code === 2, $out);

[$code, $out] = $run('demo-separate.php', ['--from=' . $demoJob, '--to=' . $realJob, '--vacancies=' . $realVac, '--expect-applications=3', '--execute']);
$vac = row('SELECT job_id, order_line_id FROM vacancies WHERE id = ?', [$realVac]);
$check('Executed: the real requisition is on the real project', $code === 0 && (int) $vac['job_id'] === $realJob, [$out, $vac]);
$check('its scope line is a copy on the real project (the demo requisition keeps the original)', (int) $vac['order_line_id'] !== $line
    && (int) val('SELECT job_id FROM job_order_lines WHERE id = ?', [(int) $vac['order_line_id']]) === $realJob && (int) val('SELECT order_line_id FROM vacancies WHERE id = ?', [$demoVac]) === $line);
$check('the real person placed on the demo project moved with it, onto the copied line', (int) val('SELECT job_id FROM placements WHERE id = ?', [$realPlacement]) === $realJob
    && (int) val('SELECT order_line_id FROM placements WHERE id = ?', [$realPlacement]) === (int) $vac['order_line_id']);
$check('one line copied, none left over from the dry run', (int) val('SELECT COUNT(*) FROM job_order_lines') === $lines + 1);
$plan = demo_plan();
$ids = static fn(string $t): array => array_map(static fn($r) => (int) $r['id'], $plan[$t] ?? []);
$apps = array_map('intval', array_column(rows('SELECT id FROM applications WHERE vacancy_id = ?', [$realVac]), 'id'));
$check('A flush now takes none of the real requisition, its 3 applications or the placement', ! in_array($realVac, $ids('vacancies'), true)
    && ! array_intersect($apps, $ids('applications')) && ! in_array($realPlacement, $ids('placements'), true) && count($apps) === 3);
$check('and still takes the demonstration (its requisition and its person)', in_array($demoVac, $ids('vacancies'), true) && in_array($demoPerson, $ids('candidates'), true));
$check("What a demonstration account wrote about a real person stays out of it; what it wrote about the demonstration goes",
    ! in_array($realEvent, $ids('candidate_events'), true) && ! in_array($realCall, $ids('candidate_calls'), true)
    && in_array($demoEvent, $ids('candidate_events'), true) && in_array($demoNotice, $ids('notifications'), true));
$check('That account is kept, not removed', ! in_array($demoUser, $ids('users'), true) && demo_users_kept($plan) === [$demoUser]);
$check('Nothing of a real person is left in the flush', demo_real_at_risk($plan) === [], demo_real_at_risk($plan));

[$code, $out] = $run('demo-flush.php', []);
$check('The flush dry run names the account it will retire', $code === 0 && str_contains($out, 'kept and retired') && str_contains($out, 'separate-owner@test.invalid'), $out);
[$code, $out] = $run('demo-flush.php', ['--execute']);
$u = row('SELECT name, email, is_active FROM users WHERE id = ?', [$demoUser]);
$check('Flushed: the demonstration is gone', $code === 0 && ! val('SELECT COUNT(*) FROM vacancies WHERE id = ?', [$demoVac]) && ! val('SELECT COUNT(*) FROM candidates WHERE id = ?', [$demoPerson])
    && ! val('SELECT COUNT(*) FROM notifications WHERE id = ?', [$demoNotice]) && ! val('SELECT COUNT(*) FROM demo_records'), $out);
$check('the real requisition, its applications, the placement, the real trail are all there',
    (int) val('SELECT COUNT(*) FROM applications WHERE vacancy_id = ?', [$realVac]) === 3 && val('SELECT COUNT(*) FROM placements WHERE id = ?', [$realPlacement])
    && val('SELECT COUNT(*) FROM candidate_events WHERE id = ?', [$realEvent]) && val('SELECT COUNT(*) FROM candidate_calls WHERE id = ?', [$realCall]));
$check('and the account that wrote it is retired: its name readable, no sign-in, no mail', $u && str_contains($u['name'], 'Separate Owner Demo') && str_contains($u['name'], 'retired')
    && (int) $u['is_active'] === 0 && str_ends_with($u['email'], '@retired.invalid'), $u);
