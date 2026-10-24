<?php
/**
 * P5-M47: where an email to a candidate actually goes, and who it answers to.
 *
 * While the agency tests its flows on real applicants, nothing may reach
 * them: in test mode every email to a candidate or a worker (anybody who is
 * not office staff) is delivered to a test inbox instead - by default
 * solutionsfleury+<first name>@gmail.com - with "[TEST for <name>]" in front
 * of the subject, exactly as the candidate would get it otherwise. Their real
 * address stays on their record; leaving test mode (Email delivery, an
 * administrator) is all it takes for the real people to receive the real
 * emails.
 *
 * Emails to office staff (administrators, recruiters, logistics, payroll,
 * supervisors, clients) are never redirected.
 *
 * Candidate emails carry a Reply-To (by default the recruiting lead,
 * pa@rsinc.com), so an answer reaches a person; the technical sender stays
 * the server's configured address (email_from), which the mail relay is
 * allowed to send for.
 */

declare(strict_types=1);

function mail_setting(string $key, string $default = ''): string
{
    try {
        $v = val('SELECT setting_value FROM platform_settings WHERE setting_key = ?', [$key]);
    } catch (Throwable $ignored) {
        return $default;
    }

    return $v === null || $v === false ? $default : (string) $v;
}

/** Is test mode on? */
function mail_test_mode(): bool
{
    return mail_setting('candidate_mail_test', '0') === '1';
}

/**
 * P5-M105b: a rehearsal - the office's emails go to the test inbox too, so a run-through on the live workspace emails
 * no real colleague. Only while test mode is on.
 */
function mail_office_test(): bool
{
    return mail_test_mode() && mail_setting('office_mail_test', '0') === '1';
}

/** P5-M105b: during a rehearsal, the in-app notices too go only to the test accounts (when the agency asks). */
function mail_notice_test(): bool
{
    return mail_office_test() && mail_setting('office_notice_test', '0') === '1';
}

/** Since when a rehearsal is on ('' when it is off). */
function mail_office_test_since(): string
{
    return mail_office_test() ? mail_setting('office_mail_test_since', '') : '';
}

/** An address that already is the test inbox (solutionsfleury+anything@gmail.com) is delivered as it is. */
function mail_is_test_inbox(string $email): bool
{
    [$before, $after] = array_pad(explode('{first}', mail_test_pattern(), 2), 2, '');

    return $before !== '' && str_starts_with(strtolower(trim($email)), strtolower($before)) && str_ends_with(strtolower(trim($email)), strtolower($after));
}

/** The test inbox, with {first} for the candidate's first name. */
function mail_test_pattern(): string
{
    return mail_setting('candidate_mail_test_to', 'solutionsfleury+{first}@gmail.com');
}

/** Where a candidate's answer goes; '' for none. */
function mail_reply_to(): string
{
    $v = mail_setting('candidate_mail_reply_to', '');

    return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : '';
}

/** Office staff are never redirected; everybody else (candidates, workers, unknown addresses) is a candidate here. */
function mail_is_staff(string $email): bool
{
    return (bool) val("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND role IN ('admin','recruiter','hotels','payroll','supervisor','client')", [trim($email)]);
}

/** The name an address belongs to: the latest candidate record, else the account. */
function mail_name_for(string $email): string
{
    $name = (string) (val('SELECT full_name FROM candidates WHERE LOWER(email) = LOWER(?) ORDER BY id DESC LIMIT 1', [trim($email)])
                      ?: val('SELECT name FROM users WHERE LOWER(email) = LOWER(?)', [trim($email)]) ?: '');

    return trim($name);
}

/**
 * The tag in the test address: the first name, in plain letters; with the
 * last name's initial when another candidate has the same first name, so two
 * Andrews do not land in the same tag.
 */
function mail_test_tag(string $email, string $name): string
{
    $plain = static fn(string $s): string => strtolower((string) preg_replace('/[^A-Za-z]/', '', (string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s)));
    $parts = preg_split('/[\s,]+/', trim($name)) ?: [];
    $first = $plain((string) ($parts[0] ?? ''));
    if ($first === '') {
        return $plain((string) strtok($email, '@')) ?: 'candidate';
    }
    $last = $plain((string) (count($parts) > 1 ? end($parts) : ''));
    $same = (int) val("SELECT COUNT(DISTINCT LOWER(email)) FROM candidates WHERE LOWER(email) <> LOWER(?) AND email IS NOT NULL AND email <> ''
                       AND LOWER(SUBSTRING_INDEX(TRIM(full_name), ' ', 1)) = ?", [trim($email), $first]);

    return $same > 0 && $last !== '' ? $first . $last[0] : $first;
}

/**
 * Where to deliver an email, and what to put in front of its subject.
 *
 * @return array{to:string,prefix:string,redirected:bool}
 */
function mail_route(string $to): array
{
    if (! mail_test_mode() || mail_is_test_inbox($to) || (mail_is_staff($to) && ! mail_office_test())) {
        return ['to' => $to, 'prefix' => '', 'redirected' => false];
    }
    $name = mail_name_for($to);
    $address = str_replace('{first}', mail_test_tag($to, $name), mail_test_pattern());

    return ['to' => $address, 'prefix' => '[TEST for ' . ($name !== '' ? $name : $to) . '] ', 'redirected' => true];
}

/** Headers for an email to a candidate: the Reply-To added unless one is already set (a campaign answers to its recruiter). */
function mail_candidate_headers(array $headers, string $to): array
{
    $reply = mail_reply_to();
    if ($reply !== '' && ! isset($headers['Reply-To']) && ! mail_is_staff($to)) {
        $headers['Reply-To'] = $reply;
    }

    return $headers;
}
