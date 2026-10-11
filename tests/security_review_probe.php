<?php
/**
 * The P4-M06 test's eyes and hands, on the test database only.
 *   read                         audit entries, consents, notices, bank rows, settings
 *   setting <key> <value>        sets a platform setting
 *   weak-hash <user id>          stores the test password under a cheap bcrypt cost
 *   cost <user id>               prints the cost of the stored hash
 *   adp <candidate id> <value>   writes an ADP id directly, as an old record might hold
 *   tamper-log                   tries to change and to delete an audit entry
 *   crew add|remove <candidate> <job> <supervisor>
 *   reset-intake                 forgets the public-form count for 127.0.0.1
 *   candidate <id>               prints the candidate row
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$mode = (string) ($argv[1] ?? 'read');
switch ($mode) {
    case 'setting':
        q('INSERT INTO platform_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)', [(string) $argv[2], (string) $argv[3]]);
        exit(0);
    case 'weak-hash':
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash('TestPassword123!', PASSWORD_BCRYPT, ['cost' => 4]), (int) $argv[2]]);
        exit(0);
    case 'cost':
        echo json_encode(password_get_info((string) val('SELECT password_hash FROM users WHERE id = ?', [(int) $argv[2]]))['options']['cost'] ?? null);
        exit(0);
    case 'adp':
        q('UPDATE employee_profiles SET adp_employee_id = ? WHERE candidate_id = ?', [(string) $argv[3], (int) $argv[2]]);
        exit(0);
    case 'tamper-log':
        $id = (int) val('SELECT MAX(id) FROM activity');
        $out = [];
        foreach (['update' => 'UPDATE activity SET detail = ? WHERE id = ?', 'delete' => 'DELETE FROM activity WHERE id = ? AND ? IS NOT NULL'] as $k => $sql) {
            try {
                q($sql, $k === 'update' ? ['changed', $id] : [$id, 1]);
                $out[$k] = 'allowed';
            } catch (Throwable $e) {
                $out[$k] = $e->getMessage();
            }
        }
        echo json_encode($out);
        exit(0);
    case 'crew':
        // crew add|remove <candidate> <job> <supervisor>: a crew placement supervised on that job, or taken off it.
        [, , $op, $cand, $job, $sup] = $argv;
        if ($op === 'add') {
            q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site',CURDATE(),30,50)", [(int) $cand, (int) $job]);
            q('INSERT INTO assignment_details(placement_id,trade,supervisor_id) VALUES (?,?,?)', [(int) db()->lastInsertId(), 'Electrician', (int) $sup]);
        } else {
            q('DELETE d FROM assignment_details d JOIN placements p ON p.id = d.placement_id WHERE p.candidate_id = ? AND p.job_id = ? AND d.supervisor_id = ?', [(int) $cand, (int) $job, (int) $sup]);
        }
        exit(0);
    case 'reset-intake':
        q('DELETE FROM auth_rate_limits WHERE bucket = ?', [hash('sha256', 'public-application|127.0.0.1')]);
        exit(0);
    case 'candidate':
        echo json_encode(row('SELECT id, full_name, email, phone, resume_text, notes FROM candidates WHERE id = ?', [(int) $argv[2]]));
        exit(0);
}

echo json_encode([
    'activity' => rows('SELECT id, user_id, action, entity, entity_id, detail, ip FROM activity ORDER BY id DESC LIMIT 300'),
    'consents' => rows('SELECT candidate_id, purpose, source, notice_id FROM consent_records ORDER BY id'),
    'notices'  => rows('SELECT id, sha256 FROM privacy_notices ORDER BY id'),
    'bank'     => rows('SELECT candidate_id, status, submitted_by, reviewed_by, last_four FROM worker_bank_details ORDER BY candidate_id'),
    'bank_requests' => rows('SELECT id, candidate_id, status, submitted_by, reviewed_by FROM worker_bank_change_requests ORDER BY id'),
    'resumes'  => rows('SELECT candidate_id FROM candidate_resumes ORDER BY id'),
    'two_step' => val("SELECT setting_value FROM platform_settings WHERE setting_key = 'require_two_step_privileged'"),
    'incidents' => rows('SELECT job_id, reported_by, description FROM safety_incidents ORDER BY id'),
]);
