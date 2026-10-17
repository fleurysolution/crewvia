<?php
/**
 * P5-M86: the recruiter's checklist - the questions asked on the phone before an applicant goes for review (RSS,
 * 17 Oct 2026: "a recruiting checklist with all the questions they need to ask... templated: these are the questions
 * we ask forklift drivers, these for CDL... link a template to the requisition... when it's filled out and good,
 * they move on").
 *
 * A template is a named list of questions. A requisition links one or more templates. On the person's page, the
 * recruiter marks each question met or not met for the application; an applicant goes for review once every
 * question is answered and met (the owner can still send anyone).
 */

declare(strict_types=1);

function cl_templates(): array
{
    return rows('SELECT t.*, (SELECT COUNT(*) FROM checklist_items i WHERE i.template_id = t.id AND i.is_active = 1) AS items,
                        (SELECT COUNT(*) FROM vacancy_checklists v WHERE v.template_id = t.id) AS used
                 FROM checklist_templates t WHERE t.is_active = 1 ORDER BY t.name');
}

function cl_items(int $templateId): array
{
    return rows('SELECT * FROM checklist_items WHERE template_id = ? AND is_active = 1 ORDER BY position, id', [$templateId]);
}

/** Saves a template from its name and its questions, one per line. Questions kept as written keep their answers. */
function cl_save(int $id, string $name, string $questions, int $by): array
{
    $name = trim($name);
    $lines = array_values(array_filter(array_map(static fn($l) => mb_substr(trim($l), 0, 255), preg_split('/\R/', $questions)), static fn($l) => $l !== ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        return [t('Name the template (for example: Forklift driver).'), 0];
    }
    if (! $lines || count($lines) > 40) {
        return [t('Write the questions, one per line (up to 40).'), 0];
    }
    if (val('SELECT id FROM checklist_templates WHERE LOWER(name) = LOWER(?) AND is_active = 1 AND id <> ?', [$name, $id])) {
        return [t('A template already has this name.'), 0];
    }
    if ($id) {
        q('UPDATE checklist_templates SET name = ? WHERE id = ?', [$name, $id]);
    } else {
        q('INSERT INTO checklist_templates (name, created_by) VALUES (?, ?)', [$name, $by]);
        $id = (int) db()->lastInsertId();
    }
    $existing = array_column(rows('SELECT id, question FROM checklist_items WHERE template_id = ? AND is_active = 1', [$id]), 'id', 'question');
    foreach ($lines as $pos => $question) {
        if (isset($existing[$question])) {
            q('UPDATE checklist_items SET position = ? WHERE id = ?', [$pos, $existing[$question]]);
            unset($existing[$question]);
        } else {
            q('INSERT INTO checklist_items (template_id, position, question) VALUES (?,?,?)', [$id, $pos, $question]);
        }
    }
    // A question taken out is retired, not deleted: the answers given to it stay in the record.
    foreach ($existing as $itemId) {
        q('UPDATE checklist_items SET is_active = 0 WHERE id = ?', [$itemId]);
    }

    return [null, $id];
}

function cl_vacancy_templates(int $vacancyId): array
{
    try {
        return array_map('intval', array_column(rows('SELECT template_id FROM vacancy_checklists WHERE vacancy_id = ?', [$vacancyId]), 'template_id'));
    } catch (Throwable $e) {
        return [];
    }
}

function cl_vacancy_save(int $vacancyId, array $templateIds): void
{
    q('DELETE FROM vacancy_checklists WHERE vacancy_id = ?', [$vacancyId]);
    foreach (array_unique(array_map('intval', $templateIds)) as $tid) {
        if ($tid && val('SELECT id FROM checklist_templates WHERE id = ? AND is_active = 1', [$tid])) {
            q('INSERT IGNORE INTO vacancy_checklists (vacancy_id, template_id) VALUES (?, ?)', [$vacancyId, $tid]);
        }
    }
}

/**
 * The checklist of an application: its questions with their answers, and where it stands.
 *
 * @return array{items:list<array>,answered:int,met:int,total:int,complete:bool,passes:bool}
 */
function cl_for_application(int $applicationId): array
{
    $out = ['items' => [], 'answered' => 0, 'met' => 0, 'total' => 0, 'complete' => true, 'passes' => true];
    try {
        $items = rows("SELECT i.id, i.question, t.name AS template, ans.answer, ans.note, ans.answered_at, u.name AS answered_by
                       FROM applications a JOIN vacancy_checklists vc ON vc.vacancy_id = a.vacancy_id
                       JOIN checklist_templates t ON t.id = vc.template_id AND t.is_active = 1
                       JOIN checklist_items i ON i.template_id = t.id AND i.is_active = 1
                       LEFT JOIN checklist_answers ans ON ans.application_id = a.id AND ans.item_id = i.id
                       LEFT JOIN users u ON u.id = ans.answered_by
                       WHERE a.id = ? ORDER BY t.name, i.position, i.id", [$applicationId]);
    } catch (Throwable $e) {
        return $out;
    }
    $out['items'] = $items;
    $out['total'] = count($items);
    foreach ($items as $i) {
        $out['answered'] += $i['answer'] !== null ? 1 : 0;
        $out['met'] += $i['answer'] === 'met' ? 1 : 0;
    }
    $out['complete'] = $out['answered'] === $out['total'];
    $out['passes'] = $out['met'] === $out['total'];

    return $out;
}

/** Records the recruiter's answers for an application: [item id => 'met'|'not_met'|''], notes by item. */
function cl_answer(int $applicationId, array $answers, array $notes, int $by): void
{
    $allowed = array_map('intval', array_column(cl_for_application($applicationId)['items'], 'id'));
    foreach ($answers as $itemId => $answer) {
        $itemId = (int) $itemId;
        if (! in_array($itemId, $allowed, true)) {
            continue;
        }
        if (! in_array($answer, ['met', 'not_met'], true)) {
            q('DELETE FROM checklist_answers WHERE application_id = ? AND item_id = ?', [$applicationId, $itemId]);
            continue;
        }
        q('INSERT INTO checklist_answers (application_id, item_id, answer, note, answered_by) VALUES (?,?,?,?,?)
           ON DUPLICATE KEY UPDATE answer = VALUES(answer), note = VALUES(note), answered_by = VALUES(answered_by), answered_at = NOW()',
          [$applicationId, $itemId, $answer, mb_substr(trim((string) ($notes[$itemId] ?? '')), 0, 255) ?: null, $by]);
    }
}
