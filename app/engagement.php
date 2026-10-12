<?php
/**
 * Recognition and engagement (P5-M05): peer thanks, award programmes and
 * their nominations, points and a configurable rewards catalogue, and
 * feedback given or asked for. Engagement surveys are in app/surveys.php.
 *
 * What it does not duplicate (P2):
 *   - an award won by a worker is written into the HR record through
 *     recognition_add() (P2-M06), so recognition stays one register;
 *   - performance reviews, goals and disciplinary cases are untouched; a
 *     concern given as feedback is not a case, and says so;
 *   - a cash reward is paid as a payroll adjustment of kind "reward" in an
 *     open pay period, chosen by payroll, never typed in a second time;
 *   - a time-off reward, once given, adds its days to the worker's leave
 *     allowance for the year (leave_balance() in app/hr.php).
 *
 * Who does what:
 *   - everyone signed in except clients thanks colleagues, nominates where a
 *     programme allows it, asks for rewards with their points, and gives and
 *     asks for feedback;
 *   - recruiters and administrators run programmes, choose winners, keep
 *     the catalogue, decide and hand over rewards, grant points;
 *   - payroll (and administrators) pay cash rewards in an open period.
 *
 * A colleague, for a desk (staff), is anyone signed in except clients. For
 * a worker or a supervisor it is the people on the same live projects:
 * crew and their supervisors. That is who they can thank, nominate and give
 * feedback to, and whose public thanks they see.
 */

declare(strict_types=1);

const ENG_DEFAULT_DAILY_KUDOS = 10;

function eng_manager(): bool
{
    return can('recruiter');
}

/** @return array<string,string> */
function eng_eligibles(): array
{
    return ['everyone' => t('Everyone'), 'staff' => t('Staff'), 'workers' => t('Workers')];
}

/** @return array<string,string> */
function eng_periods(): array
{
    return ['month' => t('Every month'), 'quarter' => t('Every quarter'), 'year' => t('Every year'), 'once' => t('Once per person')];
}

/** @return array<string,string> */
function eng_reward_kinds(): array
{
    return ['gift_card' => t('Gift card'), 'merchandise' => t('Merchandise'), 'experience' => t('Experience'),
            'time_off' => t('Time off'), 'cash' => t('Cash, paid through payroll'), 'other' => t('Other')];
}

/** @return array<string,string> */
function eng_redemption_statuses(): array
{
    return ['requested' => t('Asked for'), 'approved' => t('Approved'), 'declined' => t('Declined'),
            'fulfilled' => t('Given'), 'cancelled' => t('Cancelled')];
}

/** @return array<string,string> */
function eng_nomination_statuses(): array
{
    return ['nominated' => t('Nominated'), 'won' => t('Won'), 'not_selected' => t('Not selected'), 'withdrawn' => t('Withdrawn')];
}

function eng_notify(int $userId, string $message, string $target): void
{
    q('INSERT INTO notifications (user_id, message, target) VALUES (?,?,?)', [$userId, mb_substr($message, 0, 255), $target]);
}

function eng_setting(string $key, int $default): int
{
    $v = val('SELECT setting_value FROM platform_settings WHERE setting_key = ?', [$key]);

    return $v === null || $v === false || ! ctype_digit((string) $v) ? $default : (int) $v;
}

