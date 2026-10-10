<?php
/**
 * Reports (P4-M01). The list of reports this person may read, and one
 * report with its filters, on screen or on paper. Every report read is
 * logged with its filters.
 */

require_once __DIR__ . '/../reports.php';

require_role('recruiter', 'payroll');

$catalog = reports_catalog();
$key = (string) ($_GET['r'] ?? '');
$jobs = rows('SELECT id, title FROM jobs ORDER BY title, id');

if ($key === '') {
    $allowed = array_filter($catalog, fn($r) => can(...$r['roles']));
    render('reports', ['report' => null, 'catalog' => $allowed, 'jobs' => $jobs]);
    return;
}
if (! isset($catalog[$key])) {
    refuse(404, t('That report does not exist.'));
}
if (! report_allowed($key)) {
    refuse(403, t('This report is not open to your role.'));
}

$today = date('Y-m-d');
$filters = [
    'from'   => valid_date((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y-m-01'),
    'to'     => valid_date((string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : $today,
    'asof'   => valid_date((string) ($_GET['asof'] ?? '')) ? (string) $_GET['asof'] : $today,
    'year'   => preg_match('/^\d{4}$/', (string) ($_GET['year'] ?? '')) ? (int) $_GET['year'] : (int) date('Y'),
    'job_id' => (int) ($_GET['job_id'] ?? 0),
];
if ($filters['to'] < $filters['from']) {
    [$filters['from'], $filters['to']] = [$filters['to'], $filters['from']];
}
if ($filters['job_id'] > 0 && ! in_array($filters['job_id'], array_map('intval', array_column($jobs, 'id')), true)) {
    $filters['job_id'] = 0;
}
$def = $catalog[$key];
$result = report_run($key, $filters);
$project = '';
foreach ($jobs as $j) {
    if ((int) $j['id'] === $filters['job_id']) {
        $project = (string) $j['title'];
    }
}
$used = array_intersect_key($filters, array_flip(array_merge(
    in_array('period', $def['filters'], true) ? ['from', 'to'] : [], in_array('asof', $def['filters'], true) ? ['asof'] : [],
    in_array('year', $def['filters'], true) ? ['year'] : [], in_array('project', $def['filters'], true) && $filters['job_id'] > 0 ? ['job_id'] : [])));
$query = http_build_query(['r' => $key] + $used);
log_activity(isset($_GET['print']) ? 'printed a report' : 'viewed a report', 'report', 0, $key . ' ' . http_build_query($used));

if (isset($_GET['print'])) {
    $brand = (string) ($config['app_name'] ?? 'Crewvia');
    try { $brand = (string) (val("SELECT setting_value FROM platform_settings WHERE setting_key = 'brand_name'") ?: $brand); } catch (Throwable $e) {}
    require __DIR__ . '/../views/reports-print.php';
    exit;
}

render('reports', ['report' => $key, 'def' => $def, 'result' => $result, 'filters' => $filters, 'jobs' => $jobs, 'project' => $project,
                   'query' => $query, 'catalog' => array_filter($catalog, fn($r) => can(...$r['roles']))]);
