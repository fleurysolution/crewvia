<?php
/**
 * The P5-M21 test's hands, on the test database only: each command adds one
 * known thing, so the test can see exactly that figure move.
 *   applicant   a new application, now, on the open requisition
 *   arrival     a confirmed placement starting in 3 days, with no bed and no travel
 *   hours       a day of hours submitted by the crew, waiting for approval
 *   dispute     a disputed late arrival
 *   bill        a hotel bill due yesterday, unpaid
 *   mark        the first crew member marked present at today's roll call
 *   overfilled  a requisition for one person with two hired (more hired than openings)
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$fx = json_decode((string) file_get_contents(__DIR__ . '/home.json'), true);
$person = static function (): int {
    q("INSERT INTO candidates(full_name,email,stage) VALUES ('Home extra',CONCAT('hm-x-',?,'@test.invalid'),'new')", [bin2hex(random_bytes(4))]);
    return (int) db()->lastInsertId();
};
switch ($argv[1] ?? '') {
    case 'applicant':
        q("INSERT INTO applications(candidate_id,vacancy_id,stage) VALUES (?,?,'new')", [$person(), (int) $fx['vacancy']]);
        break;
    case 'arrival':
        q("INSERT INTO placements(candidate_id,job_id,status,start_date) VALUES (?,?,'confirmed',DATE_ADD(CURDATE(), INTERVAL 3 DAY))", [$person(), (int) $fx['job']]);
        echo (int) db()->lastInsertId();
        break;
    case 'hours':
        q("INSERT INTO attendance_records(placement_id,work_date,hours,status,submitted_by) VALUES (?,DATE_SUB(CURDATE(), INTERVAL 1 DAY),10,'submitted',?)", [(int) $fx['crew']['w'], (int) $fx['ids']['w']]);
        break;
    case 'dispute':
        q("INSERT INTO attendance_lateness(placement_id,work_date,shift_start,arrived_at,minutes_late,docked_minutes,grace_minutes,round_to_minutes,reason,status,recorded_by)
           VALUES (?,DATE_SUB(CURDATE(), INTERVAL 2 DAY),'07:00:00','07:20:00',20,30,5,15,'Synthetic','disputed',?)", [(int) $fx['crew']['c2'], (int) $fx['ids']['sup']]);
        break;
    case 'bill':
        q("INSERT INTO vendor_invoices(job_id,hotel_id,vendor_name,reference,amount,due_on) VALUES (?,?,'P5-M21 Inn',?,90,DATE_SUB(CURDATE(), INTERVAL 1 DAY))", [(int) $fx['job'], (int) $fx['hotel'], 'HM-' . bin2hex(random_bytes(3))]);
        break;
    case 'overfilled':
        q("INSERT INTO vacancies(job_id,title,description,is_open,openings) VALUES (?,'P5-M21 overfilled','Synthetic.',1,1)", [(int) $fx['job']]);
        $v = (int) db()->lastInsertId();
        foreach ([1, 2] as $_) {
            q("INSERT INTO applications(candidate_id,vacancy_id,stage) VALUES (?,?,'accepted')", [$person(), $v]);
        }
        echo $v;
        break;
    case 'mark':
        q('INSERT INTO assignment_checkins(placement_id,work_date,present,marked_by) VALUES (?,CURDATE(),1,?)', [(int) $fx['crew']['w'], (int) $fx['ids']['sup']]);
        break;
    default:
        fwrite(STDERR, "Unknown command\n");
        exit(2);
}
