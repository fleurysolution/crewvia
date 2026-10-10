<?php
/**
 * Employee self-service (P2-M07): one home for the worker, and HR requests.
 *
 * The home gathers what the worker already has elsewhere - their details
 * and the changes waiting on staff (R22-R24), time off and what is left,
 * pay statements, benefits, reviews, goals, their HR record - and puts
 * first what is waiting on them: a review asking for their view, an HR
 * case to acknowledge, an HR request answered.
 *
 * An HR request is something the worker asks: an employment letter, a pay
 * question, a copy of a document, a schedule change, anything else. It
 * goes to the desk that answers it (payroll for pay, recruiting for the
 * rest), which replies, and the worker replies back until it is closed.
 * An employment letter is issued as a letter the worker can print, with
 * the dates and the trade, and the pay rate only if the worker asked for
 * it. The letter is frozen when issued, with its fingerprint.
 */

declare(strict_types=1);

require_once __DIR__ . '/hr.php';

function hr_request_kinds(): array
{
    return ['employment_letter' => t('Employment verification letter'), 'pay_question' => t('Question about my pay'),
            'document_copy' => t('A copy of a document'), 'schedule' => t('My schedule or shifts'), 'other' => t('Something else')];
}

function hr_request_desk(string $kind): string
{
    return $kind === 'pay_question' ? 'payroll' : 'recruiter';
}

function hr_request_statuses(): array
{
    return ['open' => t('Waiting for HR'), 'answered' => t('Answered'), 'closed' => t('Closed'), 'withdrawn' => t('Withdrawn')];
}

function hr_self(): int
{
    return (user()['role'] ?? '') === 'worker' ? (int) val('SELECT candidate_id FROM worker_accounts WHERE user_id = ?', [uid()]) : 0;
}

/** May the signed-in staff member answer this request? */
function hr_request_staff(array $r): bool
{
    return can('admin') || ($r['desk'] === 'payroll' ? can('payroll') : can('recruiter'));
}

function hr_request_next_reference(): string
{
    $prefix = 'REQ-' . date('Ym') . '-';
    $n = (int) val('SELECT COUNT(*) FROM hr_requests WHERE reference LIKE ?', [$prefix . '%']);
    do {
        $ref = $prefix . str_pad((string) ++$n, 4, '0', STR_PAD_LEFT);
    } while (val('SELECT COUNT(*) FROM hr_requests WHERE reference = ?', [$ref]));

    return $ref;
}

function hr_request_notify_desk(string $desk, string $message, int $id): void
{
    q("INSERT INTO notifications (user_id, message, target) SELECT id, ?, ? FROM users WHERE is_active = 1 AND role IN ('admin', ?)", [$message, '/hr-requests?id=' . $id, $desk]);
}

/** The worker asks. Returns [id, refusal]. */
function hr_request_create(array $in): array
{
    $cid = hr_self();
    if (! $cid) {
        return [0, t('Only a worker sends an HR request, from their own account.')];
    }
    $kind = (string) ($in['kind'] ?? '');
    $subject = trim((string) ($in['subject'] ?? ''));
    $detail = trim((string) ($in['detail'] ?? ''));
    if (! isset(hr_request_kinds()[$kind])) {
        return [0, t('Choose what the request is about.')];
    }
    if (mb_strlen($subject) < 3 || mb_strlen($subject) > 190 || mb_strlen($detail) < 10) {
        return [0, t('Give a subject, and say what you need in at least 10 characters.')];
    }
    if ((int) val("SELECT COUNT(*) FROM hr_requests WHERE candidate_id = ? AND status IN ('open','answered')", [$cid]) >= 10) {
        return [0, t('You have ten requests open. Close the ones that are settled first.')];
    }
    $ref = hr_request_next_reference();
    $desk = hr_request_desk($kind);
    q('INSERT INTO hr_requests (reference, candidate_id, user_id, kind, desk, subject, detail, include_pay) VALUES (?,?,?,?,?,?,?,?)',
      [$ref, $cid, uid(), $kind, $desk, $subject, mb_substr($detail, 0, 5000), $kind === 'employment_letter' && ! empty($in['include_pay']) ? 1 : 0]);
    $id = (int) db()->lastInsertId();
    hr_request_notify_desk($desk, 'New HR request ' . $ref . ': ' . $subject, $id);

    return [$id, null];
}

