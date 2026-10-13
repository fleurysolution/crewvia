<?php
/**
 * P5-M16 (T25) fixture for overview_groups_http.py, on the isolated test
 * database only. No table changes in this module, so no rollback here.
 * An administrator; a running project short of its headcount with an open
 * requisition; a finished project.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n"); exit(2); }

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES ('Overview admin','ov-admin@test.invalid',?,'admin',0)", [$password]);
$admin = (int) db()->lastInsertId();
q("INSERT INTO clients(name) VALUES ('Overview client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status,lifecycle_stage,headcount_target,pay_rate,bill_rate) VALUES (?,'P5-M16 running turnaround','active','on_site',3,30,50)", [$client]);
$running = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status,lifecycle_stage,headcount_target) VALUES (?,'P5-M16 finished outage','closed','closed',2)", [$client]);
$finished = (int) db()->lastInsertId();
q("INSERT INTO vacancies(job_id,title,description,is_open,openings) VALUES (?,'P5-M16 operator','Synthetic.',1,3)", [$running]);
$vacancy = (int) db()->lastInsertId();
q("INSERT INTO hotels(name,nightly_rate) VALUES ('P5-M16 Inn',90)");
$hotel = (int) db()->lastInsertId();

file_put_contents(__DIR__ . '/overview_groups.json', json_encode(['admin' => $admin, 'client' => $client, 'running' => $running, 'finished' => $finished, 'vacancy' => $vacancy, 'hotel' => $hotel]));
file_put_contents(__DIR__ . '/og-db-results.json', json_encode(['Fixture for the overview groups'], JSON_PRETTY_PRINT));
echo 'PASS Fixture for the overview groups' . PHP_EOL;
