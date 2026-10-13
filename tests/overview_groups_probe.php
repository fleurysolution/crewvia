<?php
/**
 * The P5-M16 test's hands, on the test database only: each command adds
 * one known thing, so the test can see exactly that figure move.
 *   person <status>   a placement on the running project (on_site, confirmed)
 *   applicant <stage> an application on the open requisition, made now
 *   dispute           a disputed late arrival
 *   folio             an open hotel folio
 *   overdue           a hotel bill due yesterday, unpaid
 *   incident          an open safety incident
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$fx = json_decode((string) file_get_contents(__DIR__ . '/overview_groups.json'), true);
$job = (int) $fx['running'];
$candidate = static function (): int {
    q("INSERT INTO candidates(full_name,email,stage) VALUES ('Overview person',CONCAT('ov-',?,'@test.invalid'),'placed')", [bin2hex(random_bytes(4))]);
    return (int) db()->lastInsertId();
};
$placement = static function (string $status) use ($job, $candidate): int {
    q('INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate,guarantee_hours) VALUES (?,?,?,?,30,50,40)', [$candidate(), $job, $status, date('Y-m-d', strtotime('-10 days'))]);
    return (int) db()->lastInsertId();
};
switch ($argv[1] ?? '') {
    case 'person':
        $placement((string) $argv[2]);
        break;
    case 'applicant':
        q('INSERT INTO applications(candidate_id,vacancy_id,stage) VALUES (?,?,?)', [$candidate(), (int) $fx['vacancy'], (string) $argv[2]]);
        break;
    case 'dispute':
        q("INSERT INTO attendance_lateness(placement_id,work_date,shift_start,arrived_at,minutes_late,docked_minutes,grace_minutes,round_to_minutes,reason,status,recorded_by)
           VALUES (?, DATE_SUB(CURDATE(), INTERVAL 1 DAY), '07:00:00','07:20:00',20,30,5,15,'Synthetic','disputed',?)", [$placement('on_site'), (int) $fx['admin']]);
        break;
    case 'folio':
        q("INSERT INTO hotel_folios(job_id,hotel_id,week_ending,reference,created_by) VALUES (?,?,?,?,?)", [$job, (int) $fx['hotel'], week_ending(date('Y-m-d', strtotime('-' . random_int(30, 900) . ' days'))), 'OV-' . bin2hex(random_bytes(3)), (int) $fx['admin']]);
        break;
    case 'overdue':
        q("INSERT INTO vendor_invoices(job_id,hotel_id,vendor_name,reference,amount,due_on) VALUES (?,?,'P5-M16 Inn',?,100,DATE_SUB(CURDATE(), INTERVAL 1 DAY))", [$job, (int) $fx['hotel'], 'OV-' . bin2hex(random_bytes(3))]);
        break;
    case 'incident':
        q("INSERT INTO safety_incidents(job_id,reported_by,description,severity) VALUES (?,?,'Synthetic incident','low')", [$job, (int) $fx['admin']]);
        break;
    default:
        fwrite(STDERR, "Unknown command\n");
        exit(2);
}
