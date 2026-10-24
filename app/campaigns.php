<?php
/**
 * Campaign email (T7, R13-R14): one email about one requisition, with its
 * pay, per diem and start, to a filtered list of the talent pool. People
 * answer through their portal ("Jobs for you") or, without an account,
 * through the requisition's application page: first come, first served,
 * and their interest arrives in Hiring like any other.
 *
 * Decided by the owner, 13 Oct 2026:
 *   - Sent from the configured sender (no-reply@...), replies to the
 *     recruiter who sent it (Reply-To).
 *   - The audience is everyone matching the filters with a valid email,
 *     except people who unsubscribed, people on the do-not-rehire register,
 *     inactive accounts, and people already on the requisition. Every email
 *     carries an unsubscribe link and the agency's postal address.
 *   - A recruiter sends to up to 50 people; above that an administrator
 *     approves first.
 *   - Delivery is 50 every 5 minutes (install/deliver-campaigns.php in
 *     cron), at most 500 in any 24 hours, so the server's shared address is
 *     not marked as a spammer.
 *
 * Recipients are fixed when the campaign is created: the list a recruiter
 * reviewed is the list that is emailed. Anyone who unsubscribes meanwhile is
 * skipped.
 *
 * P5-M29: a campaign can go by text message instead, through Twilio
 * (app/twilio.php): the same filters, the same approval above 50 people,
 * the same delivery job. It goes to people with a mobile number who never
 * replied STOP; every text ends with "Reply STOP to stop these texts.", and
 * a STOP reply is honoured by Twilio's webhook before the next one goes.
 * In Twilio's sandbox each text is recorded on the person and not sent.
 */

declare(strict_types=1);

require_once __DIR__ . '/geo.php';

const CAMPAIGN_DIRECT_LIMIT = 50;
const CAMPAIGN_PER_RUN = 50;
const CAMPAIGN_PER_DAY = 500;
const CAMPAIGN_TEXTS_PER_DAY = 1000;
const CAMPAIGN_TEXT_MAX = 480;

/** email or text, read from a form. */
function cm_channel(array $in): string
{
    return ($in['channel'] ?? '') === 'text' ? 'text' : 'email';
}

/** The open requisition a campaign is about, with what the email quotes. */
function cm_vacancy(int $id): ?array
{
    return row("SELECT v.id, v.job_id, v.title, v.discipline, v.shift, v.starts_on, COALESCE(v.pay_rate, l.pay_rate) AS pay_rate, COALESCE(v.per_diem_rate, l.per_diem_rate) AS per_diem_rate,
                       j.title AS project, j.site_city, j.site_state, j.site_zip, j.starts_on AS job_starts
                FROM vacancies v JOIN jobs j ON j.id = v.job_id LEFT JOIN job_order_lines l ON l.id = v.order_line_id
                WHERE v.id = ? AND v.is_open = 1 AND v.posting_status = 'published' AND j.status <> 'closed'", [$id]) ?: null;
}

/** @return array<int,array> the open requisitions to choose from */
function cm_open_vacancies(): array
{
    return rows("SELECT v.id, v.title, j.title AS project FROM vacancies v JOIN jobs j ON j.id = v.job_id WHERE v.is_open = 1 AND v.posting_status = 'published' AND j.status <> 'closed' ORDER BY j.title, v.title");
}

/** The audience filters, read from a form. */
function cm_filters(array $in): array
{
    return [
        'discipline' => array_key_exists((string) ($in['discipline'] ?? ''), disciplines()) ? (string) $in['discipline'] : '',
        'state'      => geo_state_code((string) ($in['state'] ?? '')) ?? '',
        'miles'      => in_array((int) ($in['miles'] ?? 0), geo_radii(), true) ? (int) $in['miles'] : 0,
        'skill'      => array_key_exists((string) ($in['skill'] ?? ''), skills_list(true)) ? (string) $in['skill'] : '',
        'available'  => ! empty($in['available']),
        'worked'     => ! empty($in['worked']),
        // P5-M82: exactly the people ticked on Candidates (their ids), instead of a filter.
        'ids'        => implode(',', array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($in['ids'] ?? '')))))), 0, 2000)),
    ];
}

