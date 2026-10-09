<?php
/**
 * Prove the mail relay works, to one address, before the product sends to
 * anybody.
 *
 * Outbound email is held behind email_delivery_enabled, and the queue holds
 * invitations addressed to real people. Switching the flag on to find out
 * whether mail works would release that queue at the same moment - the test
 * and the consequence arriving together. This sends one message to one
 * address given on the command line and touches no queue, so the relay can be
 * proven first and the queue released as a separate, deliberate decision.
 *
 *   php install/mail-test.php you@example.com
 *
 * mail() returning true means the local mail server accepted the message for
 * delivery. It does not mean it arrived, and it never means it reached the
 * inbox rather than the spam folder. Only the recipient can confirm that.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/../app/bootstrap.php';

$to = trim((string) ($argv[1] ?? ''));

if (! filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) {
    exit("Usage: php install/mail-test.php recipient@example.com\n"
       . "One address, given explicitly. Nothing is sent without it.\n");
}

global $config;

$from = (string) ($config['email_from'] ?? '');
$host = parse_url((string) ($config['app_url'] ?? ''), PHP_URL_HOST) ?: 'localhost';

echo "Mail configuration\n";
echo str_repeat('-', 64) . "\n";
printf("  %-26s %s\n", 'email_delivery_enabled',
       ! empty($config['email_delivery_enabled']) ? 'on' : 'off (this test ignores it on purpose)');
printf("  %-26s %s\n", 'email_from', $from !== '' ? $from : 'NOT SET');
printf("  %-26s %s\n", 'app_url host', $host);
printf("  %-26s %s\n", 'mail() available', function_exists('mail') ? 'yes' : 'NO - nothing can be sent');
printf("  %-26s %s\n", 'sendmail_path', ini_get('sendmail_path') ?: '(php default)');

if (! function_exists('mail')) {
    exit("\nPHP has no mail(). Ask the host to enable it, or configure SMTP.\n");
}

if (! filter_var($from, FILTER_VALIDATE_EMAIL)) {
    echo "\nemail_from is not a valid address, so the relay has no sender to use.\n";
    echo "Set it in config.php to a mailbox on a domain whose SPF authorises this\n";
    echo "server, then run this again. Nothing was sent.\n";
    exit(1);
}

// What is waiting, so switching delivery on later is never a surprise.
$queued = 0;
$accountQueued = 0;

try {
    $queued = (int) val("SELECT COUNT(*) FROM email_outbox o
                         JOIN invitations i ON i.id = o.invitation_id
                         WHERE i.used_at IS NULL AND i.expires_at > NOW()
                           AND o.status IN ('pending','sending') AND o.attempts < 5");
    $accountQueued = (int) val("SELECT COUNT(*) FROM account_mail_outbox o
                                JOIN password_resets r ON r.id = o.reset_id
                                WHERE r.used_at IS NULL AND r.expires_at > NOW()
                                  AND o.status IN ('pending','sending') AND o.attempts < 5");
} catch (Throwable $e) {
    echo "\n  (could not read the outbox: " . $e->getMessage() . ")\n";
}

echo "\nWaiting in the queue, untouched by this test\n";
echo str_repeat('-', 64) . "\n";
printf("  %-26s %d\n", 'invitation messages', $queued);
printf("  %-26s %d\n", 'account messages', $accountQueued);

if ($queued || $accountQueued) {
    echo "  These go out to real people the moment delivery is enabled and\n";
    echo "  install/deliver-emails.php runs. This test sends none of them.\n";
}

// ── the one message ──────────────────────────────────────────────────────
$stamp   = date('Y-m-d H:i:s T');
$subject = sprintf('%s mail relay test - %s', $config['app_name'] ?? 'Crewvia', $stamp);

$body = <<<TEXT
This is a relay test sent from {$host}.

Nothing in the application sent it and no queued message was released: it was
sent by install/mail-test.php, to this address only, to establish whether mail
leaves this server and reaches an inbox.

  Sent at   {$stamp}
  From      {$from}
  Server    {$host}

If this landed in spam rather than the inbox, the relay works and the domain's
reputation is what needs attention - SPF, DKIM and DMARC on the sending domain.

No reply is needed.
TEXT;

$headers = [
    'From'         => $from,
    'Reply-To'     => $from,
    'Content-Type' => 'text/plain; charset=UTF-8',
    'X-Mailer'     => ($config['app_name'] ?? 'Crewvia') . ' relay test',
    'Auto-Submitted' => 'auto-generated',
];

echo "\nSending one message\n";
echo str_repeat('-', 64) . "\n";
printf("  %-26s %s\n", 'to', $to);
printf("  %-26s %s\n", 'from', $from);
printf("  %-26s %s\n", 'subject', $subject);

$encoded  = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$accepted = mail($to, $encoded, $body, $headers, '-f' . $from);

echo "\n";

if ($accepted) {
    echo "  The local mail server ACCEPTED the message.\n\n";
    echo "  That is as far as this script can see. Check the inbox - and the\n";
    echo "  spam folder - for the subject above. If it does not arrive within a\n";
    echo "  few minutes, the mail log on this server says why:\n\n";
    echo "    grep -i " . escapeshellarg($to) . " /var/log/exim_mainlog | tail -20\n";
    exit(0);
}

echo "  The local mail server REFUSED the message.\n\n";
echo "  Nothing left this machine. The usual causes are a sender address on a\n";
echo "  domain this server may not send for, or mail() disabled by the host.\n";
exit(1);