/** A reply from either side. Staff answering marks it answered; the worker replying reopens it. */
function hr_request_reply(array $r, string $message): ?string
{
    $worker = hr_self() === (int) $r['candidate_id'];
    if (! $worker && ! hr_request_staff($r)) {
        return t('Only the worker, or the desk it went to, replies.');
    }
    if (in_array($r['status'], ['closed', 'withdrawn'], true)) {
        return t('That request is closed.');
    }
    if (mb_strlen(trim($message)) < 2) {
        return t('Write the reply.');
    }
    q('INSERT INTO hr_request_replies (request_id, by_worker, message, user_id) VALUES (?,?,?,?)', [(int) $r['id'], $worker ? 1 : 0, mb_substr(trim($message), 0, 5000), uid() ?: null]);
    q('UPDATE hr_requests SET status = ? WHERE id = ?', [$worker ? 'open' : 'answered', (int) $r['id']]);
    if ($worker) {
        hr_request_notify_desk((string) $r['desk'], 'Reply on HR request ' . $r['reference'], (int) $r['id']);
    } elseif ($r['user_id']) {
        q('INSERT INTO notifications (user_id, message, target) VALUES (?,?,?)', [(int) $r['user_id'], 'HR answered your request ' . $r['reference'], '/hr-requests?id=' . (int) $r['id']]);
    }

    return null;
}

function hr_request_close(array $r, bool $withdraw): ?string
{
    $worker = hr_self() === (int) $r['candidate_id'];
    if (! $worker && ! hr_request_staff($r)) {
        return t('Only the worker, or the desk it went to, closes a request.');
    }
    if (in_array($r['status'], ['closed', 'withdrawn'], true)) {
        return t('That request is closed.');
    }
    if ($withdraw && ! $worker) {
        return t('Only the worker withdraws their request.');
    }
    q('UPDATE hr_requests SET status = ?, closed_at = NOW() WHERE id = ?', [$withdraw ? 'withdrawn' : 'closed', (int) $r['id']]);

    return null;
}