/** Everyone a campaign about this requisition would go to, with these filters, by email or by text. */
function cm_audience(array $vacancy, array $f, string $channel = 'email'): array
{
    geo_refresh_candidates();
    $where = $channel === 'text'
        ? ["c.phone IS NOT NULL", "c.phone <> ''", 'NOT EXISTS (SELECT 1 FROM contact_preferences cp WHERE cp.candidate_id = c.id AND cp.no_texts_since IS NOT NULL)']
        : ["c.email IS NOT NULL", "c.email LIKE '%_@_%._%'", 'NOT EXISTS (SELECT 1 FROM contact_preferences cp WHERE cp.candidate_id = c.id AND cp.no_email_since IS NOT NULL)'];
    array_push($where, "NOT EXISTS (SELECT 1 FROM employee_profiles ep WHERE ep.candidate_id = c.id AND ep.rehire_status IN ('ineligible','do_not_use'))",
              'NOT EXISTS (SELECT 1 FROM worker_accounts w2 JOIN users u2 ON u2.id = w2.user_id WHERE w2.candidate_id = c.id AND u2.is_active = 0)',
              'NOT EXISTS (SELECT 1 FROM applications a WHERE a.candidate_id = c.id AND a.vacancy_id = ?)');
    $args = [(int) $vacancy['id']];
    if (($f['ids'] ?? '') !== '') {
        // People chosen by hand may be this requisition's own applicants: they are written to, not left out.
        array_pop($where);
        $args = [];
        $where[] = 'c.id IN (' . $f['ids'] . ')';
    }
    if ($f['discipline'] !== '') { $where[] = 'c.discipline = ?'; $args[] = $f['discipline']; }
    if ($f['state'] !== '') { $where[] = 'c.geo_state = ?'; $args[] = $f['state']; }
    if ($f['skill'] !== '') { $where[] = 'EXISTS (SELECT 1 FROM candidate_skills cs WHERE cs.candidate_id = c.id AND cs.skill_slug = ?)'; $args[] = $f['skill']; }
    if ($f['available']) { $where[] = "EXISTS (SELECT 1 FROM employee_profiles ea WHERE ea.candidate_id = c.id AND ea.availability = 'available')"; }
    if ($f['worked']) { $where[] = "EXISTS (SELECT 1 FROM placements pw WHERE pw.candidate_id = c.id AND pw.status IN ('on_site','completed'))"; }
    if ($f['miles'] && ($site = geo_site($vacancy))) {
        [$within, $withinArgs] = geo_within_sql($site, $f['miles']);
        $where[] = $within;
        array_push($args, ...$withinArgs);
    }
    $people = rows('SELECT c.id, c.full_name, c.email, c.phone, c.city, c.state, w.user_id FROM candidates c LEFT JOIN worker_accounts w ON w.candidate_id = c.id
                    WHERE ' . implode(' AND ', $where) . ' ORDER BY c.full_name LIMIT 5000', $args);
    if ($channel === 'text') {
        // A text needs a number with its country code: one Twilio can deliver.
        require_once __DIR__ . '/messaging.php';
        $people = array_map(static fn($p) => ['phone' => msg_phone($p['phone'])] + $p, $people);

        return array_values(array_filter($people, static fn($p) => $p['phone'] !== null && str_starts_with($p['phone'], '+')));
    }

    return array_values(array_filter($people, static fn($p) => filter_var(trim((string) $p['email']), FILTER_VALIDATE_EMAIL)));
}

/** The text an empty campaign starts from, in English or Spanish, by email or by text. */
function cm_default(string $language, string $channel = 'email'): array
{
    if ($channel === 'text') {
        return $language === 'es'
            ? ['subject' => 'Texto: {job} en {place}',
               'body' => 'Hola {first_name}, hay trabajo para usted: {job} en {place} desde el {starts}, {pay_rate}/h, viático {per_diem}. El primero que responde se queda con el puesto: {link}']
            : ['subject' => 'Text: {job} in {place}',
               'body' => 'Hi {first_name}, work for you: {job} in {place} from {starts}, {pay_rate}/h, per diem {per_diem}. First come, first served: {link}'];
    }

    return $language === 'es'
        ? ['subject' => 'Trabajo disponible: {job} en {place}',
           'body' => "Hola {first_name}:\n\nTenemos trabajo para usted: {job} en {place}, a partir del {starts}.\nPago: {pay_rate} por hora. Viático: {per_diem}.\nTurno: {shift}\n\nEl primero que responde, primero se queda con el puesto. Si le interesa, dígalo aquí:\n{link}\n\nResponda a este correo si tiene alguna pregunta."]
        : ['subject' => 'Work available: {job} in {place}',
           'body' => "Hello {first_name},\n\nWe have work for you: {job} in {place}, starting {starts}.\nPay: {pay_rate} an hour. Per diem: {per_diem}.\nShift: {shift}\n\nFirst come, first served. If you want it, say so here:\n{link}\n\nReply to this email if you have a question."];
}

/** @return string[] the placeholders a campaign can use */
function cm_placeholders(): array
{
    return ['{first_name}', '{job}', '{place}', '{pay_rate}', '{per_diem}', '{starts}', '{shift}', '{link}'];
}

function cm_postal_address(): string
{
    return trim((string) val("SELECT setting_value FROM platform_settings WHERE setting_key = 'campaign_postal_address'"));
}

/**
 * The test suite's stand-in for the mail relay, as for Twilio: in debug
 * only, a platform setting naming a .jsonl file makes campaign mail be
 * written there instead of sent. Production runs without debug and always
 * uses the relay.
 */
function cm_capture(): ?string
{
    global $config;
    if (empty($config['debug'])) {
        return null;
    }
    $path = (string) val("SELECT setting_value FROM platform_settings WHERE setting_key = 'mail_capture_path'");

    return $path !== '' && str_ends_with($path, '.jsonl') ? $path : null;
}

/** Why campaigns cannot go out yet, by email or by text, or null. */
function cm_not_ready(string $channel = 'email'): ?string
{
    global $config;
    if ($channel === 'text') {
        require_once __DIR__ . '/twilio.php';

        return tw_mode() === 'disabled' || ! tw_configured() ? t('Texting through Crewvia is not set up.') : null;
    }
    if (! cm_capture() && (empty($config['email_delivery_enabled']) || ! filter_var((string) ($config['email_from'] ?? ''), FILTER_VALIDATE_EMAIL))) {
        return t('Email is not switched on for this installation (email_delivery_enabled and email_from).');
    }
    if (cm_postal_address() === '') {
        return t('An administrator must enter the agency\'s postal address first: every campaign email carries it.');
    }

    return null;
}

/** Where a person answers: their portal's Jobs for you, or the requisition's application page. */
function cm_link(array $vacancy, array $person): string
{
    global $config;
    $base = rtrim((string) ($config['app_url'] ?? ''), '/');

    return $person['user_id'] ? $base . '/my-jobs' : $base . '/apply?id=' . (int) $vacancy['id'];
}

/** One email, filled in for one person. @return array{subject:string, body:string, unsubscribe:string} */
function cm_render(array $campaign, array $vacancy, array $person, string $token): array
{
    global $config;
    $base = rtrim((string) ($config['app_url'] ?? ''), '/');
    $es = $campaign['language'] === 'es';
    $money = static fn($v) => $v === null ? ($es ? 'por confirmar' : 'to be confirmed') : '$' . number_format((float) $v, 2);
    $starts = $vacancy['starts_on'] ?: $vacancy['job_starts'];
    $values = [
        '{first_name}' => trim(strtok((string) $person['full_name'], ' ') ?: (string) $person['full_name']),
        '{job}' => (string) $vacancy['title'],
        '{place}' => trim(($vacancy['site_city'] ?? '') . ', ' . ($vacancy['site_state'] ?? ''), ', ') ?: ($es ? 'lugar por confirmar' : 'location to be confirmed'),
        '{pay_rate}' => $money($vacancy['pay_rate']),
        '{per_diem}' => $vacancy['per_diem_rate'] !== null && (float) $vacancy['per_diem_rate'] > 0 ? $money($vacancy['per_diem_rate']) . ($es ? ' por día' : ' a day') : ($es ? 'ninguno' : 'none'),
        '{starts}' => $starts ? ($starts <= date('Y-m-d') ? ($es ? 'ya' : 'now') : date('j M Y', strtotime((string) $starts))) : ($es ? 'fecha por confirmar' : 'date to be confirmed'),
        '{shift}' => (string) ($vacancy['shift'] ?: ($es ? 'por confirmar' : 'to be confirmed')),
        '{link}' => cm_link($vacancy, $person),
    ];
    $unsubscribe = $base . '/unsubscribe?t=' . $token;
    $body = strtr((string) $campaign['body'], $values);
    if (($campaign['channel'] ?? 'email') === 'text') {
        // A text has no unsubscribe link: STOP is the way out, and Twilio's webhook records it.
        if (! str_contains((string) $campaign['body'], '{link}')) {
            $body .= ' ' . $values['{link}'];
        }

        return ['subject' => strtr((string) $campaign['subject'], $values), 'body' => $body . "\n" . ($es ? 'Responda STOP para no recibir más textos.' : 'Reply STOP to stop these texts.'), 'unsubscribe' => ''];
    }
    if (! str_contains((string) $campaign['body'], '{link}')) {
        $body .= "\n\n" . $values['{link}'];
    }
    $footer = $es
        ? "\n\n--\nRecibe este correo porque figura en la bolsa de talento de " . $config['app_name'] . ". Para no recibir más estos correos: " . $unsubscribe . "\n" . cm_postal_address()
        : "\n\n--\nYou are receiving this because you are in " . $config['app_name'] . "'s talent pool. To stop these emails: " . $unsubscribe . "\n" . cm_postal_address();

    return ['subject' => strtr((string) $campaign['subject'], $values), 'body' => $body . $footer, 'unsubscribe' => $unsubscribe];
}

/**
 * Sends one message through the server's mail relay. In debug only, a test
 * run may point mail_capture_path at a file under storage/ and the message
 * is written there instead: production never captures.
 */
function cm_mail(string $to, string $subject, string $body, array $headers): bool
{
    if (outbound_disabled()) {
        return false;   // P5-M107: this installation sends nothing
    }
    // P5-M47: in test mode a candidate's email goes to the test inbox; candidates' emails answer to the recruiting lead.
    require_once __DIR__ . '/mail-routing.php';
    $headers = mail_candidate_headers($headers, $to);
    $route = mail_route($to);
    $to = $route['to'];
    $subject = $route['prefix'] . $subject;
    if ($capture = cm_capture()) {
        return file_put_contents($capture, json_encode(['to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => $headers]) . "\n", FILE_APPEND | LOCK_EX) !== false;
    }

    return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

function cm_headers(array $campaign, string $unsubscribe): array
{
    global $config;
    $h = ['From' => (string) (($config['email_from'] ?? '') ?: 'capture@test.invalid'), 'Content-Type' => 'text/plain; charset=UTF-8',
          'List-Unsubscribe' => '<' . $unsubscribe . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
    if ($campaign['reply_to'] && filter_var($campaign['reply_to'], FILTER_VALIDATE_EMAIL)) {
        $h['Reply-To'] = (string) $campaign['reply_to'];
    }

    return $h;
}

/** Creates a draft with its recipients fixed. @return array{0:?string,1:int} refusal, campaign id */
function cm_create(int $vacancyId, array $in, int $by): array
{
    $vacancy = cm_vacancy($vacancyId);
    if (! $vacancy) {
        return [t('Choose an open requisition.'), 0];
    }
    $language = ($in['language'] ?? 'en') === 'es' ? 'es' : 'en';
    $channel = cm_channel($in);
    $subject = trim((string) ($in['subject'] ?? ''));
    $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
    if ($channel === 'text' && $subject === '') {
        $subject = cm_default($language, 'text')['subject'];
    }
    if (mb_strlen($subject) < 3 || mb_strlen($subject) > 190) {
        return [t('Write a subject of 3 to 190 characters.'), 0];
    }
    if ($channel === 'text' && (mb_strlen($body) < 20 || mb_strlen($body) > CAMPAIGN_TEXT_MAX)) {
        return [t('Write the text in 20 to :n characters: it goes as up to three text messages.', ['n' => CAMPAIGN_TEXT_MAX]), 0];
    }
    if (mb_strlen($body) < 20 || mb_strlen($body) > 8000) {
        return [t('Write the message in 20 to 8000 characters.'), 0];
    }
    $filters = cm_filters($in);
    $people = cm_audience($vacancy, $filters, $channel);
    if (! $people) {
        return [t('Nobody matches these filters.'), 0];
    }
    $me = row('SELECT email FROM users WHERE id = ?', [$by]);
    db()->beginTransaction();
    try {
        q('INSERT INTO email_campaigns (vacancy_id, channel, language, subject, body, filters_json, reply_to, recipients, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
          [$vacancyId, $channel, $language, $subject, $body, json_encode($filters), $me['email'] ?? null, count($people), $by]);
        $id = (int) db()->lastInsertId();
        foreach ($people as $p) {
            q('INSERT INTO email_campaign_recipients (campaign_id, candidate_id, email, phone, token) VALUES (?,?,?,?,?)',
              [$id, (int) $p['id'], $channel === 'text' ? null : trim((string) $p['email']), $channel === 'text' ? $p['phone'] : null, bin2hex(random_bytes(32))]);
        }
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) { db()->rollBack(); }
        throw $error;
    }
    log_activity($channel === 'text' ? 'drafted a campaign text' : 'drafted a campaign email', 'campaign', $id, count($people) . ' recipients');

    return [null, $id];
}

function cm_campaign(int $id): ?array
{
    return row('SELECT m.*, v.title AS vacancy_title, u.name AS created_by_name, x.name AS approved_by_name FROM email_campaigns m JOIN vacancies v ON v.id = m.vacancy_id
                JOIN users u ON u.id = m.created_by LEFT JOIN users x ON x.id = m.approved_by WHERE m.id = ?', [$id]) ?: null;
}

/** @return array<string,int> recipients by status */
function cm_counts(int $id): array
{
    $out = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    foreach (rows('SELECT status, COUNT(*) n FROM email_campaign_recipients WHERE campaign_id = ? GROUP BY status', [$id]) as $r) {
        $out[$r['status']] = (int) $r['n'];
    }

    return $out;
}

/** Sends the draft: at once up to 50 people, otherwise to an administrator for approval. Returns the refusal, or null. */
function cm_send(array $campaign, int $by): ?string
{
    if ($campaign['status'] !== 'draft') {
        return t('This campaign is already on its way, or cancelled.');
    }
    if ($why = cm_not_ready((string) $campaign['channel'])) {
        return $why;
    }
    if (! cm_vacancy((int) $campaign['vacancy_id'])) {
        return t('That requisition is closed. Draft a campaign for an open one.');
    }
    if ((int) $campaign['recipients'] > CAMPAIGN_DIRECT_LIMIT && ! can('admin')) {
        q("UPDATE email_campaigns SET status = 'awaiting_approval' WHERE id = ?", [(int) $campaign['id']]);
        foreach (rows("SELECT id FROM users WHERE role = 'admin' AND is_active = 1") as $a) {
            q('INSERT INTO notifications (user_id, message, target) VALUES (?,?,?)', [(int) $a['id'], mb_substr(t($campaign['channel'] === 'text' ? 'A campaign text to :n people waits for your approval.' : 'A campaign email to :n people waits for your approval.', ['n' => (int) $campaign['recipients']]), 0, 255), '/campaigns?c=' . (int) $campaign['id']]);
        }
        log_activity('asked approval for a campaign email', 'campaign', (int) $campaign['id']);

        return null;
    }
    q("UPDATE email_campaigns SET status = 'sending', queued_at = NOW(), approved_by = ?, approved_at = NOW() WHERE id = ?", [$by, (int) $campaign['id']]);
    log_activity('queued a campaign email', 'campaign', (int) $campaign['id'], (int) $campaign['recipients'] . ' recipients');

    return null;
}

function cm_approve(array $campaign, int $by): ?string
{
    if ($campaign['status'] !== 'awaiting_approval') {
        return t('This campaign is not waiting for approval.');
    }
    if ($why = cm_not_ready((string) $campaign['channel'])) {
        return $why;
    }
    q("UPDATE email_campaigns SET status = 'sending', queued_at = NOW(), approved_by = ?, approved_at = NOW() WHERE id = ?", [$by, (int) $campaign['id']]);
    log_activity('approved a campaign email', 'campaign', (int) $campaign['id']);

    return null;
}

function cm_cancel(array $campaign): ?string
{
    if (! in_array($campaign['status'], ['draft', 'awaiting_approval', 'sending'], true)) {
        return t('This campaign is finished.');
    }
    q("UPDATE email_campaign_recipients SET status = 'skipped', error = 'cancelled' WHERE campaign_id = ? AND status = 'queued'", [(int) $campaign['id']]);
    q("UPDATE email_campaigns SET status = 'cancelled', finished_at = NOW() WHERE id = ?", [(int) $campaign['id']]);
    log_activity('cancelled a campaign email', 'campaign', (int) $campaign['id']);

    return null;
}

/** Sends the staff member the email exactly as its first recipient would get it. Returns the refusal, or null. */
function cm_test(array $campaign, int $by): ?string
{
    if ($campaign['channel'] === 'text') {
        return t('A text campaign has no test send: the sample on this page is exactly what each person receives.');
    }
    if ($why = cm_not_ready()) {
        return $why;
    }
    $me = row('SELECT email FROM users WHERE id = ?', [$by]);
    $first = row('SELECT r.token, c.full_name, c.email, w.user_id FROM email_campaign_recipients r JOIN candidates c ON c.id = r.candidate_id LEFT JOIN worker_accounts w ON w.candidate_id = c.id
                  WHERE r.campaign_id = ? ORDER BY r.id LIMIT 1', [(int) $campaign['id']]);
    $vacancy = cm_vacancy((int) $campaign['vacancy_id']);
    if (! $me || ! filter_var((string) $me['email'], FILTER_VALIDATE_EMAIL) || ! $first || ! $vacancy) {
        return t('There is nothing to test, or no email on your account.');
    }
    $mail = cm_render($campaign, $vacancy, $first, str_repeat('0', 64));
    if (! cm_mail((string) $me['email'], '[TEST] ' . $mail['subject'], $mail['body'], cm_headers($campaign, $mail['unsubscribe']))) {
        return t('The mail relay refused the test.');
    }
    log_activity('sent a campaign test to themselves', 'campaign', (int) $campaign['id']);

    return null;
}

/**
 * The delivery run: up to CAMPAIGN_PER_RUN messages, never more than
 * CAMPAIGN_PER_DAY in any 24 hours. Returns what it did.
 * @return array{sent:int, failed:int, skipped:int, held:?string}
 */
function cm_deliver(): array
{
    $done = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'held' => null];
    // P5-M29: texts have their own budget and their own readiness (Twilio).
    cm_deliver_texts($done);
    if ($why = cm_not_ready()) {
        $done['held'] = $done['held'] === null ? $why : $why . ' ' . $done['held'];
        cm_finish();
        return $done;
    }
    $sentDay = (int) val("SELECT COUNT(*) FROM email_campaign_recipients r JOIN email_campaigns m ON m.id = r.campaign_id WHERE m.channel = 'email' AND r.status = 'sent' AND r.sent_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $budget = min(CAMPAIGN_PER_RUN, CAMPAIGN_PER_DAY - $sentDay);
    if ($budget <= 0) {
        $done['held'] = 'daily limit of ' . CAMPAIGN_PER_DAY . ' reached';
        cm_finish();
        return $done;
    }
    $vacancies = [];
    foreach (rows("SELECT r.id, r.campaign_id, r.candidate_id, r.email, r.token, c.full_name, w.user_id,
                          EXISTS (SELECT 1 FROM contact_preferences cp WHERE cp.candidate_id = r.candidate_id AND cp.no_email_since IS NOT NULL) AS opted_out
                   FROM email_campaign_recipients r JOIN email_campaigns m ON m.id = r.campaign_id JOIN candidates c ON c.id = r.candidate_id
                   LEFT JOIN worker_accounts w ON w.candidate_id = c.id
                   WHERE r.status = 'queued' AND m.status = 'sending' AND m.channel = 'email' ORDER BY r.campaign_id, r.id LIMIT " . (int) $budget) as $r) {
        $campaign = cm_campaign((int) $r['campaign_id']);
        $vacancies[(int) $campaign['vacancy_id']] ??= cm_vacancy((int) $campaign['vacancy_id']);
        $vacancy = $vacancies[(int) $campaign['vacancy_id']];
        if ((int) $r['opted_out'] || ! $vacancy) {
            q("UPDATE email_campaign_recipients SET status = 'skipped', error = ? WHERE id = ?", [(int) $r['opted_out'] ? 'unsubscribed' : 'requisition closed', (int) $r['id']]);
            $done['skipped']++;
            continue;
        }
        $mail = cm_render($campaign, $vacancy, $r, (string) $r['token']);
        $ok = cm_mail((string) $r['email'], $mail['subject'], $mail['body'], cm_headers($campaign, $mail['unsubscribe']));
        q('UPDATE email_campaign_recipients SET status = ?, sent_at = ?, error = ? WHERE id = ?', [$ok ? 'sent' : 'failed', $ok ? date('Y-m-d H:i:s') : null, $ok ? null : 'relay refused', (int) $r['id']]);
        if ($ok) {
            q('INSERT INTO candidate_events (candidate_id, event_type, detail) VALUES (?,?,?)', [(int) $r['candidate_id'], 'campaign email', mb_substr((string) $campaign['vacancy_title'] . ': ' . $mail['subject'], 0, 400)]);
        }
        $done[$ok ? 'sent' : 'failed']++;
    }
    cm_finish();

    return $done;
}

/** Campaigns with nobody left to send to are sent. (P5-M29: also when email is held and only texts went.) */
function cm_finish(): void
{
    foreach (rows("SELECT id FROM email_campaigns m WHERE status = 'sending' AND NOT EXISTS (SELECT 1 FROM email_campaign_recipients r WHERE r.campaign_id = m.id AND r.status = 'queued')") as $finished) {
        q("UPDATE email_campaigns SET status = 'sent', finished_at = NOW() WHERE id = ?", [(int) $finished['id']]);
    }
}

/**
 * P5-M29: the texts of a run, through Twilio: up to CAMPAIGN_PER_RUN, never
 * more than CAMPAIGN_TEXTS_PER_DAY in any 24 hours. Someone who replied STOP
 * since the list was drawn is skipped. In Twilio's sandbox each text is
 * recorded on the person and marked so, and nothing leaves Crewvia.
 */
function cm_deliver_texts(array &$done): void
{
    $queued = "FROM email_campaign_recipients r JOIN email_campaigns m ON m.id = r.campaign_id WHERE r.status = 'queued' AND m.status = 'sending' AND m.channel = 'text'";
    if (! (int) val('SELECT COUNT(*) ' . $queued)) {
        return;
    }
    if ($why = cm_not_ready('text')) {
        $done['held'] = $why;
        return;
    }
    $sentDay = (int) val("SELECT COUNT(*) FROM email_campaign_recipients r JOIN email_campaigns m ON m.id = r.campaign_id WHERE m.channel = 'text' AND r.status = 'sent' AND r.sent_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $budget = min(CAMPAIGN_PER_RUN, CAMPAIGN_TEXTS_PER_DAY - $sentDay);
    if ($budget <= 0) {
        $done['held'] = 'daily limit of ' . CAMPAIGN_TEXTS_PER_DAY . ' texts reached';
        return;
    }
    $vacancies = [];
    foreach (rows("SELECT r.id, r.campaign_id, r.candidate_id, r.token, c.full_name, w.user_id,
                          EXISTS (SELECT 1 FROM contact_preferences cp WHERE cp.candidate_id = r.candidate_id AND cp.no_texts_since IS NOT NULL) AS opted_out
                   " . str_replace('WHERE', 'JOIN candidates c ON c.id = r.candidate_id LEFT JOIN worker_accounts w ON w.candidate_id = c.id WHERE', $queued) . '
                   ORDER BY r.campaign_id, r.id LIMIT ' . (int) $budget) as $r) {
        $campaign = cm_campaign((int) $r['campaign_id']);
        $vacancies[(int) $campaign['vacancy_id']] ??= cm_vacancy((int) $campaign['vacancy_id']);
        $vacancy = $vacancies[(int) $campaign['vacancy_id']];
        if ((int) $r['opted_out'] || ! $vacancy) {
            q("UPDATE email_campaign_recipients SET status = 'skipped', error = ? WHERE id = ?", [(int) $r['opted_out'] ? 'asked not to be texted' : 'requisition closed', (int) $r['id']]);
            $done['skipped']++;
            continue;
        }
        $text = cm_render($campaign, $vacancy, $r, (string) $r['token'])['body'];
        [$why, $how] = tw_send_text((int) $r['candidate_id'], 0, $text, (int) $campaign['created_by']);
        q('UPDATE email_campaign_recipients SET status = ?, sent_at = ?, error = ? WHERE id = ?',
          [$why === null ? 'sent' : 'failed', $why === null ? date('Y-m-d H:i:s') : null, $why === null ? ($how === 'sandbox' ? 'sandbox: recorded, not sent' : null) : mb_substr($why, 0, 255), (int) $r['id']]);
        if ($why === null) {
            q('INSERT INTO candidate_events (candidate_id, event_type, detail) VALUES (?,?,?)', [(int) $r['candidate_id'], 'campaign text', mb_substr((string) $campaign['vacancy_title'] . ': ' . $text, 0, 255)]);
        }
        $done[$why === null ? 'sent' : 'failed']++;
    }
}

/** The recipient a link's token names, or null. */
function cm_recipient(string $token): ?array
{
    return preg_match('/^[a-f0-9]{64}$/', $token) ? (row('SELECT r.*, c.full_name FROM email_campaign_recipients r JOIN candidates c ON c.id = r.candidate_id WHERE r.token = ?', [$token]) ?: null) : null;
}

/** "Stop these emails": recorded on the person, and nothing still queued for them goes. */
function cm_unsubscribe(array $recipient): void
{
    $id = (int) $recipient['candidate_id'];
    q('INSERT INTO contact_preferences (candidate_id, no_email_since) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE no_email_since = COALESCE(no_email_since, NOW())', [$id]);
    q("UPDATE email_campaign_recipients SET status = 'skipped', error = 'unsubscribed' WHERE candidate_id = ? AND status = 'queued'", [$id]);
    q('INSERT INTO candidate_events (candidate_id, event_type, detail) VALUES (?,?,?)', [$id, 'contact_preference', 'no campaign emails: unsubscribed by the link in campaign ' . (int) $recipient['campaign_id']]);
}
