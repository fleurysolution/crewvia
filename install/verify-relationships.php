<?php
/**
 * Read-only check of the person -> assignment -> week chain.
 *
 * placements has no foreign keys to candidates or jobs, so nothing stops
 * an orphan. Before a key is ever proposed for an existing table this is
 * run on the real database: a key cannot be added over a single orphan.
 *
 * Writes nothing. Exit 0 when every count is zero, 1 otherwise.
 *
 *   php install/verify-relationships.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit('CLI only');

require_once __DIR__ . '/../app/bootstrap.php';

$column = static fn(string $table, string $name): bool => (bool) val(
    'SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $name]);

$checks = [
    'placements without a person'
        => 'SELECT COUNT(*) FROM placements p LEFT JOIN candidates c ON c.id = p.candidate_id WHERE c.id IS NULL',
    'placements without a project'
        => 'SELECT COUNT(*) FROM placements p LEFT JOIN jobs j ON j.id = p.job_id WHERE j.id IS NULL',
    'timesheets without a placement'
        => 'SELECT COUNT(*) FROM timesheets t LEFT JOIN placements p ON p.id = t.placement_id WHERE p.id IS NULL',
    'attendance days without a placement'
        => 'SELECT COUNT(*) FROM attendance_records a LEFT JOIN placements p ON p.id = a.placement_id WHERE p.id IS NULL',
    'worker logins pointing at nobody'
        => 'SELECT COUNT(*) FROM worker_accounts w LEFT JOIN candidates c ON c.id = w.candidate_id WHERE c.id IS NULL',
    'two active placements for one person on one project'
        => "SELECT COUNT(*) FROM (SELECT candidate_id, job_id FROM placements
                                 WHERE status NOT IN ('completed','cancelled')
                                 GROUP BY candidate_id, job_id HAVING COUNT(*) > 1) x",
];

if ($column('placements', 'vacancy_id')) {
    $checks['placements linked to a requisition on another project']
        = 'SELECT COUNT(*) FROM placements p JOIN vacancies v ON v.id = p.vacancy_id WHERE v.job_id <> p.job_id';
    $checks['placements linked to a requisition that no longer exists']
        = 'SELECT COUNT(*) FROM placements p LEFT JOIN vacancies v ON v.id = p.vacancy_id
           WHERE p.vacancy_id IS NOT NULL AND v.id IS NULL';
    $checks['placements whose line disagrees with their requisition']
        = 'SELECT COUNT(*) FROM placements p JOIN vacancies v ON v.id = p.vacancy_id
           WHERE NOT (p.order_line_id <=> v.order_line_id)';
}

if ($column('employee_profiles', 'flsa_status')) {
    $checks['profiles whose type disagrees with their latest classification']
        = "SELECT COUNT(*) FROM employee_profiles ep
           JOIN employee_classifications k ON k.id = (
               SELECT k2.id FROM employee_classifications k2 WHERE k2.candidate_id = ep.candidate_id
               ORDER BY COALESCE(k2.effective_from, '1000-01-01') DESC, k2.id DESC LIMIT 1)
           WHERE k.employment_type <> ep.employment_type OR k.flsa_status <> ep.flsa_status";
}

$bad = 0;

foreach ($checks as $label => $sql) {
    $count = (int) val($sql);
    $bad += $count;
    printf("%-62s %d\n", $label, $count);
}

echo $bad === 0 ? "Relationships: clean.\n" : "Relationships: {$bad} problem row(s). Nothing was changed.\n";

exit($bad === 0 ? 0 : 1);
