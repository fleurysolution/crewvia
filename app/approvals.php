<?php
/**
 * Approval chains, ported from BPMS247's estimate approvals.
 *
 * A chain is an ordered list of steps; each step names a desk and says
 * whether it must wait its turn. Submitting a record copies the chain into a
 * set of requests, one per step, and the record waits until every required
 * step has approved. One rejection ends it.
 *
 * Two things are deliberately kept from the original. The gating rule: a
 * sequential step is blocked while any earlier required sequential step is
 * still pending, a parallel step never is. And the copy: each request holds
 * its own label, desk and gate rather than joining back to the chain, so
 * editing a chain next month cannot rewrite what was asked of the people who
 * already decided.
 *
 * What is new is that a chain names what it applies to. BPMS approves one
 * kind of thing; an agency approves a client order, a person going to site, a
 * week of hours and a reimbursement, with different people on each.
 */

declare(strict_types=1);

/** What a chain can govern, and what each one is called on screen. */
function approval_subjects(): array
{
    return [
        'requisition' => 'Client order before it is advertised',
        'offer'       => 'Offer before it reaches the candidate',
        'placement'   => 'Person before they are sent to site',
        'timesheet'   => 'Week of hours before it is paid',
        'expense'     => 'Reimbursement before it is paid',
    ];
}

