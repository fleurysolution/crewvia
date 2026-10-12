<?php
/**
 * Engagement surveys (P5-M05).
 *
 * Recruiters and administrators write a survey (scales of 1 to 5, the
 * 0 to 10 "would you recommend working here" question, single choices and
 * free text), choose who it is for, and open it. Opening asks everyone in
 * the audience at that moment. It closes on its date, by the scheduled
 * workflows, or by hand.
 *
 * Anonymous surveys:
 *   - the answers carry no name, a random id and no time; who was asked
 *     and the day they answered are kept apart, so neither order nor clock
 *     ties answers to a person;
 *   - results show only once at least the survey's minimum have answered
 *     (5 by default, never fewer than 3), and a group (staff, supervisors,
 *     workers) is broken out only when it has that many too;
 *   - it cannot be opened for fewer people than that minimum.
 * A survey that is not anonymous says so to whoever answers it, and keeps
 * their name with their answers.
 */

declare(strict_types=1);

require_once __DIR__ . '/engagement.php';

/** @return array<string,string> */
function sv_audiences(): array
{
    return ['everyone' => t('Everyone'), 'staff' => t('Staff'), 'workers' => t('Workers'), 'role' => t('Everyone of a desk role'), 'project' => t('Everyone on a project')];
}

/** @return array<string,string> */
function sv_kinds(): array
{
    return ['scale' => t('Scale of 1 to 5'), 'enps' => t('Would recommend, 0 to 10'), 'choice' => t('One choice'), 'text' => t('Free text')];
}

/** @return array<string,string> */
function sv_statuses(): array
{
    return ['draft' => t('Draft'), 'open' => t('Open'), 'closed' => t('Closed')];
}

/** @return array<string,string> */
function sv_segments(): array
{
    return ['staff' => t('Staff'), 'supervisors' => t('Supervisors'), 'workers' => t('Workers')];
}

function sv_segment(int $userId): string
{
    if (eng_is_worker($userId)) {
        return 'workers';
    }

    return (string) val('SELECT role FROM users WHERE id = ?', [$userId]) === 'supervisor' ? 'supervisors' : 'staff';
}

/** @return array{0:?string,1:int} */
function sv_save(array $in, int $by, int $id = 0): array
{
    $title = trim((string) ($in['title'] ?? ''));
    $intro = trim((string) ($in['intro'] ?? ''));
    $audience = (string) ($in['audience'] ?? '');
    $role = trim((string) ($in['audience_role'] ?? ''));
    $job = (int) ($in['job_id'] ?? 0);
    $min = trim((string) ($in['min_group'] ?? '5'));
    $closes = trim((string) ($in['closes_on'] ?? ''));
    $anonymous = ! empty($in['is_anonymous']) ? 1 : 0;
    if (mb_strlen($title) < 3 || mb_strlen($title) > 190) { return [t('Give the survey a title of 3 to 190 characters.'), 0]; }
    if (mb_strlen($intro) > 1000) { return [t('A field is too long.'), 0]; }
    if (! isset(sv_audiences()[$audience])) { return [t('Say who the survey is for.'), 0]; }
    require_once __DIR__ . '/training.php';
    if ($audience === 'role' && ! isset(tr_staff_roles()[$role])) { return [t('Choose a desk role.'), 0]; }
    if ($audience === 'project' && ! row('SELECT id FROM jobs WHERE id = ?', [$job])) { return [t('Choose a project.'), 0]; }
    if (! ctype_digit($min) || (int) $min < 3 || (int) $min > 50) { return [t('Results show from 3 to 50 answers.'), 0]; }
    if (! valid_date($closes) || $closes <= date('Y-m-d')) { return [t('It closes on a date after today.'), 0]; }
    $values = [$title, $intro ?: null, $audience, $audience === 'role' ? $role : null, $audience === 'project' ? $job : null, $anonymous, (int) $min, $closes];
    if ($id) {
        $s = row('SELECT * FROM surveys WHERE id = ?', [$id]);
        if (! $s) { return [t('That survey does not exist.'), 0]; }
        if ($s['status'] !== 'draft') { return [t('A survey is changed only while it is a draft.'), 0]; }
        q('UPDATE surveys SET title = ?, intro = ?, audience = ?, audience_role = ?, job_id = ?, is_anonymous = ?, min_group = ?, closes_on = ? WHERE id = ?', [...$values, $id]);

        return [null, $id];
    }
    q('INSERT INTO surveys (title, intro, audience, audience_role, job_id, is_anonymous, min_group, closes_on, created_by) VALUES (?,?,?,?,?,?,?,?,?)', [...$values, $by]);
    $id = (int) db()->lastInsertId();
    log_activity('created a survey', 'survey', $id, $title);

    return [null, $id];
}

