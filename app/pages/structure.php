<?php
/**
 * Project structure: how the site is organised, and when it is paid.
 *
 * Departments were a list of names nothing referred to - you could add one
 * and no crew, shift or report ever mentioned it again. A name that means
 * nothing is worse than no name, because somebody will assume it is wired up.
 *
 * The screen now shows what the site is actually made of: how the crew falls
 * across trades and shifts, read from their assignments rather than typed in
 * a second time. Departments stay, and one can be removed when it turns out
 * not to be a department.
 */

require_role('admin');

$job   = current_job();
$jobId = (int) ($job['id'] ?? 0);

if ($jobId) {
    q('INSERT IGNORE INTO project_operating_settings(job_id) VALUES (?)', [$jobId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? 'calendar');

    if (! $jobId) {
        flash(t('Choose a project first.'), 'err');
        redirect('/structure');
    }

    // The roll call. Marking somebody twice in a day corrects the mark
    // rather than recording them twice, which is what the unique key on
    // (placement, date) is for.
    if ($do === 'checkin') {
        $placement = row("SELECT p.id, c.full_name FROM placements p
                          JOIN candidates c ON c.id = p.candidate_id
                          WHERE p.id = ? AND p.job_id = ?
                            AND p.status IN ('confirmed','travelling','on_site')",
                         [(int) ($_POST['placement_id'] ?? 0), $jobId]);

        if (! $placement) {
            flash(t('That person is not on this project.'), 'err');
            redirect('/structure');
        }

        $present = ($_POST['present'] ?? '1') === '1' ? 1 : 0;

        q('INSERT INTO assignment_checkins (placement_id, work_date, present, marked_by)
           VALUES (?, CURDATE(), ?, ?)
           ON DUPLICATE KEY UPDATE present = VALUES(present),
                                   marked_by = VALUES(marked_by),
                                   marked_at = NOW()',
          [(int) $placement['id'], $present, uid()]);

        // Somebody marked present is on site, which is a fact the roster
        // should carry too rather than being kept only on this screen.
        if ($present) {
            q("UPDATE placements SET status = 'on_site'
               WHERE id = ? AND status IN ('confirmed','travelling')",
              [(int) $placement['id']]);
        }

        log_activity($present ? 'marked present on site' : 'marked absent from site',
                     'placement', (int) $placement['id'], (string) $placement['full_name']);

        flash($present
            ? t(':name is on site today.', ['name' => $placement['full_name']])
            : t(':name is marked absent today.', ['name' => $placement['full_name']]));

        redirect('/structure');
    }

    if ($do === 'department') {
        $name = trim((string) ($_POST['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 190) {
            flash(t('A department needs a name.'), 'err');
            redirect('/structure');
        }

        q('INSERT IGNORE INTO project_departments(job_id, name) VALUES (?,?)', [$jobId, $name]);
        log_activity('added department', 'job', $jobId, $name);
        flash(t(':name added.', ['name' => $name]));
        redirect('/structure');
    }

    if ($do === 'remove_department') {
        $department = row('SELECT * FROM project_departments WHERE id = ? AND job_id = ?',
                          [(int) ($_POST['department_id'] ?? 0), $jobId]);

        if ($department) {
            q('DELETE FROM project_departments WHERE id = ?', [$department['id']]);
            log_activity('removed department', 'job', $jobId, (string) $department['name']);
            flash(t(':name removed.', ['name' => $department['name']]));
        }

        redirect('/structure');
    }

    $timezone = (string) ($_POST['timezone'] ?? '');
    $day      = (int) ($_POST['pay_day'] ?? -1);

    if (! in_array($timezone, DateTimeZone::listIdentifiers(), true) || $day < 0 || $day > 6) {
        refuse(422, t('Invalid operating settings.'));
    }

    q('UPDATE project_operating_settings SET timezone = ?, pay_day = ? WHERE job_id = ?',
      [$timezone, $day, $jobId]);

    log_activity('updated the operating calendar', 'job', $jobId, $timezone);
    flash(t('Calendar saved.'));
    redirect('/structure');
}

$departments = rows('SELECT * FROM project_departments WHERE job_id = ? ORDER BY name', [$jobId]);
$policy      = row('SELECT * FROM project_operating_settings WHERE job_id = ?', [$jobId]);

// What the site is actually made of, read from the assignments rather than
// typed a second time.
$trades = $jobId ? rows("SELECT COALESCE(NULLIF(TRIM(d.trade), ''), ?) AS trade,
                                COUNT(*) AS crew,
                                SUM(p.status = 'on_site') AS on_site
                         FROM placements p
                         LEFT JOIN assignment_details d ON d.placement_id = p.id
                         WHERE p.job_id = ? AND p.status NOT IN ('cancelled','completed')
                         GROUP BY trade ORDER BY crew DESC, trade",
                        [t('Not assigned a trade'), $jobId]) : [];

$shifts = $jobId ? rows("SELECT COALESCE(NULLIF(TRIM(d.shift_label), ''), ?) AS shift,
                                COUNT(*) AS crew
                         FROM placements p
                         LEFT JOIN assignment_details d ON d.placement_id = p.id
                         WHERE p.job_id = ? AND p.status NOT IN ('cancelled','completed')
                         GROUP BY shift ORDER BY crew DESC, shift",
                        [t('No shift set'), $jobId]) : [];

$crewTotal = $jobId ? (int) val("SELECT COUNT(*) FROM placements
                                 WHERE job_id = ? AND status NOT IN ('cancelled','completed')",
                                [$jobId]) : 0;

$pageTitle = t('Project structure') . ' · ' . $config['app_name'];

// Who is standing on the site today, and who has not been marked either
// way. The question is asked every morning, so the screen answers it
// without anybody having to go and look for another report.
$rollCall = $jobId ? rows(
    "SELECT p.id, c.full_name, d.trade, d.shift_label,
            u.name AS supervisor, k.present, k.marked_at,
            m.name AS marked_by
     FROM placements p
     JOIN candidates c ON c.id = p.candidate_id
     LEFT JOIN assignment_details d ON d.placement_id = p.id
     LEFT JOIN users u ON u.id = d.supervisor_id
     LEFT JOIN assignment_checkins k ON k.placement_id = p.id AND k.work_date = CURDATE()
     LEFT JOIN users m ON m.id = k.marked_by
     WHERE p.job_id = ? AND p.status IN ('confirmed','travelling','on_site')
     ORDER BY k.present IS NULL DESC, c.full_name", [$jobId]) : [];

$onSiteToday = count(array_filter($rollCall, static fn ($r) => (int) $r['present'] === 1));

render('structure', compact('job', 'departments', 'policy', 'trades', 'shifts', 'crewTotal', 'rollCall', 'onSiteToday'));