function eng_settings_save(array $in): ?string
{
    $points = trim((string) ($in['kudos_points'] ?? ''));
    $daily = trim((string) ($in['kudos_daily_limit'] ?? ''));
    if (! ctype_digit($points) || (int) $points > 1000) { return t('Points for a thank-you are 0 to 1000.'); }
    if (! ctype_digit($daily) || (int) $daily < 1 || (int) $daily > 50) { return t('A person gives 1 to 50 thank-yous a day.'); }
    foreach (['kudos_points' => (int) $points, 'kudos_daily_limit' => (int) $daily] as $k => $v) {
        q('INSERT INTO platform_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$k, (string) $v]);
    }
    log_activity('changed the recognition settings', 'platform_settings', 0, 'kudos_points=' . (int) $points . ' kudos_daily_limit=' . (int) $daily);

    return null;
}

// ── people ──────────────────────────────────────────────────────────────

function eng_is_staff_role(string $role): bool
{
    return ! in_array($role, ['worker', 'supervisor', 'client'], true);
}

function eng_is_worker(int $userId): bool
{
    return (bool) val('SELECT COUNT(*) FROM worker_accounts WHERE user_id = ?', [$userId]);
}

/** An active person who takes part: anyone signed in but a client. */
function eng_person(int $userId): ?array
{
    $u = row("SELECT id, name, role FROM users WHERE id = ? AND is_active = 1 AND role <> 'client'", [$userId]);

    return $u ?: null;
}

function eng_eligible(string $eligible, int $userId): bool
{
    if (! eng_person($userId)) {
        return false;
    }

    return match ($eligible) {
        'workers' => eng_is_worker($userId),
        'staff'   => ! eng_is_worker($userId),
        default   => true,
    };
}

/**
 * The people this person works with, id => name, without themself.
 *
 * @return array<int,string>
 */
function eng_colleagues(int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    $me = eng_person($userId);
    if (! $me) {
        return $cache[$userId] = [];
    }
    $live = "p.status NOT IN ('completed','cancelled')";
    if (eng_is_staff_role((string) $me['role']) && ! eng_is_worker($userId)) {
        $list = rows("SELECT id, name FROM users WHERE is_active = 1 AND role <> 'client' AND id <> ? ORDER BY name", [$userId]);
    } else {
        $jobs = array_map('intval', array_column(rows("SELECT p.job_id FROM placements p JOIN worker_accounts w ON w.candidate_id = p.candidate_id WHERE w.user_id = ? AND $live
                                                       UNION SELECT p.job_id FROM placements p JOIN assignment_details d ON d.placement_id = p.id WHERE d.supervisor_id = ? AND $live",
                                                      [$userId, $userId]), 'job_id'));
        $list = [];
        if ($jobs) {
            $in = implode(',', $jobs);
            $list = rows("SELECT u.id, u.name FROM users u WHERE u.is_active = 1 AND u.role <> 'client' AND u.id <> ? AND u.id IN (
                            SELECT w.user_id FROM worker_accounts w JOIN placements p ON p.candidate_id = w.candidate_id WHERE p.job_id IN ($in) AND $live
                            UNION SELECT d.supervisor_id FROM assignment_details d JOIN placements p ON p.id = d.placement_id
                                  WHERE p.job_id IN ($in) AND $live AND d.supervisor_id IS NOT NULL)
                          ORDER BY u.name", [$userId]);
        }
    }
    $out = [];
    foreach ($list as $r) {
        $out[(int) $r['id']] = (string) $r['name'];
    }

    return $cache[$userId] = $out;
}

function eng_candidate(int $userId): int
{
    return (int) val('SELECT candidate_id FROM worker_accounts WHERE user_id = ?', [$userId]);
}

// ── points ──────────────────────────────────────────────────────────────

function eng_balance(int $userId): int
{
    return (int) val('SELECT COALESCE(SUM(points), 0) FROM reward_points WHERE user_id = ?', [$userId]);
}

function eng_points(int $userId, int $points, string $source, ?int $sourceId, ?string $note, ?int $by): void
{
    q('INSERT INTO reward_points (user_id, points, source, source_id, note, created_by) VALUES (?,?,?,?,?,?)',
      [$userId, $points, $source, $sourceId, $note !== null ? mb_substr($note, 0, 255) : null, $by ?: null]);
}

/** A manager adds points to somebody, with the reason. Never to themself. */
function eng_points_grant(int $userId, string $points, string $reason, int $by): ?string
{
    $reason = trim($reason);
    if (! eng_person($userId)) { return t('Choose who the points are for.'); }
    if ($userId === $by) { return t('Points are granted by someone else.'); }
    if (! ctype_digit($points) || (int) $points < 1 || (int) $points > 10000) { return t('Grant 1 to 10,000 points.'); }
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) { return t('Say why, in 5 to 255 characters.'); }
    eng_points($userId, (int) $points, 'grant', null, $reason, $by);
    eng_notify($userId, t(':n points for you: :why', ['n' => (int) $points, 'why' => $reason]), '/recognition#points');
    log_activity('granted points', 'user', $userId, (int) $points . ': ' . $reason);

    return null;
}

// ── thanks ──────────────────────────────────────────────────────────────

/**
 * Thank a colleague. Points, if the agency gives any for a thank-you, come
 * with the first thanks from the same person in a calendar month, so two
 * people cannot trade thanks for points.
 *
 * @return array{0:?string,1:int}
 */
