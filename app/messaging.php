<?php
/**
 * Texting and calling (P5-M07) through the staff member's own phone.
 *
 * No SMS or calling provider is connected and Crewvia sends nothing itself.
 * A text is written here from wording kept once (message_templates), filled
 * from what Crewvia already holds about the person, their assignment, hotel
 * and travel, and edited if need be. It then opens:
 *   - in the phone's texting app (an sms: link). On Android that can be
 *     Google Voice; on an iPhone it is always Messages, which sends from
 *     the phone's own number;
 *   - or, copied, in Google Voice (voice.google.com or its app), which
 *     sends from the agency's Google Voice number on any device.
 * Opening it logs it in the existing contact register (candidate_calls,
 * outcome "texted"), so "last contacted" and the history stay one record.
 * Calls stay tel: links and are logged by their outcome, as before.
 *
 * Someone who asked not to be texted (or called) is recorded once, with
 * since when and why; Crewvia then neither offers nor logs a text (or a
 * call) to them until a recruiter records otherwise.
 */

declare(strict_types=1);

const MSG_PLACEHOLDERS = ['first_name', 'name', 'sender', 'agency', 'project', 'start_date', 'hotel', 'hotel_address', 'room', 'check_in', 'travel', 'pickup'];
const MSG_GOOGLE_VOICE = 'https://voice.google.com/u/0/messages';

/** @return array<string,string> */
function msg_languages(): array
{
    return ['en' => 'English', 'es' => 'Español', 'fr' => 'Français'];
}

/** @return array<string,string> */
function msg_purposes(): array
{
    return ['candidate' => t('Recruiting: from the person\'s page'), 'placement' => t('Assignment: from the placement, with hotel and travel')];
}

/**
 * A telephone number as a link wants it: digits with a leading +. Ten
 * digits are taken as North American (+1). Null when it cannot be a number.
 */
function msg_phone(?string $phone): ?string
{
    $raw = trim((string) $phone);
    $digits = preg_replace('/\D/', '', $raw);
    if ($digits === '' || strlen($digits) < 7 || strlen($digits) > 15) {
        return null;
    }
    if (str_starts_with($raw, '+')) {
        return '+' . $digits;
    }
    if (strlen($digits) === 10) {
        return '+1' . $digits;
    }
    if (strlen($digits) === 11 && $digits[0] === '1') {
        return '+' . $digits;
    }

    return $digits;
}

/** sms: with the message written in. "?&body=" opens it on both Android and iPhone. */
function msg_sms_link(string $phone, string $body): string
{
    return 'sms:' . $phone . '?&body=' . rawurlencode($body);
}

function msg_preferences(int $candidateId): array
{
    return row('SELECT * FROM contact_preferences WHERE candidate_id = ?', [$candidateId]) ?: ['no_texts_since' => null, 'no_calls_since' => null, 'reason' => null];
}

/** Record that a person asked not to be texted or called, or that they may be again. */
function msg_preferences_save(int $candidateId, bool $noTexts, bool $noCalls, string $reason, int $by): ?string
{
    $reason = trim($reason);
    if (! row('SELECT id FROM candidates WHERE id = ?', [$candidateId])) { return t('That person does not exist.'); }
    if (($noTexts || $noCalls) && (mb_strlen($reason) < 3 || mb_strlen($reason) > 255)) { return t('Say how they asked, in 3 to 255 characters (for example: replied STOP).'); }
    $old = msg_preferences($candidateId);
    q('INSERT INTO contact_preferences (candidate_id, no_texts_since, no_calls_since, reason, recorded_by) VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE no_texts_since = VALUES(no_texts_since), no_calls_since = VALUES(no_calls_since), reason = VALUES(reason), recorded_by = VALUES(recorded_by)',
      [$candidateId, $noTexts ? ($old['no_texts_since'] ?? date('Y-m-d H:i:s')) : null, $noCalls ? ($old['no_calls_since'] ?? date('Y-m-d H:i:s')) : null,
       ($noTexts || $noCalls) ? $reason : null, $by]);
    q('INSERT INTO candidate_events (candidate_id, user_id, event_type, detail) VALUES (?,?,?,?)',
      [$candidateId, $by, 'contact_preference', ($noTexts ? 'no texts' : 'texts allowed') . ', ' . ($noCalls ? 'no calls' : 'calls allowed') . ($reason !== '' ? ': ' . mb_substr($reason, 0, 200) : '')]);

    return null;
}