function sv_question_add(int $surveyId, array $in): ?string
{
    $s = row('SELECT * FROM surveys WHERE id = ?', [$surveyId]);
    if (! $s) { return t('That survey does not exist.'); }
    if ($s['status'] !== 'draft') { return t('A survey is changed only while it is a draft.'); }
    $kind = (string) ($in['kind'] ?? '');
    $prompt = trim((string) ($in['prompt'] ?? ''));
    if (! isset(sv_kinds()[$kind])) { return t('Say what kind of question it is.'); }
    if (mb_strlen($prompt) < 5 || mb_strlen($prompt) > 500) { return t('Ask the question in 5 to 500 characters.'); }
    if ((int) val('SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?', [$surveyId]) >= 30) { return t('A survey has at most 30 questions.'); }
    $options = null;
    if ($kind === 'choice') {
        $list = array_values(array_filter(array_map(fn($o) => trim((string) $o), (array) ($in['options'] ?? [])), fn($o) => $o !== ''));
        if (count($list) < 2 || count($list) > 8) { return t('Give 2 to 8 choices.'); }
        foreach ($list as $o) {
            if (mb_strlen($o) > 200) { return t('A choice is at most 200 characters.'); }
        }
        if (count(array_unique(array_map('mb_strtolower', $list))) !== count($list)) { return t('Two choices are the same.'); }
        $options = json_encode($list, JSON_UNESCAPED_UNICODE);
    }
    $next = (int) val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM survey_questions WHERE survey_id = ?', [$surveyId]);
    q('INSERT INTO survey_questions (survey_id, sort_order, kind, prompt, options_json, is_required) VALUES (?,?,?,?,?,?)',
      [$surveyId, $next, $kind, $prompt, $options, ! empty($in['is_required']) ? 1 : 0]);

    return null;
}

function sv_question_remove(int $surveyId, int $questionId): ?string
{
    $s = row('SELECT * FROM surveys WHERE id = ?', [$surveyId]);
    if (! $s || $s['status'] !== 'draft') { return t('A survey is changed only while it is a draft.'); }
    if (! row('SELECT id FROM survey_questions WHERE id = ? AND survey_id = ?', [$questionId, $surveyId])) { return t('That question does not exist.'); }
    q('DELETE FROM survey_questions WHERE id = ?', [$questionId]);

    return null;
}

/** Who a survey asks, as it stands now. */
function sv_audience_people(array $s): array
{
    $base = "SELECT u.id FROM users u WHERE u.is_active = 1 AND u.role <> 'client'";
    $ids = match ($s['audience']) {
        'staff'   => rows($base . ' AND NOT EXISTS (SELECT 1 FROM worker_accounts w WHERE w.user_id = u.id)'),
        'workers' => rows($base . ' AND EXISTS (SELECT 1 FROM worker_accounts w WHERE w.user_id = u.id)'),
        'role'    => rows($base . ' AND u.role = ? AND NOT EXISTS (SELECT 1 FROM worker_accounts w WHERE w.user_id = u.id)', [(string) $s['audience_role']]),
        'project' => rows($base . " AND u.id IN (SELECT w.user_id FROM worker_accounts w JOIN placements p ON p.candidate_id = w.candidate_id
                                                 WHERE p.job_id = ? AND p.status NOT IN ('completed','cancelled')
                                                 UNION SELECT d.supervisor_id FROM assignment_details d JOIN placements p ON p.id = d.placement_id
                                                 WHERE p.job_id = ? AND p.status NOT IN ('completed','cancelled') AND d.supervisor_id IS NOT NULL)",
                            [(int) $s['job_id'], (int) $s['job_id']]),
        default   => rows($base),
    };

    return array_map('intval', array_column($ids, 'id'));
}

