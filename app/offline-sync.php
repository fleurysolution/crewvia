<?php
/**
 * Offline attendance (P5-M06b): trusted phones and what they send.
 *
 * A worker or a supervisor, signed in, may trust the phone they are on.
 * The phone then gets a random token in an HttpOnly, SameSite=Strict cookie
 * (scripts cannot read it); only its hash is stored. The grant does two
 * things and nothing else, under /sync:
 *   - give the phone what it needs to work without signal: a worker's own
 *     live assignments; a supervisor's crew (names, for 24 hours at most);
 *     the screen's words in the person's language;
 *   - take what was typed without signal: a worker's hours for a day, a
 *     supervisor's gate mark for one of their crew.
 * It opens no page, record, document or pay.
 *
 * A grant ends at its expiry (14 days, config device_trust_days), when the
 * person or an administrator removes it, at sign-out on that phone, and
 * when the account's sign-in version moves (password change, two-step
 * change, "sign out everywhere").
 *
 * The server decides. Each entry is checked by the rules of the screens,
 * and more strictly where a phone could be out of date:
 *   hours  - the worker's own live assignment, a day within it and not in
 *            the future, 0 to 24 hours; an approved day is refused (ask
 *            for a correction); a day rejected after the entry was typed
 *            is refused as out of date; a week frozen for pay is refused;
 *            a submitted day is replaced, as on the screen.
 *   gate   - a supervisor, for a live assignment they supervise, today or
 *            up to 7 days back; a mark made later than this one (by anyone)
 *            stands, and this one is refused as out of date. Present puts
 *            the person on site, as the gate screen does.
 * Every entry, saved or refused, is logged with when it was typed and when
 * it arrived. The same entry id sent again gets the same answer and is
 * written once.
 */

declare(strict_types=1);

const OS_COOKIE = 'crewvia_device';
const OS_GATE_DAYS_BACK = 7;
const OS_CREW_HOURS = 24;
const OS_MAX_ENTRIES = 200;

function os_trust_days(): int
{
    global $config;
    $d = (int) ($config['device_trust_days'] ?? 14);

    return $d >= 1 && $d <= 90 ? $d : 14;
}

function os_secure(): bool
{
    global $config;

    return (($_SERVER['HTTPS'] ?? '') === 'on') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || ! empty($config['secure_cookies']);
}

function os_set_cookie(string $value, int $expires): void
{
    setcookie(OS_COOKIE, $value, ['expires' => $expires, 'path' => '/', 'secure' => os_secure(), 'httponly' => true, 'samesite' => 'Strict']);
}

function os_session_version(int $userId): int
{
    return (int) val('SELECT session_version FROM account_security WHERE user_id = ?', [$userId]);
}

/** Who may trust a phone: a worker, or a supervisor. */
function os_may_trust(array $u): bool
{
    return ($u['role'] ?? '') === 'supervisor' || (bool) val('SELECT COUNT(*) FROM worker_accounts WHERE user_id = ?', [(int) $u['id']]);
}

/** Trust this phone for the signed-in person. Returns the grant id. */
function os_grant_create(array $u, string $userAgent): int
{
    $token = bin2hex(random_bytes(32));
    $expires = time() + os_trust_days() * 86400;
    $label = mb_substr(trim(preg_replace('/\s+/', ' ', $userAgent)) ?: 'Phone', 0, 120);
    // One phone, one grant: trusting it again replaces what it had.
    os_revoke_cookie_grant(null, 'replaced');
    q('INSERT INTO device_grants (user_id, token_hash, label, session_version, expires_at) VALUES (?,?,?,?,?)',
      [(int) $u['id'], hash('sha256', $token), $label, os_session_version((int) $u['id']), date('Y-m-d H:i:s', $expires)]);
    $id = (int) db()->lastInsertId();
    os_set_cookie($token, $expires);
    log_activity('trusted a phone for offline attendance', 'device_grant', $id, $label);

    return $id;
}