// ── templates ───────────────────────────────────────────────────────────

/** @return array{0:?string,1:int} */
function msg_template_save(array $in, int $by, int $id = 0): array
{
    $name = trim((string) ($in['name'] ?? ''));
    $language = (string) ($in['language'] ?? 'en');
    $purpose = (string) ($in['purpose'] ?? 'candidate');
    $body = trim((string) ($in['body'] ?? ''));
    if (mb_strlen($name) < 3 || mb_strlen($name) > 120) { return [t('Give the message a name of 3 to 120 characters.'), 0]; }
    if (! isset(msg_languages()[$language])) { return [t('Choose a language.'), 0]; }
    if (! isset(msg_purposes()[$purpose])) { return [t('Say where it is sent from.'), 0]; }
    if (mb_strlen($body) < 5 || mb_strlen($body) > 1000) { return [t('Write the message in 5 to 1000 characters.'), 0]; }
    preg_match_all('/\{([a-z_]+)\}/', $body, $m);
    $unknown = array_values(array_diff(array_unique($m[1]), MSG_PLACEHOLDERS));
    if ($unknown) { return [t('Unknown placeholder: :list. Use: :known.', ['list' => '{' . implode('}, {', $unknown) . '}', 'known' => '{' . implode('}, {', MSG_PLACEHOLDERS) . '}']), 0]; }
    if ($purpose === 'candidate' && array_intersect($m[1], ['project', 'start_date', 'hotel', 'hotel_address', 'room', 'check_in', 'travel', 'pickup'])) {
        return [t('Assignment details are only known from a placement: send this one from the placement.'), 0];
    }
    $dupe = (int) val('SELECT id FROM message_templates WHERE name = ? AND language = ? AND id <> ?', [$name, $language, $id]);
    if ($dupe) { return [t('A message with that name already exists in that language.'), 0]; }
    if ($id) {
        if (! row('SELECT id FROM message_templates WHERE id = ?', [$id])) { return [t('That message does not exist.'), 0]; }
        q('UPDATE message_templates SET name = ?, language = ?, purpose = ?, body = ?, is_active = ? WHERE id = ?', [$name, $language, $purpose, $body, ! empty($in['is_active']) ? 1 : 0, $id]);
        log_activity('changed a text message', 'message_template', $id, $name);

        return [null, $id];
    }
    q('INSERT INTO message_templates (name, language, purpose, body, created_by) VALUES (?,?,?,?,?)', [$name, $language, $purpose, $body, $by]);
    $id = (int) db()->lastInsertId();
    log_activity('created a text message', 'message_template', $id, $name);

    return [null, $id];
}

function msg_templates(string $purpose): array
{
    // A placement can also send the recruiting messages; a person's page only those.
    return $purpose === 'placement'
        ? rows("SELECT * FROM message_templates WHERE is_active = 1 ORDER BY purpose = 'placement' DESC, name, language")
        : rows("SELECT * FROM message_templates WHERE is_active = 1 AND purpose = 'candidate' ORDER BY name, language");
}