/** @return array{0:?string,1:int} */
function sv_open(int $id, int $by): array
{
    $s = row('SELECT * FROM surveys WHERE id = ?', [$id]);
    if (! $s) { return [t('That survey does not exist.'), 0]; }
    if ($s['status'] !== 'draft') { return [t('That survey is already open or closed.'), 0]; }
    if (! val('SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?', [$id])) { return [t('Add a question first.'), 0]; }
    if ((string) $s['closes_on'] <= date('Y-m-d')) { return [t('It closes on a date after today.'), 0]; }
    $people = sv_audience_people($s);
    if (! $people) { return [t('Nobody is in that audience.'), 0]; }
    if ((int) $s['is_anonymous'] && count($people) < (int) $s['min_group']) {
        return [t('An anonymous survey needs at least :n people to ask; this audience has :have.', ['n' => (int) $s['min_group'], 'have' => count($people)]), 0];
    }
    db()->beginTransaction();
    q("UPDATE surveys SET status = 'open', opened_at = NOW() WHERE id = ? AND status = 'draft'", [$id]);
    foreach ($people as $u) {
        q('INSERT IGNORE INTO survey_invitations (survey_id, user_id) VALUES (?,?)', [$id, $u]);
    }
    db()->commit();
    foreach ($people as $u) {
        eng_notify($u, t('A survey for you, until :date: :title', ['date' => d((string) $s['closes_on']), 'title' => (string) $s['title']]), '/surveys?take=' . $id);
    }
    log_activity('opened a survey', 'survey', $id, count($people) . ' asked');

    return [null, count($people)];
}

function sv_close(int $id): ?string
{
    $s = row('SELECT * FROM surveys WHERE id = ?', [$id]);
    if (! $s || $s['status'] !== 'open') { return t('That survey is not open.'); }
    q("UPDATE surveys SET status = 'closed', closed_at = NOW() WHERE id = ?", [$id]);
    log_activity('closed a survey', 'survey', $id, (string) $s['title']);

    return null;
}

/** Close every open survey whose date has passed. Run by the scheduled workflows. */
function sv_close_due(): int
{
    return q("UPDATE surveys SET status = 'closed', closed_at = NOW() WHERE status = 'open' AND closes_on < CURDATE()")->rowCount();
}

/** Remind those asked who have not answered. Nobody is named to whoever sends it. */
function sv_remind(int $id): array
{
    $s = row('SELECT * FROM surveys WHERE id = ?', [$id]);
    if (! $s || $s['status'] !== 'open') { return [t('That survey is not open.'), 0]; }
    $n = 0;
    foreach (rows('SELECT user_id FROM survey_invitations WHERE survey_id = ? AND responded_on IS NULL', [$id]) as $r) {
        eng_notify((int) $r['user_id'], t('Reminder, until :date: :title', ['date' => d((string) $s['closes_on']), 'title' => (string) $s['title']]), '/surveys?take=' . $id);
        $n++;
    }

    return [null, $n];
}