function eng_kudos_give(int $from, int $to, string $message, bool $public): array
{
    $message = trim($message);
    if (! eng_person($from)) { return [t('Only someone who works here gives thanks.'), 0]; }
    if ($to === $from) { return [t('Thank someone else.'), 0]; }
    if (! isset(eng_colleagues($from)[$to])) { return [t('Choose one of the people you work with.'), 0]; }
    if (mb_strlen($message) < 3 || mb_strlen($message) > 500) { return [t('Say what for, in 3 to 500 characters.'), 0]; }
    $limit = eng_setting('kudos_daily_limit', ENG_DEFAULT_DAILY_KUDOS);
    if ((int) val('SELECT COUNT(*) FROM kudos WHERE from_user_id = ? AND created_at >= CURDATE()', [$from]) >= $limit) {
        return [t('That is :n thank-yous today, the most in a day.', ['n' => $limit]), 0];
    }
    $points = eng_setting('kudos_points', 0);
    if ($points > 0 && val("SELECT COUNT(*) FROM kudos WHERE from_user_id = ? AND to_user_id = ? AND points > 0
                            AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')", [$from, $to])) {
        $points = 0;
    }
    q('INSERT INTO kudos (from_user_id, to_user_id, message, is_public, points) VALUES (?,?,?,?,?)', [$from, $to, $message, $public ? 1 : 0, $points]);
    $id = (int) db()->lastInsertId();
    if ($points > 0) {
        eng_points($to, $points, 'kudos', $id, null, $from);
    }
    eng_notify($to, t(':name thanked you: :message', ['name' => (string) val('SELECT name FROM users WHERE id = ?', [$from]), 'message' => $message]), '/recognition');

    return [null, $id];
}

/** The thanks this person may see, newest first. */
function eng_wall(int $viewer, int $limit = 100): array
{
    $sql = 'SELECT k.*, f.name AS from_name, t.name AS to_name FROM kudos k JOIN users f ON f.id = k.from_user_id JOIN users t ON t.id = k.to_user_id WHERE ';
    $me = eng_person($viewer);
    if (! $me) {
        return [];
    }
    if (can('admin')) {
        return rows($sql . '1 ORDER BY k.id DESC LIMIT ' . $limit);
    }
    if (eng_is_staff_role((string) $me['role']) && ! eng_is_worker($viewer)) {
        return rows($sql . 'k.is_public = 1 OR k.from_user_id = ? OR k.to_user_id = ? ORDER BY k.id DESC LIMIT ' . $limit, [$viewer, $viewer]);
    }
    $ids = array_keys(eng_colleagues($viewer));
    $in = $ids ? implode(',', array_map('intval', $ids)) : '0';

    return rows($sql . "k.from_user_id = ? OR k.to_user_id = ? OR (k.is_public = 1 AND k.to_user_id IN ($in)) ORDER BY k.id DESC LIMIT " . $limit, [$viewer, $viewer]);
}

// ── award programmes ────────────────────────────────────────────────────

function eng_period_key(string $period, ?string $date = null): string
{
    $ts = strtotime($date ?? 'today');

    return match ($period) {
        'month'   => date('Y-m', $ts),
        'quarter' => date('Y', $ts) . '-Q' . (intdiv((int) date('n', $ts) - 1, 3) + 1),
        'year'    => date('Y', $ts),
        default   => 'any',
    };
}

function eng_period_label(string $key): string
{
    return $key === 'any' ? t('Once per person') : $key;
}

/** @return array{0:?string,1:int} */
function eng_program_save(array $in, int $by, int $id = 0): array
{
    $name = trim((string) ($in['name'] ?? ''));
    $description = trim((string) ($in['description'] ?? ''));
    $period = (string) ($in['period'] ?? '');
    $eligible = (string) ($in['eligible'] ?? '');
    $nominatedBy = (string) ($in['nominated_by'] ?? '');
    $points = trim((string) ($in['points'] ?? '0'));
    if (mb_strlen($name) < 3 || mb_strlen($name) > 190) { return [t('Give the award a name of 3 to 190 characters.'), 0]; }
    if (mb_strlen($description) > 1000) { return [t('A field is too long.'), 0]; }
    if (! isset(eng_periods()[$period])) { return [t('Say how often it is given.'), 0]; }
    if (! isset(eng_eligibles()[$eligible])) { return [t('Say who can win it.'), 0]; }
    if (! in_array($nominatedBy, ['staff', 'everyone'], true)) { return [t('Say who can nominate.'), 0]; }
    if ($points === '' ) { $points = '0'; }
    if (! ctype_digit($points) || (int) $points > 100000) { return [t('An award carries 0 to 100,000 points.'), 0]; }
    $values = [$name, $description ?: null, $period, $eligible, $nominatedBy, (int) $points];
    if ($id) {
        $old = row('SELECT * FROM award_programs WHERE id = ?', [$id]);
        if (! $old) { return [t('That award does not exist.'), 0]; }
        if ($old['period'] !== $period && val('SELECT COUNT(*) FROM award_nominations WHERE program_id = ?', [$id])) {
            return [t('How often it is given cannot change once there are nominations.'), 0];
        }
        q('UPDATE award_programs SET name = ?, description = ?, period = ?, eligible = ?, nominated_by = ?, points = ?, is_active = ? WHERE id = ?',
          [...$values, ! empty($in['is_active']) ? 1 : 0, $id]);
        log_activity('changed an award', 'award_program', $id, $name);

        return [null, $id];
    }
    q('INSERT INTO award_programs (name, description, period, eligible, nominated_by, points, created_by) VALUES (?,?,?,?,?,?,?)', [...$values, $by]);
    $id = (int) db()->lastInsertId();
    log_activity('created an award', 'award_program', $id, $name);

    return [null, $id];
}

/** May this person nominate for this programme at all? */
function eng_may_nominate(array $program, int $userId): bool
{
    $me = eng_person($userId);
    if (! $me || ! (int) $program['is_active']) {
        return false;
    }

    return $program['nominated_by'] === 'everyone' || (eng_is_staff_role((string) $me['role']) && ! eng_is_worker($userId));
}

/** @return array{0:?string,1:int} */
function eng_nominate(int $programId, int $nominee, string $reason, int $by): array
{
    $reason = trim($reason);
    $p = row('SELECT * FROM award_programs WHERE id = ?', [$programId]);
    if (! $p || ! (int) $p['is_active']) { return [t('That award is not given now.'), 0]; }
    if (! eng_may_nominate($p, $by)) { return [t('Nominations for this award come from staff.'), 0]; }
    if ($nominee === $by) { return [t('Nominate someone else.'), 0]; }
    if (! isset(eng_colleagues($by)[$nominee])) { return [t('Choose one of the people you work with.'), 0]; }
    if (! eng_eligible((string) $p['eligible'], $nominee)) { return [t('This award is for :who.', ['who' => mb_strtolower(eng_eligibles()[$p['eligible']])]), 0]; }
    if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) { return [t('Say why, in 10 to 1000 characters.'), 0]; }
    $key = eng_period_key((string) $p['period']);
    if ($p['period'] === 'once') {
        if (val("SELECT COUNT(*) FROM award_nominations WHERE program_id = ? AND nominee_user_id = ? AND status = 'won'", [$programId, $nominee])) {
            return [t('This person has already won this award.'), 0];
        }
    } elseif (val("SELECT COUNT(*) FROM award_nominations WHERE program_id = ? AND period_key = ? AND status = 'won'", [$programId, $key])) {
        return [t('This period\'s award has been given.'), 0];
    }
    if (val('SELECT COUNT(*) FROM award_nominations WHERE program_id = ? AND period_key = ? AND nominee_user_id = ? AND nominated_by = ?', [$programId, $key, $nominee, $by])) {
        return [t('You have already nominated this person for this period.'), 0];
    }
    q('INSERT INTO award_nominations (program_id, period_key, nominee_user_id, nominated_by, reason) VALUES (?,?,?,?,?)', [$programId, $key, $nominee, $by, $reason]);

    return [null, (int) db()->lastInsertId()];
}