/** Whether the tables are there yet. An install that has not upgraded is not broken by this. */
function approvals_available(): bool
{
    static $there = null;

    if ($there === null) {
        $there = (int) val("SELECT COUNT(*) FROM information_schema.tables
                            WHERE table_schema = DATABASE()
                              AND table_name = 'approval_requests'") > 0;
    }

    return $there;
}

/** The active default chain for a kind of record, with its steps. */
function approval_chain_for(string $subject): ?array
{
    if (! approvals_available()) {
        return null;
    }

    $chain = row('SELECT * FROM approval_chains
                  WHERE applies_to = ? AND is_active = 1
                  ORDER BY is_default DESC, id ASC LIMIT 1', [$subject]);

    if (! $chain) {
        return null;
    }

    $chain['steps'] = rows('SELECT * FROM approval_chain_steps
                            WHERE chain_id = ? ORDER BY step_order, id', [$chain['id']]);

    return $chain['steps'] ? $chain : null;
}

/** Has this record already been submitted? */
function approval_started(string $subject, int $id): bool
{
    return approvals_available()
        && (int) val("SELECT COUNT(*) FROM approval_requests
                      WHERE subject_type = ? AND subject_id = ? AND status <> 'cancelled'",
                     [$subject, $id]) > 0;
}

/**
 * Submit a record into its chain.
 *
 * @return array{ok:bool,message:string}
 */
function approval_start(string $subject, int $id, string $label, ?int $jobId = null): array
{
    if (! approvals_available()) {
        return ['ok' => false, 'message' => 'Approvals are not set up on this workspace.'];
    }

    if (approval_started($subject, $id)) {
        return ['ok' => false, 'message' => 'This is already waiting for approval.'];
    }

    $chain = approval_chain_for($subject);

    if (! $chain) {
        return ['ok' => false, 'message' => 'No approval chain is configured for this.'];
    }

    db()->beginTransaction();

    foreach ($chain['steps'] as $step) {
        q('INSERT INTO approval_requests
             (chain_id, subject_type, subject_id, subject_label, job_id, step_id,
              step_order, label, role_slug, gate_type, is_required, requested_by)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
          [$chain['id'], $subject, $id, mb_substr($label, 0, 190), $jobId, $step['id'],
           $step['step_order'], $step['label'], $step['role_slug'],
           $step['gate_type'], $step['is_required'], uid()]);
    }

    db()->commit();

    approval_notify($subject, $id);

    return ['ok' => true, 'message' => 'Submitted for approval.'];
}

/**
 * The steps that can be decided right now.
 *
 * BPMS's rule, unchanged: a parallel step is always actionable; a sequential
 * one waits for every earlier required sequential step.
 */
function approval_actionable(string $subject, int $id): array
{
    $all = rows('SELECT * FROM approval_requests
                 WHERE subject_type = ? AND subject_id = ?
                 ORDER BY step_order, id', [$subject, $id]);

    $open = [];

    foreach ($all as $r) {
        if ($r['status'] !== 'pending') {
            continue;
        }

        if ($r['gate_type'] === 'parallel') {
            $open[] = $r;
            continue;
        }

        $blocked = false;

        foreach ($all as $earlier) {
            if ((int) $earlier['step_order'] < (int) $r['step_order']
                && $earlier['gate_type'] === 'sequential'
                && (int) $earlier['is_required'] === 1
                && $earlier['status'] === 'pending') {
                $blocked = true;
                break;
            }
        }

        if (! $blocked) {
            $open[] = $r;
        }
    }

    return $open;
}

/** Where a record stands: none, pending, approved or rejected. */
function approval_state(string $subject, int $id): string
{
    if (! approvals_available()) {
        return 'none';
    }

    $rows = rows("SELECT status, is_required FROM approval_requests
                  WHERE subject_type = ? AND subject_id = ? AND status <> 'cancelled'",
                 [$subject, $id]);

    if (! $rows) {
        return 'none';
    }

    foreach ($rows as $r) {
        if ($r['status'] === 'rejected') {
            return 'rejected';
        }
    }

    foreach ($rows as $r) {
        if ($r['status'] === 'pending' && (int) $r['is_required'] === 1) {
            return 'pending';
        }
    }

    return 'approved';
}

/**
 * Record one decision.
 *
 * @return array{ok:bool,message:string}
 */
function approval_decide(int $requestId, string $decision, string $comments = ''): array
{
    if (! in_array($decision, ['approved', 'rejected'], true)) {
        return ['ok' => false, 'message' => 'Approve or reject; nothing else.'];
    }

    $r = row('SELECT * FROM approval_requests WHERE id = ?', [$requestId]);

    if (! $r || $r['status'] !== 'pending') {
        return ['ok' => false, 'message' => 'That step has already been decided.'];
    }

    // The desk named on the step, or an administrator.
    if (! can($r['role_slug']) && ! can('admin')) {
        return ['ok' => false, 'message' => 'This step belongs to another desk.'];
    }

    // Order is part of the rule, not a suggestion.
    $actionable = approval_actionable($r['subject_type'], (int) $r['subject_id']);
    $isOpen     = false;

    foreach ($actionable as $a) {
        if ((int) $a['id'] === $requestId) {
            $isOpen = true;
            break;
        }
    }

    if (! $isOpen) {
        return ['ok' => false, 'message' => 'An earlier step has to be decided first.'];
    }

    q("UPDATE approval_requests
       SET status = ?, decided_by = ?, decided_at = NOW(), comments = ?
       WHERE id = ? AND status = 'pending'",
      [$decision, uid(), mb_substr(trim($comments), 0, 500) ?: null, $requestId]);

    // A rejection ends the whole thing; nobody should be asked to decide a
    // step on a record that has already been turned down.
    if ($decision === 'rejected') {
        q("UPDATE approval_requests SET status = 'cancelled'
           WHERE subject_type = ? AND subject_id = ? AND status = 'pending'",
          [$r['subject_type'], (int) $r['subject_id']]);
    }

    log_activity('decided an approval step', 'approval_request', $requestId,
                 $r['subject_type'] . ' ' . $r['subject_id'] . ' ' . $decision);

    approval_notify((string) $r['subject_type'], (int) $r['subject_id']);

    return ['ok' => true, 'message' => $decision === 'approved'
        ? 'Approved.'
        : 'Rejected. The rest of the chain is closed.'];
}

/** Everything this person can decide right now, across every record. */
function approval_queue(): array
{
    if (! approvals_available() || ! user()) {
        return [];
    }

    $waiting = rows("SELECT DISTINCT subject_type, subject_id FROM approval_requests
                     WHERE status = 'pending' LIMIT 500");

    $mine = [];

    foreach ($waiting as $w) {
        foreach (approval_actionable((string) $w['subject_type'], (int) $w['subject_id']) as $r) {
            if (can($r['role_slug']) || can('admin')) {
                $mine[] = $r;
            }
        }
    }

    return $mine;
}

/**
 * Tell whoever the next step belongs to - once.
 *
 * Every decision re-opens the question of what is actionable, so without
 * a record of what has already been announced a parallel step collects a
 * fresh message each time anybody else decides anything.
 */
function approval_notify(string $subject, int $id): void
{
    foreach (approval_actionable($subject, $id) as $r) {
        if (! empty($r['notified_at'])) {
            continue;
        }

        try {
            q("INSERT INTO notifications (user_id, message, target)
               SELECT id, ?, '/approvals' FROM users
               WHERE is_active = 1 AND (role = ? OR role = 'admin')",
              [$r['label'] . ': ' . $r['subject_label'], $r['role_slug']]);

            q('UPDATE approval_requests SET notified_at = NOW() WHERE id = ?', [$r['id']]);
        } catch (Throwable $e) {
            error_log('[approvals] could not notify for request ' . $r['id']);
        }
    }
}