/** The text of an employment letter for this person, as of today. */
function employment_letter_text(int $candidateId, bool $includePay, string $brand): string
{
    $c = row('SELECT full_name FROM candidates WHERE id = ?', [$candidateId]);
    $first = row("SELECT MIN(start_date) AS since FROM placements WHERE candidate_id = ? AND status <> 'cancelled'", [$candidateId]);
    $current = row("SELECT p.*, j.title, d.trade FROM placements p JOIN jobs j ON j.id = p.job_id LEFT JOIN assignment_details d ON d.placement_id = p.id
                    WHERE p.candidate_id = ? AND p.status <> 'cancelled' ORDER BY FIELD(p.status, 'on_site','travelling','confirmed','offered','completed'), p.start_date DESC LIMIT 1", [$candidateId]);
    $type = (string) (val('SELECT employment_type FROM employee_profiles WHERE candidate_id = ?', [$candidateId]) ?: 'hourly');
    require_once __DIR__ . '/classification.php';

    $lines = [
        t('To whom it may concern,'),
        '',
        t(':name has worked with :brand since :since.', ['name' => $c['full_name'], 'brand' => $brand, 'since' => $first['since'] ? d((string) $first['since']) : '—']),
    ];
    if ($current) {
        $lines[] = in_array($current['status'], ['completed'], true)
            ? t('Their most recent assignment, as :trade on :project, ended on :end.', ['trade' => $current['trade'] ?: t('crew member'), 'project' => $current['title'], 'end' => $current['end_date'] ? d((string) $current['end_date']) : '—'])
            : t('They are currently assigned as :trade on :project.', ['trade' => $current['trade'] ?: t('crew member'), 'project' => $current['title']]);
    }
    $lines[] = t('Kind of employment: :type.', ['type' => employment_types()[$type] ?? $type]);
    if ($includePay && $current && $current['pay_rate'] !== null) {
        $lines[] = t('Their current rate of pay is :rate an hour.', ['rate' => money($current['pay_rate'])]);
    }
    $lines[] = '';
    $lines[] = t('This letter states facts held in our records on :date, at the request of the person named. It is not a reference.', ['date' => d(date('Y-m-d'))]);

    return implode("\n", $lines);
}

/** Issue the letter on an employment-letter request. Recruiting desk. */
function hr_request_issue_letter(array $r, string $brand): ?string
{
    if ($r['kind'] !== 'employment_letter') {
        return t('Only an employment letter request is answered with a letter.');
    }
    if (! hr_request_staff($r)) {
        return t('Only the worker, or the desk it went to, replies.');
    }
    if (in_array($r['status'], ['closed', 'withdrawn'], true)) {
        return t('That request is closed.');
    }
    if ($r['letter_text'] !== null) {
        return t('The letter is already issued.');
    }
    $text = employment_letter_text((int) $r['candidate_id'], (int) $r['include_pay'] === 1, $brand);
    q("UPDATE hr_requests SET letter_text = ?, letter_sha256 = ?, letter_issued_by = ?, letter_issued_at = NOW(), status = 'answered' WHERE id = ?",
      [$text, hash('sha256', $text), uid() ?: null, (int) $r['id']]);
    q('INSERT INTO hr_request_replies (request_id, by_worker, message, user_id) VALUES (?,0,?,?)', [(int) $r['id'], 'Letter issued.', uid() ?: null]);
    if ($r['user_id']) {
        q('INSERT INTO notifications (user_id, message, target) VALUES (?,?,?)', [(int) $r['user_id'], 'Your employment letter is ready', '/hr-requests?id=' . (int) $r['id']]);
    }

    return null;
}

/** What waits on the worker, and where everything is: the self-service home. */
function self_service_home(int $cid): array
{
    $count = static fn(string $sql, array $args): int => (int) val($sql, $args);
    $safe = static function (callable $f, $default) {
        try { return $f(); } catch (Throwable $e) { return $default; }
    };
    $uidW = uid();

    return [
        'name'       => (string) val('SELECT full_name FROM candidates WHERE id = ?', [$cid]),
        'details'    => $count("SELECT COUNT(*) FROM profile_change_requests WHERE candidate_id = ? AND status = 'pending'", [$cid])
                      + $count("SELECT COUNT(*) FROM worker_bank_change_requests WHERE candidate_id = ? AND status = 'pending'", [$cid]),
        'timeoff'    => $count("SELECT COUNT(*) FROM time_off_requests t JOIN placements p ON p.id = t.placement_id WHERE p.candidate_id = ? AND t.status = 'pending'", [$cid]),
        'leave'      => $safe(function () use ($cid) {
            $out = [];
            foreach (leave_types() as $slug => $type) {
                $b = leave_balance($cid, (string) $slug);
                if ($b['left'] !== null) {
                    $out[] = ['label' => t((string) $type['label']), 'left' => $b['left']];
                }
            }
            return $out;
        }, []),
        'payslips'   => $count("SELECT COUNT(*) FROM payroll_runs r WHERE r.status IN ('approved','locked') AND EXISTS (SELECT 1 FROM timesheets t JOIN placements p ON p.id = t.placement_id
                                 WHERE p.candidate_id = ? AND t.week_ending = r.week_ending AND t.status IN ('approved','paid'))", [$cid]),
        'benefits'   => $safe(fn() => rows("SELECT p.name FROM benefit_enrollments e JOIN benefit_plans p ON p.id = e.plan_id WHERE e.candidate_id = ? AND e.status = 'enrolled' AND (e.ends_on IS NULL OR e.ends_on >= CURDATE())", [$cid]), []),
        'self_review'=> $safe(fn() => $count("SELECT COUNT(*) FROM appraisals WHERE candidate_id = ? AND status = 'self_review'", [$cid]), 0),
        'goals'      => $safe(fn() => $count("SELECT COUNT(*) FROM performance_goals WHERE candidate_id = ? AND status = 'open'", [$cid]), 0),
        'to_ack'     => $safe(fn() => $count("SELECT COUNT(*) FROM disciplinary_cases WHERE candidate_id = ? AND status = 'decided' AND acknowledged_at IS NULL", [$cid]), 0),
        'answered'   => $count("SELECT COUNT(*) FROM hr_requests WHERE candidate_id = ? AND status = 'answered'", [$cid]),
        'requests'   => rows("SELECT * FROM hr_requests WHERE candidate_id = ? ORDER BY FIELD(status,'answered','open','closed','withdrawn'), id DESC LIMIT 20", [$cid]),
        'unread'     => $count('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$uidW]),
    ];
}