function eng_nomination_withdraw(int $id, int $by): ?string
{
    $n = row('SELECT * FROM award_nominations WHERE id = ?', [$id]);
    if (! $n || (int) $n['nominated_by'] !== $by) { return t('That nomination is not yours.'); }
    if ($n['status'] !== 'nominated') { return t('That nomination has been decided.'); }
    q("UPDATE award_nominations SET status = 'withdrawn' WHERE id = ?", [$id]);

    return null;
}

/**
 * Choose the winner. In a period, that closes the others; a worker's win
 * goes into their HR record as an award; the award's points are added once.
 */
function eng_award(int $nominationId, int $by): ?string
{
    require_once __DIR__ . '/hr-records.php';
    if (! eng_manager()) { return t('Recruiters and administrators choose the winners.'); }
    $n = row('SELECT * FROM award_nominations WHERE id = ?', [$nominationId]);
    if (! $n) { return t('That nomination does not exist.'); }
    if ((int) $n['nominee_user_id'] === $by) { return t('Someone else decides an award you are nominated for.'); }
    db()->beginTransaction();
    try {
        $p = row('SELECT * FROM award_programs WHERE id = ? FOR UPDATE', [(int) $n['program_id']]);
        $n = row('SELECT * FROM award_nominations WHERE id = ? FOR UPDATE', [$nominationId]);
        if ($n['status'] !== 'nominated') { db()->rollBack(); return t('That nomination has been decided.'); }
        $nominee = (int) $n['nominee_user_id'];
        $key = (string) $n['period_key'];
        $won = $p['period'] === 'once'
            ? val("SELECT COUNT(*) FROM award_nominations WHERE program_id = ? AND nominee_user_id = ? AND status = 'won'", [(int) $p['id'], $nominee])
            : val("SELECT COUNT(*) FROM award_nominations WHERE program_id = ? AND period_key = ? AND status = 'won'", [(int) $p['id'], $key]);
        if ($won) { db()->rollBack(); return t('This award has already been given for that period.'); }

        $title = (string) $p['name'] . ($p['period'] === 'once' ? '' : ' · ' . $key);
        $recognition = null;
        $cid = eng_candidate($nominee);
        if ($cid) {
            [$recognition, $why] = recognition_add($cid, ['kind' => 'award', 'title' => mb_substr($title, 0, 190), 'description' => (string) $n['reason'], 'awarded_on' => date('Y-m-d')]);
            if ($why !== null) { db()->rollBack(); return $why; }
        }
        // Every nomination of the winner for that period is won; the rest of the period is not.
        q("UPDATE award_nominations SET status = 'won', decided_by = ?, decided_at = NOW() WHERE program_id = ? AND period_key = ? AND nominee_user_id = ? AND status = 'nominated'",
          [$by, (int) $p['id'], $key, $nominee]);
        q('UPDATE award_nominations SET recognition_id = ? WHERE id = ?', [$recognition ?: null, $nominationId]);
        if ($p['period'] !== 'once') {
            q("UPDATE award_nominations SET status = 'not_selected', decided_by = ?, decided_at = NOW() WHERE program_id = ? AND period_key = ? AND status = 'nominated'",
              [$by, (int) $p['id'], $key]);
        }
        if ((int) $p['points'] > 0) {
            eng_points($nominee, (int) $p['points'], 'award', $nominationId, $title, $by);
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        throw $e;
    }
    if (! $cid) {
        eng_notify($nominee, t('You won: :title', ['title' => $title]), '/recognition#awards');
    }
    foreach (rows("SELECT DISTINCT nominated_by FROM award_nominations WHERE program_id = ? AND period_key = ? AND nominee_user_id = ? AND status = 'won'", [(int) $p['id'], $key, $nominee]) as $r) {
        eng_notify((int) $r['nominated_by'], t('Your nominee won: :title', ['title' => $title]), '/recognition#awards');
    }
    log_activity('gave an award', 'award_nomination', $nominationId, $title);

    return null;
}

/** Winners, newest first: one line per programme, period and person. */
function eng_winners(int $limit = 50): array
{
    return rows("SELECT n.program_id, n.period_key, n.nominee_user_id, p.name AS program, u.name, MAX(n.decided_at) AS decided_at,
                        GROUP_CONCAT(n.reason ORDER BY n.id SEPARATOR ' / ') AS reasons
                 FROM award_nominations n JOIN award_programs p ON p.id = n.program_id JOIN users u ON u.id = n.nominee_user_id
                 WHERE n.status = 'won' GROUP BY n.program_id, n.period_key, n.nominee_user_id, p.name, u.name
                 ORDER BY decided_at DESC LIMIT " . $limit);
}

// ── rewards ─────────────────────────────────────────────────────────────

/** @return array{0:?string,1:int} */
function eng_reward_save(array $in, int $by, int $id = 0): array
{
    $name = trim((string) ($in['name'] ?? ''));
    $description = trim((string) ($in['description'] ?? ''));
    $kind = (string) ($in['kind'] ?? '');
    $cost = trim((string) ($in['points_cost'] ?? ''));
    $cash = trim((string) ($in['cash_amount'] ?? ''));
    $leaveType = trim((string) ($in['leave_type'] ?? ''));
    $leaveDays = trim((string) ($in['leave_days'] ?? ''));
    $eligible = (string) ($in['eligible'] ?? 'everyone');
    $stock = trim((string) ($in['stock'] ?? ''));
    if (mb_strlen($name) < 3 || mb_strlen($name) > 190) { return [t('Give the reward a name of 3 to 190 characters.'), 0]; }
    if (mb_strlen($description) > 1000) { return [t('A field is too long.'), 0]; }
    if (! isset(eng_reward_kinds()[$kind])) { return [t('Say what kind of reward it is.'), 0]; }
    if (! ctype_digit($cost) || (int) $cost < 1 || (int) $cost > 1000000) { return [t('A reward costs 1 to 1,000,000 points.'), 0]; }
    if (! isset(eng_eligibles()[$eligible])) { return [t('Say who can have it.'), 0]; }
    if ($stock !== '' && ! ctype_digit($stock)) { return [t('How many are left is a whole number, or empty for no limit.'), 0]; }
    $cashAmount = $leaveSlug = $days = null;
    if ($kind === 'cash') {
        if (! is_numeric($cash) || (float) $cash <= 0 || (float) $cash > 10000) { return [t('A cash reward pays more than 0 and at most 10,000.'), 0]; }
        if ($eligible !== 'workers') { return [t('A cash reward is paid through payroll, so it is for workers.'), 0]; }
        $cashAmount = round((float) $cash, 2);
    }
    if ($kind === 'time_off') {
        if (! row('SELECT slug FROM leave_types WHERE slug = ? AND is_active = 1 AND days_allowed IS NOT NULL', [$leaveType])) {
            return [t('Choose a kind of leave that has a yearly allowance.'), 0];
        }
        if (! ctype_digit($leaveDays) || (int) $leaveDays < 1 || (int) $leaveDays > 10) { return [t('A time-off reward gives 1 to 10 days.'), 0]; }
        if ($eligible !== 'workers') { return [t('Time off is added to a worker\'s leave allowance, so it is for workers.'), 0]; }
        [$leaveSlug, $days] = [$leaveType, (int) $leaveDays];
    }
    $values = [$name, $description ?: null, $kind, (int) $cost, $cashAmount, $leaveSlug, $days, $eligible, $stock !== '' ? (int) $stock : null];
    if ($id) {
        if (! row('SELECT id FROM reward_catalog WHERE id = ?', [$id])) { return [t('That reward does not exist.'), 0]; }
        q('UPDATE reward_catalog SET name = ?, description = ?, kind = ?, points_cost = ?, cash_amount = ?, leave_type = ?, leave_days = ?, eligible = ?, stock = ?, is_active = ? WHERE id = ?',
          [...$values, ! empty($in['is_active']) ? 1 : 0, $id]);
        log_activity('changed a reward', 'reward', $id, $name);

        return [null, $id];
    }
    q('INSERT INTO reward_catalog (name, description, kind, points_cost, cash_amount, leave_type, leave_days, eligible, stock, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)', [...$values, $by]);
    $id = (int) db()->lastInsertId();
    log_activity('created a reward', 'reward', $id, $name);

    return [null, $id];
}

/** The rewards this person could ask for. */
function eng_rewards_for(int $userId): array
{
    return array_values(array_filter(rows('SELECT * FROM reward_catalog WHERE is_active = 1 ORDER BY points_cost, name'),
        fn($r) => eng_eligible((string) $r['eligible'], $userId)));
}

/**
 * Ask for a reward. The points are held at once, and given back if it is
 * declined or cancelled.
 *
 * @return array{0:?string,1:int}
 */
function eng_redeem(int $userId, int $rewardId, string $note): array
{
    $note = trim($note);
    if (mb_strlen($note) > 500) { return [t('A field is too long.'), 0]; }
    db()->beginTransaction();
    try {
        // One person's points are spent one request at a time.
        row('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
        $r = row('SELECT * FROM reward_catalog WHERE id = ? FOR UPDATE', [$rewardId]);
        $why = null;
        if (! $r || ! (int) $r['is_active']) { $why = t('That reward is not offered now.'); }
        elseif (! eng_eligible((string) $r['eligible'], $userId)) { $why = t('That reward is for :who.', ['who' => mb_strtolower(eng_eligibles()[$r['eligible']])]); }
        elseif ($r['stock'] !== null && (int) $r['stock'] < 1) { $why = t('None of that reward is left.'); }
        elseif (in_array($r['kind'], ['cash', 'time_off'], true) && ! eng_candidate($userId)) { $why = t('That reward is for workers.'); }
        elseif (eng_balance($userId) < (int) $r['points_cost']) { $why = t('That needs :n points; you have :have.', ['n' => (int) $r['points_cost'], 'have' => eng_balance($userId)]); }
        if ($why !== null) { db()->rollBack(); return [$why, 0]; }
        q('INSERT INTO reward_redemptions (user_id, reward_id, points, kind, cash_amount, leave_type, leave_days, note) VALUES (?,?,?,?,?,?,?,?)',
          [$userId, $rewardId, (int) $r['points_cost'], $r['kind'], $r['cash_amount'], $r['leave_type'], $r['leave_days'], $note ?: null]);
        $id = (int) db()->lastInsertId();
        eng_points($userId, -(int) $r['points_cost'], 'redemption', $id, (string) $r['name'], $userId);
        if ($r['stock'] !== null) {
            q('UPDATE reward_catalog SET stock = stock - 1 WHERE id = ?', [$rewardId]);
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        throw $e;
    }

    return [null, $id];
}

/** Points and stock back, for a request that will not be given. */
function eng_redemption_return(array $red, string $status, ?string $note, int $by): void
{
    q('UPDATE reward_redemptions SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?', [$status, $by, $note, (int) $red['id']]);
    eng_points((int) $red['user_id'], (int) $red['points'], 'refund', (int) $red['id'], null, $by);
    q('UPDATE reward_catalog SET stock = stock + 1 WHERE id = ? AND stock IS NOT NULL', [(int) $red['reward_id']]);
}

function eng_redemption_cancel(int $id, int $userId): ?string
{
    db()->beginTransaction();
    $red = row('SELECT * FROM reward_redemptions WHERE id = ? FOR UPDATE', [$id]);
    if (! $red || (int) $red['user_id'] !== $userId) { db()->rollBack(); return t('That request is not yours.'); }
    if ($red['status'] !== 'requested') { db()->rollBack(); return t('That request has been decided.'); }
    eng_redemption_return($red, 'cancelled', null, $userId);
    db()->commit();

    return null;
}

function eng_redemption_decide(int $id, string $decision, string $note, int $by): ?string
{
    $note = trim($note);
    if (! eng_manager()) { return t('Recruiters and administrators decide rewards.'); }
    if (! in_array($decision, ['approve', 'decline'], true)) { return t('Unknown action.'); }
    if ($decision === 'decline' && (mb_strlen($note) < 5 || mb_strlen($note) > 500)) { return t('Say why it is declined, in 5 to 500 characters.'); }
    db()->beginTransaction();
    $red = row('SELECT * FROM reward_redemptions WHERE id = ? FOR UPDATE', [$id]);
    if (! $red) { db()->rollBack(); return t('That request does not exist.'); }
    if ((int) $red['user_id'] === $by) { db()->rollBack(); return t('Someone else decides your own request.'); }
    if ($red['status'] !== 'requested') { db()->rollBack(); return t('That request has been decided.'); }
    $name = (string) val('SELECT name FROM reward_catalog WHERE id = ?', [(int) $red['reward_id']]);
    if ($decision === 'decline') {
        eng_redemption_return($red, 'declined', $note, $by);
        db()->commit();
        eng_notify((int) $red['user_id'], t('Declined, and your points given back: :name. :why', ['name' => $name, 'why' => $note]), '/recognition#rewards');
    } else {
        q("UPDATE reward_redemptions SET status = 'approved', decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?", [$by, $note ?: null, $id]);
        db()->commit();
        eng_notify((int) $red['user_id'], t('Approved: :name', ['name' => $name]), '/recognition#rewards');
    }
    log_activity($decision === 'approve' ? 'approved a reward' : 'declined a reward', 'reward_redemption', $id, $name);

    return null;
}

/** Hand over an approved reward. Cash is paid through payroll instead. */
function eng_redemption_fulfil(int $id, string $note, int $by): ?string
{
    $note = trim($note);
    if (! eng_manager()) { return t('Recruiters and administrators hand over rewards.'); }
    if (mb_strlen($note) > 500) { return t('A field is too long.'); }
    db()->beginTransaction();
    $red = row('SELECT * FROM reward_redemptions WHERE id = ? FOR UPDATE', [$id]);
    if (! $red) { db()->rollBack(); return t('That request does not exist.'); }
    if ($red['status'] !== 'approved') { db()->rollBack(); return t('Only an approved reward is handed over.'); }
    if ($red['kind'] === 'cash') { db()->rollBack(); return t('A cash reward is paid by payroll, in an open pay period.'); }
    q("UPDATE reward_redemptions SET status = 'fulfilled', fulfilled_by = ?, fulfilled_at = NOW(), fulfil_note = ? WHERE id = ?", [$by, $note ?: null, $id]);
    db()->commit();
    $name = (string) val('SELECT name FROM reward_catalog WHERE id = ?', [(int) $red['reward_id']]);
    eng_notify((int) $red['user_id'], $red['kind'] === 'time_off'
        ? t(':name: :n day(s) added to your leave for this year.', ['name' => $name, 'n' => (int) $red['leave_days']])
        : t('Your reward is ready: :name', ['name' => $name]), '/recognition#rewards');
    log_activity('handed over a reward', 'reward_redemption', $id, $name);

    return null;
}

/** Approved cash rewards, waiting for payroll. */
function eng_cash_rewards_to_pay(): array
{
    return rows("SELECT r.id, r.cash_amount, r.decided_at, c.name AS reward, u.name, w.candidate_id
                 FROM reward_redemptions r JOIN reward_catalog c ON c.id = r.reward_id JOIN users u ON u.id = r.user_id
                 JOIN worker_accounts w ON w.user_id = r.user_id
                 WHERE r.status = 'approved' AND r.kind = 'cash' ORDER BY r.id");
}

/**
 * Pay an approved cash reward in an open pay period: one payroll adjustment
 * of kind "reward", taxed as wages like any other, and the reward is given.
 */
function eng_reward_pay(int $runId, int $redemptionId, int $by): ?string
{
    require_once __DIR__ . '/pay-periods.php';
    if (! can('payroll')) { return t('Payroll pays cash rewards.'); }
    db()->beginTransaction();
    $run = row('SELECT * FROM payroll_runs WHERE id = ? FOR UPDATE', [$runId]);
    $red = row('SELECT * FROM reward_redemptions WHERE id = ? FOR UPDATE', [$redemptionId]);
    $why = null;
    if (! $run) { $why = t('That pay period does not exist.'); }
    elseif ($run['status'] !== 'open') { $why = t('Adjustments go into an open period. This one is :status.', ['status' => payroll_run_statuses()[$run['status']] ?? $run['status']]); }
    elseif (! $red || $red['kind'] !== 'cash') { $why = t('That is not a cash reward.'); }
    elseif ($red['status'] !== 'approved') { $why = t('Only an approved reward is paid, and only once.'); }
    $cid = $red ? eng_candidate((int) $red['user_id']) : 0;
    if ($why === null && ! $cid) { $why = t('That reward is for workers.'); }
    if ($why !== null) { db()->rollBack(); return $why; }
    $name = (string) val('SELECT name FROM reward_catalog WHERE id = ?', [(int) $red['reward_id']]);
    $reason = mb_substr($name . ' (reward #' . $redemptionId . ')', 0, 500);
    q("INSERT INTO payroll_adjustments (run_id, candidate_id, timesheet_id, kind, amount, hours, reason, created_by) VALUES (?,?,NULL,'reward',?,NULL,?,?)",
      [$runId, $cid, round((float) $red['cash_amount'], 2), $reason, $by]);
    $adj = (int) db()->lastInsertId();
    payroll_run_event($runId, 'adjustment', 'reward ' . $red['cash_amount'] . ' for #' . $cid . ': ' . $reason, $by);
    q("UPDATE reward_redemptions SET status = 'fulfilled', fulfilled_by = ?, fulfilled_at = NOW(), payroll_adjustment_id = ?, fulfil_note = ? WHERE id = ?",
      [$by, $adj, t('Paid in the week ending :date', ['date' => (string) $run['week_ending']]), $redemptionId]);
    db()->commit();
    eng_notify((int) $red['user_id'], t(':name will be paid with the week ending :date.', ['name' => $name, 'date' => d((string) $run['week_ending'])]), '/recognition#rewards');
    log_activity('paid a cash reward', 'payroll_run', $runId, $reason);

    return null;
}

/**
 * Days of a kind of leave given as rewards to a worker in a year: added to
 * that year's allowance by leave_balance().
 */
function eng_leave_reward_days(int $candidateId, string $slug, int $year): float
{
    return (float) val("SELECT COALESCE(SUM(r.leave_days), 0) FROM reward_redemptions r JOIN worker_accounts w ON w.user_id = r.user_id
                        WHERE w.candidate_id = ? AND r.leave_type = ? AND r.kind = 'time_off' AND r.status = 'fulfilled' AND YEAR(r.fulfilled_at) = ?",
                       [$candidateId, $slug, $year]);
}

// ── feedback ────────────────────────────────────────────────────────────

/** @return array<string,string> */
function fb_kinds(): array
{
    return ['praise' => t('Praise'), 'suggestion' => t('Suggestion'), 'concern' => t('Concern')];
}

/**
 * May the signed-in person read all feedback about this person, shared or
 * not? The HR desk, and the supervisor of a worker's live assignment.
 */
function fb_may_read_all(int $aboutUserId): bool
{
    if (eng_manager()) {
        return true;
    }
    $cid = eng_candidate($aboutUserId);
    if (! $cid) {
        return false;
    }
    require_once __DIR__ . '/hr-records.php';

    return hr_supervises($cid);
}

/** @return array{0:?string,1:int} */
function fb_give(int $from, int $about, string $kind, string $body, bool $shared, int $requestId = 0): array
{
    $body = trim($body);
    if (! eng_person($from)) { return [t('Only someone who works here gives feedback.'), 0]; }
    if ($about === $from) { return [t('Feedback is about someone else.'), 0]; }
    if (! isset(fb_kinds()[$kind])) { return [t('Say what kind of feedback it is.'), 0]; }
    if (mb_strlen($body) < 10 || mb_strlen($body) > 2000) { return [t('Write it in 10 to 2000 characters.'), 0]; }
    $request = null;
    if ($requestId) {
        $request = row('SELECT r.*, p.answered_at FROM feedback_requests r JOIN feedback_request_people p ON p.request_id = r.id AND p.user_id = ? WHERE r.id = ?', [$from, $requestId]);
        if (! $request) { return [t('That request was not sent to you.'), 0]; }
        if ($request['answered_at'] !== null) { return [t('You have already answered that request.'), 0]; }
        $about = (int) $request['about_user_id'];
    } elseif (! isset(eng_colleagues($from)[$about])) {
        return [t('Choose one of the people you work with.'), 0];
    }
    if (! eng_person($about)) { return [t('Choose one of the people you work with.'), 0]; }
    q('INSERT INTO feedback_entries (about_user_id, from_user_id, request_id, kind, body, is_shared) VALUES (?,?,?,?,?,?)',
      [$about, $from, $requestId ?: null, $kind, $body, $shared ? 1 : 0]);
    $id = (int) db()->lastInsertId();
    $fromName = (string) val('SELECT name FROM users WHERE id = ?', [$from]);
    if ($request) {
        q('UPDATE feedback_request_people SET answered_at = NOW() WHERE request_id = ? AND user_id = ?', [$requestId, $from]);
        if ((int) $request['asked_by'] !== $about || $shared) {
            eng_notify((int) $request['asked_by'], t(':name answered your request for feedback.', ['name' => $fromName]), '/feedback?request=' . $requestId);
        }
    }
    if ($shared && (! $request || (int) $request['asked_by'] !== $about)) {
        eng_notify($about, t('New feedback from :name.', ['name' => $fromName]), '/feedback');
    }

    return [null, $id];
}

/**
 * Ask colleagues for feedback about yourself, or, for the HR desk or a
 * worker's supervisor, about that person.
 *
 * @return array{0:?string,1:int}
 */
function fb_request(int $askedBy, int $about, array $userIds, string $question, string $dueOn): array
{
    $question = trim($question);
    if (! eng_person($askedBy)) { return [t('Only someone who works here asks for feedback.'), 0]; }
    if (! eng_person($about)) { return [t('Choose who the feedback is about.'), 0]; }
    if ($about !== $askedBy && ! fb_may_read_all($about)) { return [t('You ask for feedback about yourself, or about someone you supervise.'), 0]; }
    $people = array_values(array_unique(array_map('intval', $userIds)));
    if (count($people) < 1 || count($people) > 10) { return [t('Ask 1 to 10 people.'), 0]; }
    $mine = eng_colleagues($askedBy);
    foreach ($people as $p) {
        if ($p === $about || $p === $askedBy) { return [t('Ask other people than the person it is about.'), 0]; }
        if (! isset($mine[$p]) && ! (eng_manager() && eng_person($p))) { return [t('Choose one of the people you work with.'), 0]; }
    }
    if (mb_strlen($question) < 10 || mb_strlen($question) > 500) { return [t('Ask the question in 10 to 500 characters.'), 0]; }
    if ($dueOn !== '' && (! valid_date($dueOn) || $dueOn < date('Y-m-d'))) { return [t('Give a due date, today or later.'), 0]; }
    q('INSERT INTO feedback_requests (about_user_id, asked_by, question, due_on) VALUES (?,?,?,?)', [$about, $askedBy, $question, $dueOn ?: null]);
    $id = (int) db()->lastInsertId();
    $aboutName = (string) val('SELECT name FROM users WHERE id = ?', [$about]);
    foreach ($people as $p) {
        q('INSERT INTO feedback_request_people (request_id, user_id) VALUES (?,?)', [$id, $p]);
        eng_notify($p, t('Feedback asked about :name: :question', ['name' => $aboutName, 'question' => $question]), '/feedback?answer=' . $id);
    }

    return [null, $id];
}

/** May this person read this piece of feedback? */
function fb_visible(array $e, int $viewer): bool
{
    if ((int) $e['from_user_id'] === $viewer) {
        return true;
    }
    if ((int) $e['about_user_id'] === $viewer) {
        return (bool) (int) $e['is_shared'];
    }
    if ($e['request_id'] !== null && (int) val('SELECT asked_by FROM feedback_requests WHERE id = ?', [(int) $e['request_id']]) === $viewer) {
        return true;
    }

    return fb_may_read_all((int) $e['about_user_id']);
}

function fb_entries(string $where, array $args, int $viewer): array
{
    $list = rows('SELECT e.*, f.name AS from_name, a.name AS about_name FROM feedback_entries e JOIN users f ON f.id = e.from_user_id JOIN users a ON a.id = e.about_user_id
                  WHERE ' . $where . ' ORDER BY e.id DESC LIMIT 200', $args);

    return array_values(array_filter($list, fn($e) => fb_visible($e, $viewer)));
}
