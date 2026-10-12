<?php
/**
 * P5-M06c: make the agency's push key pair (VAPID, P-256), once.
 *
 *   php install/push-keys.php
 *
 * The private key is stored sealed with the encryption key, never printed
 * and never written to a file. Running it again changes nothing: a new key
 * pair would cut off every phone already subscribed. Making the keys does
 * not turn push on; an administrator does that under Trusted phones.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/push.php';

try {
    $public = push_keys_make();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

echo $public === null
    ? 'Push keys: already in place; nothing changed.' . PHP_EOL
    : 'Push keys: made. Push stays off until an administrator turns it on under Trusted phones.' . PHP_EOL;