/** What the placeholders stand for, for one person and, if given, one assignment. */
function msg_values(int $candidateId, int $placementId = 0): array
{
    global $config;
    $c = row('SELECT full_name FROM candidates WHERE id = ?', [$candidateId]) ?: ['full_name' => ''];
    $brand = (string) (val("SELECT setting_value FROM platform_settings WHERE setting_key = 'brand_name'") ?: ($config['app_name'] ?? 'Crewvia'));
    $v = ['first_name' => (string) strtok((string) $c['full_name'], ' '), 'name' => (string) $c['full_name'], 'sender' => (string) (user()['name'] ?? ''), 'agency' => $brand];
    if ($placementId) {
        $p = row('SELECT p.start_date, j.title FROM placements p JOIN jobs j ON j.id = p.job_id WHERE p.id = ? AND p.candidate_id = ?', [$placementId, $candidateId]);
        if ($p) {
            $v['project'] = (string) $p['title'];
            $v['start_date'] = $p['start_date'] ? d((string) $p['start_date']) : '';
            $l = row("SELECT h.name, CONCAT_WS(', ', h.address, h.city, h.state) AS address, l.room_number, l.check_in FROM lodging l JOIN hotels h ON h.id = l.hotel_id
                      WHERE l.placement_id = ? AND l.status <> 'cancelled' ORDER BY l.id DESC LIMIT 1", [$placementId]);
            if ($l) {
                $v += ['hotel' => (string) $l['name'], 'hotel_address' => (string) $l['address'], 'room' => (string) $l['room_number'], 'check_in' => $l['check_in'] ? d((string) $l['check_in']) : ''];
            }
            $legs = rows("SELECT * FROM travel WHERE placement_id = ? AND status <> 'cancelled' AND direction = 'inbound' ORDER BY depart_time, id", [$placementId]);
            if ($legs) {
                $v['travel'] = implode('; ', array_map(static fn($t) => trim(implode(' ', array_filter([(string) $t['carrier'], (string) $t['reference'],
                    $t['depart_from'] ? (string) $t['depart_from'] . ($t['depart_time'] ? ' ' . substr((string) $t['depart_time'], 0, 16) : '') : '',
                    $t['arrive_at'] ? '→ ' . (string) $t['arrive_at'] . ($t['arrive_time'] ? ' ' . substr((string) $t['arrive_time'], 0, 16) : '') : '']))), $legs));
                $pick = array_values(array_filter(array_map(static fn($t) => (int) $t['pickup_needed'] ? (string) ($t['pickup_by'] ?: t('arranged')) : '', $legs)));
                $v['pickup'] = $pick ? $pick[0] : t('none needed');
            }
        }
    }

    return $v;
}

/**
 * A message filled in. What is not known stays visible, in brackets, so it
 * is never sent with a hole in it unnoticed.
 *
 * @return array{text:string,missing:array<int,string>}
 */
function msg_render(string $body, array $values): array
{
    $missing = [];
    $text = preg_replace_callback('/\{([a-z_]+)\}/', static function ($m) use ($values, &$missing) {
        $v = trim((string) ($values[$m[1]] ?? ''));
        if ($v === '') {
            $missing[] = $m[1];
            return '[' . $m[1] . ']';
        }
        return $v;
    }, $body);

    return ['text' => (string) $text, 'missing' => array_values(array_unique($missing))];
}

/** Everything the text panel needs for one person (and assignment). */
function msg_panel(int $candidateId, int $placementId = 0): array
{
    $c = row('SELECT id, full_name, phone FROM candidates WHERE id = ?', [$candidateId]);
    $pref = msg_preferences($candidateId);
    $values = msg_values($candidateId, $placementId);
    $messages = [];
    foreach (msg_templates($placementId ? 'placement' : 'candidate') as $tpl) {
        $messages[] = ['id' => (int) $tpl['id'], 'name' => $tpl['name'], 'language' => $tpl['language']] + msg_render((string) $tpl['body'], $values);
    }

    return ['candidate' => $c, 'placement_id' => $placementId, 'phone' => msg_phone($c['phone'] ?? null), 'preferences' => $pref, 'messages' => $messages];
}

/**
 * A text was opened on the phone: log it in the contact register, as a
 * call is. Refused for someone who asked not to be texted.
 */
function msg_log_text(int $candidateId, int $templateId, string $text, int $by): ?string
{
    $c = row('SELECT id, stage, phone FROM candidates WHERE id = ?', [$candidateId]);
    if (! $c) { return t('That person does not exist.'); }
    if (msg_phone($c['phone']) === null) { return t('No telephone number on file'); }
    if (msg_preferences($candidateId)['no_texts_since'] !== null) { return t('This person asked not to be texted.'); }
    if ($templateId && ! row('SELECT id FROM message_templates WHERE id = ?', [$templateId])) { return t('That message does not exist.'); }
    $name = $templateId ? (string) val('SELECT name FROM message_templates WHERE id = ?', [$templateId]) : '';
    $note = mb_substr(trim(($name !== '' ? $name . ': ' : '') . trim($text)), 0, 2000);
    q("INSERT INTO candidate_calls (candidate_id, user_id, outcome, template_id, note) VALUES (?,?,'texted',?,?)", [$candidateId, $by, $templateId ?: null, $note ?: null]);
    q('UPDATE candidates SET last_contact_at = NOW(), owner_id = COALESCE(owner_id, ?)' . ($c['stage'] === 'new' ? ", stage = 'contacted'" : '') . ' WHERE id = ?', [$by, $candidateId]);

    return null;
}
