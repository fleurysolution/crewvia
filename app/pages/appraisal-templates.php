<?php
/**
 * Appraisal templates (P2-M02). Administrators only. A template picks its
 * criteria and weights, its scale and its grade thresholds. Once a review
 * uses it, it is fixed: what an old grade meant must not change. A new
 * version is a new template, and the old one is retired.
 */

require_once __DIR__ . '/../appraisals.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['template_id'] ?? 0);

    if ($do === 'criterion') {
        $label = trim((string) ($_POST['label'] ?? ''));
        $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_');
        if (mb_strlen($label) < 3 || mb_strlen($label) > 90 || $slug === '' || strlen($slug) > 40) {
            refuse(422, t('Name the criterion in 3 to 90 characters.'));
        }
        if (val('SELECT COUNT(*) FROM assignment_review_criteria WHERE slug = ?', [$slug])) {
            refuse(422, t('That criterion already exists.'));
        }
        q('INSERT INTO assignment_review_criteria (slug, label, sort_order) VALUES (?,?,?)',
          [$slug, $label, (int) val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM assignment_review_criteria')]);
        log_activity('added a review criterion', 'criterion', 0, $slug);
        flash(t('Criterion added. It can now be put on a template, and it appears on the roster\'s review.'));
        redirect('/appraisal-templates');
    }

    if ($do === 'save') {
        $label = trim((string) ($_POST['label'] ?? ''));
        $code = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_');
        $kind = (string) ($_POST['kind'] ?? '');
        $scale = (int) ($_POST['scale_max'] ?? 0);
        $th = [];
        foreach (['grade_a', 'grade_b', 'grade_c', 'grade_d'] as $col) {
            $th[$col] = is_numeric($_POST[$col] ?? null) ? round((float) $_POST[$col], 2) : -1;
        }
        $weights = [];
        foreach (assignment_review_criteria() as $slug => $l) {
            $w = (int) ($_POST['weight'][$slug] ?? 0);
            if ($w < 0 || $w > 10) {
                refuse(422, t('A weight is from 0 (left out) to 10.'));
            }
            if ($w > 0) {
                $weights[$slug] = $w;
            }
        }

        if (mb_strlen($label) < 3 || mb_strlen($label) > 120 || $code === '' || strlen($code) > 40) {
            refuse(422, t('Name the template in 3 to 120 characters.'));
        }
        if (! isset(appraisal_kinds()[$kind])) {
            refuse(422, t('Choose what kind of review it is.'));
        }
        if ($scale < 3 || $scale > 10) {
            refuse(422, t('The scale runs from 1 to between 3 and 10.'));
        }
        if (min($th) < 0 || max($th) > 100 || ! ($th['grade_a'] > $th['grade_b'] && $th['grade_b'] > $th['grade_c'] && $th['grade_c'] > $th['grade_d'])) {
            refuse(422, t('Grade thresholds are percentages, A above B above C above D.'));
        }
        if (! $weights) {
            refuse(422, t('Give at least one criterion a weight.'));
        }
        if ($id) {
            $t = row('SELECT * FROM appraisal_templates WHERE id = ?', [$id]);
            if (! $t) {
                refuse(404, t('That template does not exist.'));
            }
            if (val('SELECT COUNT(*) FROM appraisals WHERE template_id = ?', [$id])) {
                refuse(422, t('Reviews already use this template, so it cannot change. Create a new version and retire this one.'));
            }
        } elseif (val('SELECT COUNT(*) FROM appraisal_templates WHERE code = ?', [$code])) {
            refuse(422, t('A template with that name already exists.'));
        }

        db()->beginTransaction();
        if ($id) {
            q('UPDATE appraisal_templates SET label = ?, kind = ?, scale_max = ?, self_review = ?, grade_a = ?, grade_b = ?, grade_c = ?, grade_d = ? WHERE id = ?',
              [$label, $kind, $scale, ! empty($_POST['self_review']) ? 1 : 0, $th['grade_a'], $th['grade_b'], $th['grade_c'], $th['grade_d'], $id]);
            q('DELETE FROM appraisal_template_criteria WHERE template_id = ?', [$id]);
        } else {
            q('INSERT INTO appraisal_templates (code, label, kind, scale_max, self_review, grade_a, grade_b, grade_c, grade_d, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
              [$code, $label, $kind, $scale, ! empty($_POST['self_review']) ? 1 : 0, $th['grade_a'], $th['grade_b'], $th['grade_c'], $th['grade_d'], uid()]);
            $id = (int) db()->lastInsertId();
        }
        $order = 0;
        foreach ($weights as $slug => $w) {
            q('INSERT INTO appraisal_template_criteria (template_id, criterion_slug, weight, sort_order) VALUES (?,?,?,?)', [$id, $slug, $w, ++$order]);
        }
        db()->commit();
        log_activity('saved an appraisal template', 'appraisal_template', $id, $label . ' ' . json_encode($weights));
        flash(t('Template saved.'));
        redirect('/appraisal-templates?id=' . $id);
    }

    if ($do === 'retire' || $do === 'restore') {
        if (! val('SELECT COUNT(*) FROM appraisal_templates WHERE id = ?', [$id])) {
            refuse(404, t('That template does not exist.'));
        }
        q('UPDATE appraisal_templates SET is_active = ? WHERE id = ?', [$do === 'restore' ? 1 : 0, $id]);
        log_activity($do . 'd an appraisal template', 'appraisal_template', $id);
        flash($do === 'restore' ? t('Template back in use.') : t('Template retired. Reviews already open on it carry on.'));
        redirect('/appraisal-templates');
    }

    refuse(400, t('Unknown action.'));
}

$templates = appraisal_templates(false);
$criteria = assignment_review_criteria();
$edit = null;
$editWeights = [];
if (isset($_GET['id'])) {
    $edit = row('SELECT t.*, (SELECT COUNT(*) FROM appraisals a WHERE a.template_id = t.id) AS used FROM appraisal_templates t WHERE t.id = ?', [(int) $_GET['id']]);
    if (! $edit) {
        refuse(404, t('That template does not exist.'));
    }
    foreach (appraisal_template_criteria((int) $edit['id']) as $c) {
        $editWeights[$c['slug']] = (int) $c['weight'];
    }
}

render('appraisal-templates', compact('templates', 'criteria', 'edit', 'editWeights'));