/** The grant this request's cookie carries, if it is still good. */
function os_current_grant(): ?array
{
    $token = (string) ($_COOKIE[OS_COOKIE] ?? '');
    if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $g = row("SELECT g.*, u.name, u.role, u.locale, u.is_active FROM device_grants g JOIN users u ON u.id = g.user_id
              WHERE g.token_hash = ? AND g.revoked_at IS NULL AND g.expires_at > NOW()", [hash('sha256', $token)]);
    if (! $g || ! (int) $g['is_active'] || (int) $g['session_version'] !== os_session_version((int) $g['user_id'])) {
        return null;
    }

    return $g;
}

function os_revoke(int $grantId, ?int $by, string $reason): bool
{
    return q('UPDATE device_grants SET revoked_at = NOW(), revoked_by = ?, revoke_reason = ? WHERE id = ? AND revoked_at IS NULL',
             [$by ?: null, mb_substr($reason, 0, 40), $grantId])->rowCount() > 0;
}

/** End the grant this request's cookie carries, whatever its state, and clear the cookie. */
function os_revoke_cookie_grant(?int $by, string $reason): bool
{
    $token = (string) ($_COOKIE[OS_COOKIE] ?? '');
    if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }
    $id = (int) val('SELECT id FROM device_grants WHERE token_hash = ?', [hash('sha256', $token)]);
    os_set_cookie('', time() - 3600);
    unset($_COOKIE[OS_COOKIE]);

    return $id ? os_revoke($id, $by, $reason) || true : false;
}

function os_grants_for(?int $userId): array
{
    $sql = 'SELECT g.id, g.user_id, g.label, g.created_at, g.expires_at, g.last_used_at, g.revoked_at, g.revoke_reason, u.name
            FROM device_grants g JOIN users u ON u.id = g.user_id';

    return $userId
        ? rows($sql . ' WHERE g.user_id = ? ORDER BY g.id DESC LIMIT 50', [$userId])
        : rows($sql . ' WHERE g.revoked_at IS NULL AND g.expires_at > NOW() ORDER BY u.name, g.id DESC LIMIT 500');
}

function os_grant_state(array $g): string
{
    if ($g['revoked_at'] !== null) {
        return 'ended';
    }

    return (string) $g['expires_at'] <= date('Y-m-d H:i:s') ? 'expired' : 'active';
}

// ── what the phone keeps ────────────────────────────────────────────────

function os_is_worker(int $userId): bool
{
    return (bool) val('SELECT COUNT(*) FROM worker_accounts WHERE user_id = ?', [$userId]);
}

/** The words of the offline screen, in the person's language. */
function os_labels(): array
{
    return [
        'title' => t('Attendance without signal'), 'intro' => t('Typed here, it is kept on this phone and sent when there is a connection. The office decides, as for anything typed online.'),
        'hours' => t('Hours'), 'assignment' => t('Assignment'), 'date' => t('Date'), 'keep' => t('Keep it to send'),
        'gate' => t('Gate'), 'present' => t('Present'), 'absent' => t('Absent'), 'waiting' => t('Waiting to be sent'), 'sent' => t('Answered'),
        'send' => t('Send now'), 'nothing' => t('Nothing waiting.'), 'offline' => t('No connection: kept on this phone.'),
        'crew_expired' => t('The crew list is more than a day old and was removed from this phone. Open Crewvia with a connection to get it again.'),
        'untrusted' => t('This phone is no longer trusted. Sign in to Crewvia and trust it again.'),
        'saved' => t('Saved'), 'replaced' => t('Replaced'), 'refused' => t('Refused'), 'open' => t('Open Crewvia'),
        'kept' => t('Kept. It goes when there is a connection.'), 'bad_hours' => t('Hours are 0 to 24.'),
    ];
}

