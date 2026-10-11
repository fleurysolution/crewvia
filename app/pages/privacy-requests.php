<?php
/**
 * Privacy, for administrators (P4-M06): publish the agency's privacy notice
 * in versions; answer a person's request to see what is held about them
 * (one file), or to be removed (applicants never placed only); see the
 * consents recorded. Every action is logged.
 */

declare(strict_types=1);

require_once __DIR__ . '/../privacy.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'publish') {
        if (($why = privacy_publish((string) ($_POST['body'] ?? ''))) !== null) {
            refuse(422, $why);
        }
        log_activity('published a privacy notice', 'privacy_notice', (int) db()->lastInsertId());
        flash(t('Privacy notice published. Consents from now on refer to this version.'));
        redirect('/privacy-requests');
    }
    $person = privacy_find((string) ($_POST['who'] ?? ''));
    if (! $person) {
        refuse(404, t('No person with that email or number.'));
    }
    if ($do === 'export') {
        $data = privacy_subject_export((int) $person['id']);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        log_activity('exported everything held about a person', 'candidate', (int) $person['id'], 'sha256 ' . hash('sha256', (string) $json));
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="person-' . (int) $person['id'] . '-' . date('Ymd') . '.json"');
        header('Cache-Control: no-store');
        echo $json;
        exit;
    }
    if ($do === 'anonymise') {
        db()->beginTransaction();
        if (($why = privacy_anonymise((int) $person['id'], (string) ($_POST['reason'] ?? ''))) !== null) {
            db()->rollBack();
            refuse(422, $why);
        }
        db()->commit();
        log_activity('anonymised an applicant', 'candidate', (int) $person['id'], mb_substr(trim((string) $_POST['reason']), 0, 300));
        flash(t('The applicant was removed. Their record now holds no name, contact details or résumé.'));
        redirect('/privacy-requests');
    }
    refuse(400, t('Unknown action.'));
}

$notice = privacy_notice_current();
$versions = rows('SELECT n.id, n.sha256, n.published_at, u.name AS by_name, (SELECT COUNT(*) FROM consent_records c WHERE c.notice_id = n.id) AS consents
                  FROM privacy_notices n LEFT JOIN users u ON u.id = n.published_by ORDER BY n.id DESC');
$consents = rows('SELECT c.purpose, c.source, c.notice_id, c.given_at, k.id AS candidate_id, k.full_name FROM consent_records c JOIN candidates k ON k.id = c.candidate_id ORDER BY c.id DESC LIMIT 50');
$unrecorded = (int) val("SELECT COUNT(*) FROM candidates c WHERE NOT EXISTS (SELECT 1 FROM consent_records r WHERE r.candidate_id = c.id) AND c.full_name NOT LIKE 'Removed applicant %'");

render('privacy-requests', compact('notice', 'versions', 'consents', 'unrecorded'));