/** The surveys this person was asked to answer. */
function sv_mine(int $userId): array
{
    return rows("SELECT s.id, s.title, s.closes_on, s.status, s.is_anonymous, i.responded_on FROM survey_invitations i JOIN surveys s ON s.id = i.survey_id
                 WHERE i.user_id = ? ORDER BY s.status = 'open' DESC, s.closes_on DESC", [$userId]);
}

function sv_questions(int $surveyId): array
{
    $list = rows('SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order, id', [$surveyId]);
    foreach ($list as &$qq) {
        $qq['options'] = $qq['options_json'] ? (array) json_decode((string) $qq['options_json'], true) : [];
    }

    return $list;
}

/** Hand in the answers, once. */
function sv_respond(int $surveyId, int $userId, array $answers): ?string
{
    $s = row('SELECT * FROM surveys WHERE id = ?', [$surveyId]);
    if (! $s || ! row('SELECT user_id FROM survey_invitations WHERE survey_id = ? AND user_id = ?', [$surveyId, $userId])) { return t('That survey was not sent to you.'); }
    if ($s['status'] !== 'open' || (string) $s['closes_on'] < date('Y-m-d')) { return t('That survey is closed.'); }
    $rows = [];
    foreach (sv_questions($surveyId) as $qq) {
        $raw = trim((string) ($answers[(int) $qq['id']] ?? ''));
        if ($raw === '') {
            if ((int) $qq['is_required']) { return t('Answer every question marked as required.'); }
            continue;
        }
        $score = $choice = $text = null;
        switch ($qq['kind']) {
            case 'scale':
                if (! ctype_digit($raw) || (int) $raw < 1 || (int) $raw > 5) { return t('A scale answer is 1 to 5.'); }
                $score = (int) $raw;
                break;
            case 'enps':
                if (! ctype_digit($raw) || (int) $raw > 10) { return t('That answer is 0 to 10.'); }
                $score = (int) $raw;
                break;
            case 'choice':
                if (! ctype_digit($raw) || ! isset($qq['options'][(int) $raw])) { return t('Choose one of the answers given.'); }
                $choice = (int) $raw;
                break;
            default:
                if (mb_strlen($raw) > 2000) { return t('A written answer is at most 2000 characters.'); }
                $text = $raw;
        }
        $rows[] = [(int) $qq['id'], $score, $choice, $text];
    }
    db()->beginTransaction();
    $inv = row('SELECT * FROM survey_invitations WHERE survey_id = ? AND user_id = ? FOR UPDATE', [$surveyId, $userId]);
    if ($inv['responded_on'] !== null) { db()->rollBack(); return t('You have already answered this survey.'); }
    q('UPDATE survey_invitations SET responded_on = CURDATE() WHERE survey_id = ? AND user_id = ?', [$surveyId, $userId]);
    $rid = bin2hex(random_bytes(16));
    q('INSERT INTO survey_responses (id, survey_id, user_id, segment) VALUES (?,?,?,?)', [$rid, $surveyId, (int) $s['is_anonymous'] ? null : $userId, sv_segment($userId)]);
    foreach ($rows as [$qid, $score, $choice, $text]) {
        q('INSERT INTO survey_answers (response_id, question_id, score, choice_index, answer_text) VALUES (?,?,?,?,?)', [$rid, $qid, $score, $choice, $text]);
    }
    db()->commit();

    return null;
}

/**
 * What the answers say. Nothing per question while an anonymous survey has
 * fewer answers than its minimum; a group only when it reaches it too.
 */
function sv_results(array $s): array
{
    $id = (int) $s['id'];
    $min = (int) $s['min_group'];
    $anonymous = (bool) (int) $s['is_anonymous'];
    $out = [
        'invited'   => (int) val('SELECT COUNT(*) FROM survey_invitations WHERE survey_id = ?', [$id]),
        'responded' => (int) val('SELECT COUNT(*) FROM survey_responses WHERE survey_id = ?', [$id]),
        'shown'     => false,
        'questions' => [],
        'segments'  => [],
    ];
    if ($anonymous && $out['responded'] < $min) {
        return $out;
    }
    $out['shown'] = true;
    $segments = [];
    foreach (rows('SELECT segment, COUNT(*) AS n FROM survey_responses WHERE survey_id = ? GROUP BY segment', [$id]) as $g) {
        if (! $anonymous || (int) $g['n'] >= $min) {
            $segments[(string) $g['segment']] = (int) $g['n'];
        }
    }
    $out['segments'] = count($segments) > 1 ? $segments : [];
    foreach (sv_questions($id) as $qq) {
        $answers = rows('SELECT a.*, r.segment, u.name FROM survey_answers a JOIN survey_responses r ON r.id = a.response_id LEFT JOIN users u ON u.id = r.user_id
                         WHERE a.question_id = ? ORDER BY a.response_id', [(int) $qq['id']]);
        $res = ['question' => $qq, 'n' => count($answers)];
        $scores = array_map(fn($a) => (int) $a['score'], array_filter($answers, fn($a) => $a['score'] !== null));
        if ($qq['kind'] === 'scale') {
            $res['average'] = $scores ? round(array_sum($scores) / count($scores), 2) : null;
            $res['counts'] = array_replace(array_fill(1, 5, 0), array_count_values($scores));
        } elseif ($qq['kind'] === 'enps') {
            $promoters = count(array_filter($scores, fn($v) => $v >= 9));
            $detractors = count(array_filter($scores, fn($v) => $v <= 6));
            $res['promoters'] = $promoters;
            $res['passives'] = count($scores) - $promoters - $detractors;
            $res['detractors'] = $detractors;
            $res['enps'] = $scores ? (int) round(100 * ($promoters - $detractors) / count($scores)) : null;
        } elseif ($qq['kind'] === 'choice') {
            $counts = array_fill(0, count($qq['options']), 0);
            foreach ($answers as $a) {
                if ($a['choice_index'] !== null && isset($counts[(int) $a['choice_index']])) { $counts[(int) $a['choice_index']]++; }
            }
            $res['counts'] = $counts;
        } else {
            $res['texts'] = array_map(fn($a) => ['text' => (string) $a['answer_text'], 'name' => $anonymous ? null : $a['name']], array_filter($answers, fn($a) => $a['answer_text'] !== null));
        }
        if (in_array($qq['kind'], ['scale', 'enps'], true) && $out['segments']) {
            foreach (array_keys($out['segments']) as $seg) {
                $v = array_map(fn($a) => (int) $a['score'], array_filter($answers, fn($a) => $a['score'] !== null && $a['segment'] === $seg));
                $res['by_segment'][$seg] = $v ? round(array_sum($v) / count($v), 2) : null;
            }
        }
        $out['questions'][] = $res;
    }

    return $out;
}
