<?php
/**
 * How many of a scope line are placed, on the isolated test database only.
 * A crew placed straight onto the line (a roster load names the line on the
 * placement and has no application) counts; an older placement reached
 * through a requisition still counts; an offer or another line does not.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/scope.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n"); exit(2); }

$results = [];
function check(string $label, bool $condition, string $detail = ''): void
{
    global $results;
    if (! $condition) { fwrite(STDERR, 'FAIL ' . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL); exit(1); }
    $results[] = $label;
    echo 'PASS ' . $label . PHP_EOL;
}

q("INSERT INTO clients(name) VALUES ('Scope filled client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'Scope filled plant','active')", [$client]);
$job = (int) db()->lastInsertId();
q("INSERT INTO job_order_lines(job_id,role_title,discipline,quantity,pay_rate,bill_rate,sort_order) VALUES (?,'Scope welder','mechanical',5,40,60,1)", [$job]);
$line = (int) db()->lastInsertId();
q("INSERT INTO job_order_lines(job_id,role_title,discipline,quantity,pay_rate,bill_rate,sort_order) VALUES (?,'Scope painter','mechanical',5,30,45,2)", [$job]);
$other = (int) db()->lastInsertId();
q("INSERT INTO vacancies(job_id,order_line_id,title,description,is_open,openings) VALUES (?,?,'Scope welder','Synthetic.',1,5)", [$job, $line]);
$vacancy = (int) db()->lastInsertId();

$person = static function (string $k): int {
    q("INSERT INTO candidates(full_name,email,stage) VALUES (?,?,'placed')", ['Scope ' . $k, 'sf-' . $k . '@test.invalid']);
    return (int) db()->lastInsertId();
};
foreach (['a', 'b'] as $k) {
    q("INSERT INTO placements(candidate_id,job_id,order_line_id,status) VALUES (?,?,?,'on_site')", [$person($k), $job, $line]);
}
check('A crew placed straight onto a line counts on that line', scope_line_filled($line) === 2, (string) scope_line_filled($line));

$old = $person('old');
q("INSERT INTO applications(candidate_id,vacancy_id,stage) VALUES (?,?,'accepted')", [$old, $vacancy]);
q("INSERT INTO applications(candidate_id,vacancy_id,stage) VALUES (?,?,'rejected')", [$old, $vacancy]);
q("INSERT INTO placements(candidate_id,job_id,status) VALUES (?,?,'confirmed')", [$old, $job]);
check('A placement through the line\'s requisition still counts, once', scope_line_filled($line) === 3, (string) scope_line_filled($line));

q("INSERT INTO placements(candidate_id,job_id,order_line_id,status) VALUES (?,?,?,'offered')", [$person('offer'), $job, $line]);
q("INSERT INTO placements(candidate_id,job_id,order_line_id,status) VALUES (?,?,?,'on_site')", [$person('paint'), $job, $other]);
check('An offer, or a person on another line, does not count', scope_line_filled($line) === 3 && scope_line_filled($other) === 1);

file_put_contents(__DIR__ . '/sf-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
