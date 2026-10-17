<?php
/** P5-M86: /checklists - the templates of questions the recruiter asks on the phone, linked to requisitions. */

require_role('recruiter');
require_once __DIR__ . '/../checklists.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['template_id'] ?? 0);
    if ($do === 'save') {
        [$why, $id] = cl_save($id, (string) ($_POST['name'] ?? ''), (string) ($_POST['questions'] ?? ''), uid());
        if ($why) {
            refuse(422, $why, '/checklists' . ((int) ($_POST['template_id'] ?? 0) ? '?id=' . (int) $_POST['template_id'] : '#new-template'));
        }
        log_activity('saved a checklist template', 'checklist', $id);
        flash(t('Template saved.'));
        redirect('/checklists');
    }
    if ($do === 'retire' && $id) {
        q('UPDATE checklist_templates SET is_active = 0 WHERE id = ?', [$id]);
        q('DELETE FROM vacancy_checklists WHERE template_id = ?', [$id]);
        log_activity('retired a checklist template', 'checklist', $id);
        flash(t('Template removed. Answers already given stay on the applicants.'));
        redirect('/checklists');
    }
    refuse(422, t('Unknown action.'), '/checklists');
}

$templates = cl_templates();
$editing = isset($_GET['id']) ? row('SELECT * FROM checklist_templates WHERE id = ? AND is_active = 1', [(int) $_GET['id']]) : null;
$editingItems = $editing ? cl_items((int) $editing['id']) : [];
$pageTitle = t('Checklists') . ' · ' . $config['app_name'];
render('checklists', compact('templates', 'editing', 'editingItems'));