/** What a trusted phone may keep: nothing about anyone else, except a supervisor's crew for a day. */
function os_bootstrap(array $g): array
{
    $uid = (int) $g['user_id'];
    $out = ['user' => ['name' => (string) $g['name']], 'trusted_until' => (string) $g['expires_at'], 'labels' => os_labels(), 'today' => date('Y-m-d')];
    if (os_is_worker($uid)) {
        $out['assignments'] = rows("SELECT p.id AS placement_id, j.title AS project, p.start_date, p.end_date FROM placements p
                                    JOIN worker_accounts w ON w.candidate_id = p.candidate_id JOIN jobs j ON j.id = p.job_id
                                    WHERE w.user_id = ? AND p.status IN ('confirmed','travelling','on_site') ORDER BY j.title", [$uid]);
    }
    if ((string) $g['role'] === 'supervisor') {
        $out['crew'] = rows("SELECT p.id AS placement_id, c.full_name AS name, j.title AS project FROM placements p
                             JOIN assignment_details d ON d.placement_id = p.id JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id
                             WHERE d.supervisor_id = ? AND p.status IN ('confirmed','travelling','on_site') ORDER BY j.title, c.full_name", [$uid]);
        $out['crew_until'] = date('Y-m-d H:i:s', time() + OS_CREW_HOURS * 3600);
    }

    return $out;
}

// ── what the phone sends ────────────────────────────────────────────────

/** @return array{0:string,1:?string,2:?string,3:?int} outcome, reason code, message, attendance id */
function os_apply_hours(array $g, array $e, string $captured): array
{
    require_once __DIR__ . '/attendance-controls.php';
    $uid = (int) $g['user_id'];
    $pid = (int) ($e['placement_id'] ?? 0);
    $date = (string) ($e['work_date'] ?? '');
    $hours = $e['hours'] ?? null;
    if (! os_is_worker($uid)) { return ['refused', 'not_worker', t('Hours are sent by the worker.'), null]; }
    $p = row("SELECT p.* FROM placements p JOIN worker_accounts w ON w.candidate_id = p.candidate_id
              WHERE p.id = ? AND w.user_id = ? AND p.status IN ('confirmed','travelling','on_site')", [$pid, $uid]);
    if (! $p) { return ['refused', 'assignment', t('That assignment is not one of yours, or it has ended.'), null]; }
    if (! valid_date($date) || $date > date('Y-m-d')) { return ['refused', 'date', t('Give a working day, today or earlier.'), null]; }
    if (($p['start_date'] && $date < $p['start_date']) || ($p['end_date'] && $date > $p['end_date'])) {
        return ['refused', 'date', t('That day is outside the assignment.'), null];
    }
    if (! is_numeric($hours) || ! is_finite((float) $hours) || (float) $hours < 0 || (float) $hours > 24) {
        return ['refused', 'hours', t('Hours are 0 to 24.'), null];
    }
    if (attendance_week_frozen($pid, $date)) {
        return ['refused', 'frozen', t('That week is already approved for pay. Ask the office for a correction.'), null];
    }
    $existing = row('SELECT * FROM attendance_records WHERE placement_id = ? AND work_date = ? FOR UPDATE', [$pid, $date]);
    if ($existing && $existing['status'] === 'approved') {
        return ['refused', 'approved', t('That day is already approved. Ask for a correction.'), (int) $existing['id']];
    }
    if ($existing && $existing['status'] === 'rejected' && $existing['reviewed_at'] !== null && (string) $existing['reviewed_at'] >= $captured) {
        return ['refused', 'stale', t('That day was rejected after you typed this. Look at the reason and enter it again.'), (int) $existing['id']];
    }
    if ($existing) {
        q("UPDATE attendance_records SET hours = ?, status = 'submitted', submitted_by = ?, reviewed_by = NULL, reviewed_at = NULL WHERE id = ?",
          [round((float) $hours, 2), $uid, (int) $existing['id']]);

        return ['replaced', null, null, (int) $existing['id']];
    }
    q("INSERT INTO attendance_records (placement_id, work_date, hours, submitted_by) VALUES (?,?,?,?)", [$pid, $date, round((float) $hours, 2), $uid]);

    return ['saved', null, null, (int) db()->lastInsertId()];
}

function os_apply_gate(array $g, array $e, string $captured): array
{
    $uid = (int) $g['user_id'];
    $pid = (int) ($e['placement_id'] ?? 0);
    $date = (string) ($e['work_date'] ?? '');
    $present = (int) (! empty($e['present']));
    if ((string) $g['role'] !== 'supervisor') { return ['refused', 'not_supervisor', t('Gate marks are sent by a supervisor.'), null]; }
    $p = row("SELECT p.id FROM placements p JOIN assignment_details d ON d.placement_id = p.id
              WHERE p.id = ? AND d.supervisor_id = ? AND p.status IN ('confirmed','travelling','on_site')", [$pid, $uid]);
    if (! $p) { return ['refused', 'assignment', t('That person is not in your crew, or their assignment has ended.'), null]; }
    if (! valid_date($date) || $date > date('Y-m-d') || $date < date('Y-m-d', strtotime('-' . OS_GATE_DAYS_BACK . ' days'))) {
        return ['refused', 'date', t('A gate mark is for today or the last 7 days.'), null];
    }
    $existing = row('SELECT * FROM assignment_checkins WHERE placement_id = ? AND work_date = ? FOR UPDATE', [$pid, $date]);
    if ($existing && (string) $existing['marked_at'] > $captured) {
        return ['refused', 'stale', t('Someone marked this person later than you did; that mark stands.'), null];
    }
    q('INSERT INTO assignment_checkins (placement_id, work_date, present, marked_by, marked_at) VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE present = VALUES(present), marked_by = VALUES(marked_by), marked_at = VALUES(marked_at)',
      [$pid, $date, $present, $uid, $captured]);
    if ($present) {
        q("UPDATE placements SET status = 'on_site' WHERE id = ? AND status IN ('confirmed','travelling')", [$pid]);
    }

    return [$existing ? 'replaced' : 'saved', null, null, null];
}

/**
 * One entry from the phone. The answer is logged; the same entry id again
 * gets the same answer.
 *
 * @return array{id:string,outcome:string,reason:?string,message:?string,repeat:bool}
 */
function os_apply(array $g, array $e): array
{
    $entryId = (string) ($e['id'] ?? '');
    if (! preg_match('/^[A-Za-z0-9-]{8,64}$/', $entryId)) {
        return ['id' => mb_substr($entryId, 0, 64), 'outcome' => 'refused', 'reason' => 'id', 'message' => t('That entry has no usable id.'), 'repeat' => false];
    }
    $uid = (int) $g['user_id'];
    $answer = static fn(array $r, bool $repeat) => ['id' => $entryId, 'outcome' => (string) $r['outcome'], 'reason' => $r['reason'], 'message' => $r['message'], 'repeat' => $repeat];
    $seen = row('SELECT outcome, reason, message FROM attendance_sync_log WHERE user_id = ? AND entry_id = ?', [$uid, $entryId]);
    if ($seen) {
        return $answer($seen, true);
    }
    $kind = (string) ($e['kind'] ?? '');
    // When it was typed, by the phone's clock, never later than now.
    $ts = strtotime((string) ($e['captured_at'] ?? '')) ?: time();
    $captured = date('Y-m-d H:i:s', min($ts, time()));
    db()->beginTransaction();
    try {
        [$outcome, $reason, $message, $attendanceId] = match ($kind) {
            'hours' => os_apply_hours($g, $e, $captured),
            'gate'  => os_apply_gate($g, $e, $captured),
            default => ['refused', 'kind', t('Unknown entry.'), null],
        };
        q('INSERT INTO attendance_sync_log (user_id, device_id, entry_id, kind, placement_id, work_date, hours, present, captured_at, outcome, reason, message, attendance_id)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$uid, (int) $g['id'], $entryId, in_array($kind, ['hours', 'gate'], true) ? $kind : 'hours', ((int) ($e['placement_id'] ?? 0)) ?: null,
           valid_date((string) ($e['work_date'] ?? '')) ? (string) $e['work_date'] : null,
           $kind === 'hours' && is_numeric($e['hours'] ?? null) && abs((float) $e['hours']) < 1000 ? round((float) $e['hours'], 2) : null,
           $kind === 'gate' ? (int) (! empty($e['present'])) : null, $captured, $outcome, $reason, $message !== null ? mb_substr($message, 0, 255) : null, $attendanceId]);
        db()->commit();
    } catch (PDOException $ex) {
        db()->rollBack();
        // The same entry arriving twice at once: the first one's answer stands.
        $seen = row('SELECT outcome, reason, message FROM attendance_sync_log WHERE user_id = ? AND entry_id = ?', [$uid, $entryId]);
        if ($seen) {
            return $answer($seen, true);
        }
        throw $ex;
    }
    if ($outcome !== 'refused') {
        q('INSERT INTO activity (user_id, action, entity, entity_id, detail) VALUES (?,?,?,?,?)',
          [$uid, $kind === 'gate' ? 'sent a gate mark from a trusted phone' : 'sent hours from a trusted phone', 'placement', (int) $e['placement_id'],
           mb_substr((string) $e['work_date'] . ' · typed ' . $captured, 0, 500)]);
    }

    return $answer(['outcome' => $outcome, 'reason' => $reason, 'message' => $message], false);
}
