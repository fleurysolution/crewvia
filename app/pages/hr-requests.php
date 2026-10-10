<?php
/**
 * HR requests (P2-M07). A worker sends them and follows their own; payroll
 * answers the pay questions, recruiting the rest, administrators all. An
 * employment letter prints on its own (?letter=ID).
 */

require_once __DIR__ . '/../hr-requests.php';

require_login();

$role = (string) (user()['role'] ?? '');
if ($role !== 'worker') {
    require_role('recruiter', 'payroll');
}

$brand = (string) ($config['app_name'] ?? 'Crewvia');
try { $brand = (string) (val("SELECT setting_value FROM platform_settings WHERE setting_key = 'brand_name'") ?: $brand); } catch (Throwable $e) {}

$load = static function (int $id): ?array {
    $r = row('SELECT h.*, c.full_name FROM hr_requests h JOIN candidates c ON c.id = h.candidate_id WHERE h.id = ?', [$id]);
    if (! $r) {
        return null;
    }
    return hr_self() === (int) $r['candidate_id'] || hr_request_staff($r) ? $r : null;
};

if (isset($_GET['letter'])) {
    $r = $load((int) $_GET['letter']);
    if (! $r || $r['letter_text'] === null || ! hash_equals((string) $r['letter_sha256'], hash('sha256', (string) $r['letter_text']))) {
        refuse(404, t('That letter does not exist.'));
    }
    $issuer = (string) val('SELECT name FROM users WHERE id = ?', [(int) $r['letter_issued_by']]);
    require __DIR__ . '/../views/employment-letter.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['request_id'] ?? 0);
    $r = $id ? $load($id) : null;
    if ($id && ! $r) {
        refuse(404, t('That request does not exist.'));
    }

    db()->beginTransaction();
    switch ($do) {
        case 'create':
            [$id, $why] = hr_request_create($_POST);
            break;
        case 'reply':
            $why = hr_request_reply($r, (string) ($_POST['message'] ?? ''));
            break;
        case 'close':
        case 'withdraw':
            $why = hr_request_close($r, $do === 'withdraw');
            break;
        case 'letter':
            $why = hr_request_issue_letter($r, $brand);
            break;
        default:
            $why = t('Unknown action.');
    }
    if ($why !== null) {
        db()->rollBack();
        $forbidden = [t('Only a worker sends an HR request, from their own account.'), t('Only the worker, or the desk it went to, replies.'),
                      t('Only the worker, or the desk it went to, closes a request.'), t('Only the worker withdraws their request.')];
        refuse(in_array($why, $forbidden, true) ? 403 : 422, $why);
    }
    db()->commit();
    log_activity('hr request ' . $do, 'hr_request', $id);
    flash(t('Recorded.'));
    redirect('/hr-requests?id=' . $id);
}

if (isset($_GET['id'])) {
    $r = $load((int) $_GET['id']);
    if (! $r) {
        refuse(404, t('That request does not exist.'));
    }
    $replies = rows('SELECT x.*, u.name AS by_name FROM hr_request_replies x LEFT JOIN users u ON u.id = x.user_id WHERE x.request_id = ? ORDER BY x.id', [(int) $r['id']]);
    render('hr-requests', ['mode' => 'one', 'r' => $r, 'replies' => $replies, 'role' => $role]);
    return;
}

if ($role === 'worker') {
    $list = rows("SELECT h.*, NULL AS full_name FROM hr_requests h WHERE h.candidate_id = ? ORDER BY FIELD(h.status,'answered','open','closed','withdrawn'), h.id DESC", [hr_self()]);
} else {
    $desks = can('admin') ? ['payroll', 'recruiter'] : array_values(array_filter(['payroll', 'recruiter'], fn($d) => can($d)));
    $list = rows("SELECT h.*, c.full_name FROM hr_requests h JOIN candidates c ON c.id = h.candidate_id
                  WHERE h.desk IN ('" . implode("','", $desks) . "') ORDER BY FIELD(h.status,'open','answered','closed','withdrawn'), h.created_at LIMIT 300");
}
render('hr-requests', ['mode' => 'list', 'list' => $list, 'role' => $role]);
