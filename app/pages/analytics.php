<?php
/**
 * Dashboards (P4-M02). The list of dashboards this person may read, and
 * one dashboard with its filters. Every dashboard opened is logged.
 */

require_once __DIR__ . '/../analytics.php';

require_role('recruiter', 'payroll', 'hotels');

$catalog = analytics_catalog();
$key = (string) ($_GET['d'] ?? '');
$jobs = rows('SELECT id, title FROM jobs ORDER BY title, id');
$allowed = array_filter($catalog, fn($d) => can(...$d['roles']));

if ($key === '') {
    render('analytics', ['dashboard' => null, 'catalog' => $allowed]);
    return;
}
if (! isset($catalog[$key])) {
    refuse(404, t('That dashboard does not exist.'));
}
if (! analytics_allowed($key)) {
    refuse(403, t('This dashboard is not open to your role.'));
}
$def = $catalog[$key];
if ($def['ledger']) {
    // What was recorded since reaches the ledger before it is read.
    ledger_sync();
}

$today = date('Y-m-d');
$filters = [
    'from'   => valid_date((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y-m-01'),
    'to'     => valid_date((string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : $today,
    'asof'   => valid_date((string) ($_GET['asof'] ?? '')) ? (string) $_GET['asof'] : $today,
    'job_id' => (int) ($_GET['job_id'] ?? 0),
];
if ($filters['to'] < $filters['from']) {
    [$filters['from'], $filters['to']] = [$filters['to'], $filters['from']];
}
if ($filters['job_id'] > 0 && ! in_array($filters['job_id'], array_map('intval', array_column($jobs, 'id')), true)) {
    $filters['job_id'] = 0;
}
$used = array_intersect_key($filters, array_flip(array_merge(
    in_array('period', $def['filters'], true) ? ['from', 'to'] : [], in_array('asof', $def['filters'], true) ? ['asof'] : [],
    in_array('project', $def['filters'], true) && $filters['job_id'] > 0 ? ['job_id'] : [])));
$result = analytics_run($key, $filters);
log_activity('viewed a dashboard', 'dashboard', 0, $key . ' ' . http_build_query($used));

render('analytics', ['dashboard' => $key, 'def' => $def, 'result' => $result, 'filters' => $filters, 'jobs' => $jobs, 'catalog' => $allowed]);
