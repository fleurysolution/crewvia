<?php
/**
 * The report builder (P4-M03): saved reports, their preview and export,
 * schedules, the deliveries waiting for this person, and, for
 * administrators, the export policy and running what is due.
 */

require_once __DIR__ . '/../report-builder.php';

require_role('recruiter', 'payroll');

$catalog = reports_catalog();
$readable = array_filter($catalog, fn($r) => can(...$r['roles']));

// ── a delivered file ─────────────────────────────────────────────────────
if (isset($_GET['delivery'])) {
    [$d, $status, $why] = rb_delivery_for_download((int) $_GET['delivery']);
    if (! $d) {
        refuse($status, $why);
    }
    q('UPDATE report_delivery_recipients SET downloaded_at = COALESCE(downloaded_at, NOW()) WHERE delivery_id = ? AND user_id = ?', [(int) $d['id'], uid()]);
    rb_log_export('delivery', (string) $d['source'], (int) $d['definition_id'], (int) $d['id'], ['period' => $d['period_label']], (int) $d['row_count'], (string) $d['sha256']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="crewvia-report-' . (int) $d['definition_id'] . '-delivery-' . (int) $d['id'] . '.csv"');
    header('X-Content-SHA256: ' . $d['sha256']);
    header('Cache-Control: no-store');
    echo $d['csv'];
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['definition_id'] ?? 0);

    if ($do === 'policy') {
        require_role('admin');
        $source = (string) ($_POST['source'] ?? '');
        if (! isset($catalog[$source])) {
            refuse(422, t('Choose a report.'));
        }
        $roles = array_values(array_intersect($catalog[$source]['roles'], array_map('strval', (array) ($_POST['roles'] ?? []))));
        if (($_POST['readers'] ?? '') === '1') {
            q('DELETE FROM report_export_policy WHERE source = ?', [$source]);
        } else {
            q('INSERT INTO report_export_policy (source, roles, updated_by, updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE roles = VALUES(roles), updated_by = VALUES(updated_by), updated_at = NOW()',
              [$source, implode(',', $roles), uid()]);
        }
        log_activity('changed who may export a report', 'report', 0, $source . ': ' . (($_POST['readers'] ?? '') === '1' ? 'every reader' : (implode(',', $roles) ?: 'administrators only')));
        flash(t('Export policy saved.'));
        redirect('/report-builder#policy');
    }

    if ($do === 'run_due') {
        require_role('admin');
        $done = rb_run_due(date('Y-m-d H:i:s'));
        log_activity('ran the due report schedules', 'report', 0, implode(' ', $done));
        flash($done ? implode(' ', $done) : t('Nothing was due.'));
        redirect('/report-builder');
    }

    if ($do === 'save') {
        [$in, $why] = rb_input($_POST);
        if ($why !== null) {
            refuse(422, $why);
        }
        $args = [$in['name'], $in['source'], json_encode($in['columns']), json_encode($in['filters']), json_encode($in['conditions']),
                 $in['sort_column'], $in['sort_dir'], $in['row_limit'], $in['shared']];
        if ($id > 0) {
            $d = rb_definition($id);
            if (! $d || ! rb_may_change($d)) {
                refuse(403, t('Only its owner or an administrator changes a saved report.'));
            }
            if ($in['source'] !== $d['source'] && val('SELECT COUNT(*) FROM report_schedules WHERE definition_id = ? AND active = 1', [$id])) {
                refuse(422, t('This report is scheduled: stop its schedules before changing which report it is built on.'));
            }
            q('UPDATE report_definitions SET name = ?, source = ?, columns_json = ?, filters_json = ?, conditions_json = ?, sort_column = ?, sort_dir = ?, row_limit = ?, shared = ?, updated_at = NOW() WHERE id = ?', [...$args, $id]);
        } else {
            q('INSERT INTO report_definitions (name, source, columns_json, filters_json, conditions_json, sort_column, sort_dir, row_limit, shared, owner_id) VALUES (?,?,?,?,?,?,?,?,?,?)', [...$args, uid()]);
            $id = (int) db()->lastInsertId();
        }
        log_activity('saved a report', 'report', $id, $in['name'] . ' (' . $in['source'] . ')');
        flash(t('Report saved.'));
        redirect('/report-builder?id=' . $id);
    }

    $d = rb_definition($id);
    if (! $d) {
        refuse(404, t('That saved report does not exist, or is not open to you.'));
    }

    if ($do === 'export') {
        if (! rb_can_export((string) $d['source'])) {
            refuse(403, t('Your role may read this report but not export it.'));
        }
        $result = rb_run($d, date('Y-m-d'));
        $csv = rb_csv($result);
        $sha = hash('sha256', $csv);
        rb_log_export('download', (string) $d['source'], (int) $d['id'], null, $result['filters'], count($result['rows']), $sha);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="crewvia-report-' . (int) $d['id'] . '-' . date('Ymd') . '.csv"');
        header('X-Content-SHA256: ' . $sha);
        header('Cache-Control: no-store');
        echo $csv;
        exit;
    }

    if (! rb_may_change($d)) {
        refuse(403, t('Only its owner or an administrator changes a saved report.'));
    }

    if ($do === 'archive') {
        q('UPDATE report_definitions SET archived_at = NOW() WHERE id = ?', [$id]);
        q('UPDATE report_schedules SET active = 0 WHERE definition_id = ?', [$id]);
        log_activity('archived a saved report', 'report', $id, (string) $d['name']);
        flash(t('Report archived, and its schedules stopped.'));
        redirect('/report-builder');
    }

    if ($do === 'schedule') {
        [$in, $why] = rb_schedule_input($d, $_POST);
        if ($why !== null) {
            refuse(422, $why);
        }
        $next = rb_next_run($in, date('Y-m-d H:i:s'));
        q('INSERT INTO report_schedules (definition_id, frequency, weekday, monthday, run_hour, recipients_json, next_run_at, created_by) VALUES (?,?,?,?,?,?,?,?)',
          [$id, $in['frequency'], $in['weekday'], $in['monthday'], $in['run_hour'], json_encode($in['recipients']), $next, uid()]);
        log_activity('scheduled a report', 'report', $id, $in['frequency'] . ' to ' . implode(',', $in['recipients']));
        flash(t('Scheduled. The first run is due :at.', ['at' => $next]));
        redirect('/report-builder?id=' . $id . '#schedules');
    }

    if ($do === 'schedule_stop' || $do === 'schedule_start') {
        $sid = (int) ($_POST['schedule_id'] ?? 0);
        $s = row('SELECT * FROM report_schedules WHERE id = ? AND definition_id = ?', [$sid, $id]);
        if (! $s) {
            refuse(404, t('That schedule does not exist.'));
        }
        $on = $do === 'schedule_start';
        q('UPDATE report_schedules SET active = ?, next_run_at = ? WHERE id = ?', [$on ? 1 : 0, $on ? rb_next_run($s, date('Y-m-d H:i:s')) : $s['next_run_at'], $sid]);
        log_activity($on ? 'restarted a report schedule' : 'stopped a report schedule', 'report', $id, (string) $sid);
        flash($on ? t('Schedule restarted.') : t('Schedule stopped.'));
        redirect('/report-builder?id=' . $id . '#schedules');
    }

    refuse(400, t('Unknown action.'));
}

