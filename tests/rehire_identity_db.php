<?php
/**
 * P5-M78 on the test database: a blocked person who comes back with another email and phone is held by the same
 * Social Security number, or the same name and date of birth; the same name alone is shown to check, not held.
 * The SSN is test data (212-55-1234 style numbers), kept only as its keyed fingerprint.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/rehire.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
$check = static function (string $label, bool $ok, $detail = ''): void {
    if (! $ok) { fwrite(STDERR, "FAIL $label " . json_encode($detail) . "\n"); exit(1); }
    echo "PASS $label\n";
};
$person = static function (string $name, string $email, string $phone): int {
    q("INSERT INTO candidates (full_name, email, phone, stage) VALUES (?,?,?, 'new')", [$name, $email, $phone]);
    return (int) db()->lastInsertId();
};

$a = $person('Jordan Q. Blocked', 'jordan-old@test.invalid', '555-010-1001');
q("INSERT INTO employee_profiles (candidate_id, rehire_status, exclusion_reason, excluded_at) VALUES (?, 'do_not_use', 'Walked off the job on day two.', NOW())", [$a]);
identity_note($a, ['ssn' => '212551234', 'birth_date' => '1990-04-02', 'first_name' => 'Jordan', 'last_name' => 'Blocked']);
$row = row('SELECT * FROM candidate_identity WHERE candidate_id = ?', [$a]);
$check('The SSN is kept as a fingerprint, never the number', $row && strlen((string) $row['ssn_hash']) === 64 && ! str_contains((string) $row['ssn_hash'], '212551234') && $row['name_key'] === 'jordan blocked', $row);

$b = $person('J Blocked', 'new-address@test.invalid', '555-020-2002');
$check('Another email and phone, nothing known yet: not held', rehire_block_for($b) === null);
identity_note($b, ['ssn' => '212-55-1234', 'birth_date' => '', 'first_name' => 'J', 'last_name' => 'Blocked']);
$hold = rehire_block_for($b);
$check('The same SSN on the W-4: held, with the reason it was blocked', $hold && $hold['how'] === 'ssn' && $hold['record'] === $a && $hold['reason'] === 'Walked off the job on day two.', $hold);
$check('The refusal says how it matched and why', str_contains((string) rehire_refusal($b), 'same Social Security number') && str_contains((string) rehire_refusal($b), 'Walked off the job'), rehire_refusal($b));

$c = $person('Jordan Blocked', 'third@test.invalid', '555-030-3003');
identity_note($c, ['ssn' => '212559999', 'birth_date' => '1990-04-02', 'first_name' => 'Jordán', 'last_name' => 'Blocked']);
$hold = rehire_block_for($c);
$check('The same name and date of birth on the I-9, another SSN: held', $hold && $hold['how'] === 'name_dob' && $hold['record'] === $a, $hold);

$d = $person('Jordan Blocked', 'fourth@test.invalid', '555-040-4004');
$check('The same name alone is not held', rehire_block_for($d) === null);
$m = rehire_name_matches($d);
$check('...but it is shown to the recruiter to check, with the reason', count($m) === 1 && $m[0]['record'] === $a && $m[0]['reason'] === 'Walked off the job on day two.', $m);

$flag = val('SELECT ' . rehire_block_sql('c') . ' FROM candidates c WHERE c.id = ?', [$b]);
$check('The lists mark the same-SSN record too', $flag === 'do_not_use', $flag);
$e = $person('Somebody Else', 'else@test.invalid', '555-050-5005');
identity_note($e, ['ssn' => '212557777', 'birth_date' => '1985-01-01', 'first_name' => 'Somebody', 'last_name' => 'Else']);
$check('Somebody else is not held', rehire_block_for($e) === null && val('SELECT ' . rehire_block_sql('c') . ' FROM candidates c WHERE c.id = ?', [$e]) === null);
