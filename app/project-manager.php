<?php
/**
 * P5-M59: what a project manager oversees.
 *
 * A project manager (Job setup, "Project manager") was only told of new client
 * orders and requisitions. Signed in on the Supervisor desk, they saw an empty
 * "My team" - they carry no crew themselves - and the project tasks, board and
 * workload of no project, those being opened to the supervisors of a crew. The
 * projects they manage now count as theirs there (supervised_job_ids), and My
 * team opens on them: who is on site, who is starting, who has no supervisor,
 * the supervisors and their crews, the requisitions being filled.
 */

declare(strict_types=1);

/** The projects this person manages, each with its people and its recruiting, in figures. */
function pm_projects(int $userId): array
{
    $projects = rows("SELECT id, title, site_city, site_state, status FROM jobs WHERE project_manager_id = ? ORDER BY title", [$userId]);
    foreach ($projects as &$p) {
        $id = (int) $p['id'];
        $p['people'] = row("SELECT COALESCE(SUM(p.status = 'on_site'), 0) AS on_site,
                                   COALESCE(SUM(p.status IN ('offered','confirmed','travelling')), 0) AS not_started,
                                   COALESCE(SUM(p.status IN ('offered','confirmed','travelling','on_site') AND d.supervisor_id IS NULL), 0) AS unassigned
                            FROM placements p LEFT JOIN assignment_details d ON d.placement_id = p.id
                            WHERE p.job_id = ?", [$id]) ?: ['on_site' => 0, 'not_started' => 0, 'unassigned' => 0];
        $p['supervisors'] = rows("SELECT u.name, COUNT(*) AS crew, COALESCE(SUM(p.status = 'on_site'), 0) AS on_site
                                  FROM placements p JOIN assignment_details d ON d.placement_id = p.id JOIN users u ON u.id = d.supervisor_id
                                  WHERE p.job_id = ? AND p.status IN ('offered','confirmed','travelling','on_site')
                                  GROUP BY u.id, u.name ORDER BY u.name", [$id]);
        $p['recruiting'] = row("SELECT COUNT(DISTINCT v.id) AS requisitions, COALESCE(SUM(v.openings), 0) AS openings,
                                       (SELECT COUNT(*) FROM applications a JOIN vacancies v2 ON v2.id = a.vacancy_id
                                        WHERE v2.job_id = ? AND v2.is_open = 1 AND a.stage IN ('new','screening','interview','offered')) AS applicants
                                FROM vacancies v WHERE v.job_id = ? AND v.is_open = 1", [$id, $id]) ?: ['requisitions' => 0, 'openings' => 0, 'applicants' => 0];
    }
    unset($p);

    return $projects;
}
