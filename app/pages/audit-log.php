<?php
/**
 * The audit log (P4-M06), for administrators: every entry of `activity`,
 * filtered by person, action, record and dates, newest first, with the
 * address each came from. It is read only, here and in the database (the
 * table refuses change and deletion). Reading it is itself logged.
 */

declare(strict_types=1);

require_role('admin');

$filters = [
    'user'   => (int) ($_GET['user'] ?? 0),
    'action' => mb_substr(trim((string) ($_GET['action'] ?? '')), 0, 60),
    'entity' => preg_match('/^[a-z_]{1,40}$/', (string) ($_GET['entity'] ?? '')) ? (string) $_GET['entity'] : '',
    'id'     => (int) ($_GET['id'] ?? 0),
    'from'   => valid_date((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y-m-d', strtotime('-30 days')),
    'to'     => valid_date((string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : date('Y-m-d'),
];
$where = ['a.created_at >= ?', 'a.created_at < DATE_ADD(?, INTERVAL 1 DAY)'];
$args = [$filters['from'], $filters['to']];
if ($filters['user'] > 0) { $where[] = 'a.user_id = ?'; $args[] = $filters['user']; }
if ($filters['action'] !== '') { $where[] = 'a.action LIKE ?'; $args[] = '%' . addcslashes($filters['action'], '%_\\') . '%'; }
if ($filters['entity'] !== '') { $where[] = 'a.entity = ?'; $args[] = $filters['entity']; }
if ($filters['id'] > 0) { $where[] = 'a.entity_id = ?'; $args[] = $filters['id']; }
$page = max(1, (int) ($_GET['page'] ?? 1));
$entries = rows('SELECT a.*, u.name AS by_name FROM activity a LEFT JOIN users u ON u.id = a.user_id WHERE ' . implode(' AND ', $where)
                . ' ORDER BY a.id DESC LIMIT 201 OFFSET ' . (($page - 1) * 200), $args);
$more = count($entries) > 200;
$entries = array_slice($entries, 0, 200);
$people = rows('SELECT id, name, role FROM users ORDER BY name');
$entities = array_column(rows("SELECT DISTINCT entity FROM activity WHERE entity <> '' ORDER BY entity"), 'entity');
log_activity('read the audit log', 'activity', 0, http_build_query(array_filter($filters)));

render('audit-log', compact('filters', 'entries', 'more', 'page', 'people', 'entities'));