$jobs = rows('SELECT id, title FROM jobs ORDER BY title, id');
$mine = rows('SELECT d.*, u.name AS owner_name FROM report_definitions d JOIN users u ON u.id = d.owner_id WHERE d.archived_at IS NULL AND (d.owner_id = ? OR d.shared = 1 OR ?) ORDER BY d.name, d.id',
             [uid(), can('admin') ? 1 : 0]);
$mine = array_values(array_filter($mine, fn($d) => isset($readable[$d['source']])));
$deliveries = rows('SELECT r.id, r.period_label, r.row_count, r.status, r.created_at, r.expires_at, d.name, x.downloaded_at FROM report_delivery_recipients x
                    JOIN report_deliveries r ON r.id = x.delivery_id JOIN report_definitions d ON d.id = r.definition_id
                    WHERE x.user_id = ? AND x.skipped_reason IS NULL ORDER BY r.id DESC LIMIT 50', [uid()]);
$definition = null;
$result = null;
$schedules = [];
$staff = [];
$form = null;
if (isset($_GET['id'])) {
    $definition = rb_definition((int) $_GET['id']);
    if (! $definition) {
        refuse(404, t('That saved report does not exist, or is not open to you.'));
    }
    $result = rb_run($definition, date('Y-m-d'));
    $schedules = rows('SELECT s.*, (SELECT COUNT(*) FROM report_deliveries r WHERE r.schedule_id = s.id) AS runs FROM report_schedules s WHERE s.definition_id = ? ORDER BY s.id', [(int) $definition['id']]);
    $staff = array_values(array_filter(rows("SELECT id, name, role, is_active FROM users WHERE is_active = 1 AND role IN ('admin','recruiter','payroll','hotels') ORDER BY name"),
                                       fn($u) => rb_recipient_refusal($u, (string) $definition['source']) === null));
    log_activity('viewed a saved report', 'report', (int) $definition['id'], (string) $definition['source']);
}
if (isset($_GET['edit']) || isset($_GET['new'])) {
    $form = isset($_GET['edit']) ? rb_definition((int) $_GET['edit']) : null;
    if (isset($_GET['edit']) && (! $form || ! rb_may_change($form))) {
        refuse(403, t('Only its owner or an administrator changes a saved report.'));
    }
    $source = $form['source'] ?? (string) ($_GET['source'] ?? array_key_first($readable));
    if (! isset($readable[$source])) {
        refuse(403, t('This report is not open to your role.'));
    }
    $form = ($form ?? ['id' => 0, 'name' => '', 'source' => $source, 'columns' => array_keys(report_run_columns($source)), 'filters' => ['period' => 'this_month'],
                       'conditions' => [], 'sort_column' => null, 'sort_dir' => 'asc', 'row_limit' => 1000, 'shared' => 0]);
    $form['all_columns'] = report_run_columns($source);
}
$policy = [];
if (can('admin')) {
    foreach ($catalog as $k => $r) {
        $policy[$k] = ['readers' => $r['roles'], 'exporters' => rb_export_roles($k), 'set' => val('SELECT COUNT(*) FROM report_export_policy WHERE source = ?', [$k]) > 0];
    }
}
$allSchedules = can('admin') ? rows('SELECT s.*, d.name FROM report_schedules s JOIN report_definitions d ON d.id = s.definition_id ORDER BY s.active DESC, s.next_run_at') : [];

render('report-builder', compact('catalog', 'readable', 'mine', 'deliveries', 'definition', 'result', 'schedules', 'staff', 'form', 'jobs', 'policy', 'allSchedules'));
