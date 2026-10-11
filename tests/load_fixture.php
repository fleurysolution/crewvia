<?php
/**
 * P4-M07 fixture for load_http.py, on the isolated test database only:
 * 40 synthetic desk users (each signs in once, in its own session) and 12
 * payroll users, and one bank change waiting to be checked, for the race
 * where many people decide it at the same moment.
 *   php load_fixture.php            creates them, writes load.json
 *   php load_fixture.php read       what the races left behind
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/gmail.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

if (($argv[1] ?? '') === 'questions') {
    // The server's count of statements so far: read before and after one page view.
    echo (int) row("SHOW GLOBAL STATUS LIKE 'Questions'")['Value'];
    exit(0);
}

if (($argv[1] ?? '') === 'read') {
    $fx = json_decode((string) file_get_contents(__DIR__ . '/load.json'), true);
    echo json_encode([
        'bank_request' => row('SELECT status, reviewed_by FROM worker_bank_change_requests WHERE id = ?', [$fx['bank_request']]),
        'bank_rows' => (int) val('SELECT COUNT(*) FROM worker_bank_details WHERE candidate_id = ?', [$fx['candidate']]),
        'bank_decisions_logged' => (int) val("SELECT COUNT(*) FROM activity WHERE action = 'decided proposed bank details' AND entity_id = ?", [$fx['bank_request']]),
        'audit_reads' => (int) val("SELECT COUNT(*) FROM activity WHERE action = 'read the audit log' AND user_id IN (" . implode(',', array_map('intval', $fx['desk'])) . ')'),
        'login_attempts' => (int) val('SELECT attempts FROM auth_rate_limits WHERE bucket = ?', [hash('sha256', 'login-email|' . $fx['target_email'])]),
        'failed_logged' => (int) val("SELECT COUNT(*) FROM activity WHERE action = 'failed sign-in' AND detail LIKE ?", ['%' . $fx['target_email'] . '%']),
        'throttled_logged' => (int) val("SELECT COUNT(*) FROM activity WHERE action = 'sign-in throttled' AND detail LIKE ?", ['%' . $fx['target_email'] . '%']),
    ]);
    exit(0);
}

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$desk = [];
$pay = [];
for ($i = 1; $i <= 40; $i++) {
    q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,'admin',0)", ['Load desk ' . $i, sprintf('load-desk-%02d@test.invalid', $i), $password]);
    $desk[] = (int) db()->lastInsertId();
}
for ($i = 1; $i <= 12; $i++) {
    q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,'payroll',0)", ['Load payroll ' . $i, sprintf('load-pay-%02d@test.invalid', $i), $password]);
    $pay[] = (int) db()->lastInsertId();
}
q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES ('Load target','load-target@test.invalid',?,'recruiter',0)", [$password]);
q("INSERT INTO candidates(full_name,email,stage) VALUES ('Load banked worker','load-banked@test.invalid','placed')");
$cid = (int) db()->lastInsertId();
q("INSERT INTO worker_bank_change_requests(candidate_id,encrypted_details,last_four,bank_label,submitted_by) VALUES (?,?,'4321','Load bank',?)",
  [$cid, token_encrypt(['account_number' => '987654324321', 'routing_number' => '021000021', 'bank_name' => 'Load bank', 'account_holder' => 'Load banked worker']), $desk[0]]);
$req = (int) db()->lastInsertId();
q('DELETE FROM auth_rate_limits');
file_put_contents(__DIR__ . '/load.json', json_encode(['desk' => $desk, 'pay' => $pay, 'candidate' => $cid, 'bank_request' => $req, 'target_email' => 'load-target@test.invalid']));
echo 'PASS Load fixture: 40 desk users, 12 payroll users, one bank change waiting' . PHP_EOL;
