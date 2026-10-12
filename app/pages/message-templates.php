<?php
/**
 * Text messages (P5-M07): the wording texts are written from, kept once,
 * for recruiters and administrators. See app/messaging.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../messaging.php';

require_role('recruiter');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$why, $id] = msg_template_save($_POST, uid(), (int) ($_POST['template_id'] ?? 0));
    if ($why !== null) { refuse(422, $why); }
    flash(t('Saved.'));
    redirect('/message-templates?id=' . $id);
}

$templates = rows('SELECT * FROM message_templates ORDER BY is_active DESC, purpose, name, language');
$edit = isset($_GET['id']) ? row('SELECT * FROM message_templates WHERE id = ?', [(int) $_GET['id']]) : null;
render('message-templates', compact('templates', 'edit'));
