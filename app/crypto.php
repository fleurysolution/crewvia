<?php
/**
 * The workspace's encryption: AES-256-GCM, stored as IV (12 bytes), tag
 * (16) and ciphertext. Database values hold it base64-encoded, document
 * files hold the raw bytes.
 *
 * Two keys can be configured while the key is being rotated (GAP-1002,
 * install/rotate-key.php): encryption_key, used for everything written,
 * and previous_encryption_key, still accepted for reading what was
 * written before. Once every value and document is re-encrypted, the
 * previous key is removed and only the new one opens anything.
 */

declare(strict_types=1);

/** A configured key as raw bytes, or null when it is missing or not 32 bytes. */
function crypto_raw_key(?string $b64): ?string
{
    $raw = $b64 === null ? false : base64_decode($b64, true);
    return $raw !== false && strlen($raw) === 32 ? $raw : null;
}

function crypto_key_ready(): bool
{
    global $config;
    return crypto_raw_key($config['encryption_key'] ?? null) !== null;
}

/** Encrypts with the current key. */
function crypto_seal(string $plain, ?string $key = null): string
{
    global $config;
    $key ??= crypto_raw_key($config['encryption_key'] ?? null);
    if ($key === null) {
        throw new RuntimeException('Configure a 32-byte encryption key.');
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed.');
    }
    return $iv . $tag . $cipher;
}

/** Decrypts with one key, or null when that key does not open it. */
function crypto_open_with(string $raw, string $key): ?string
{
    if (strlen($raw) < 28) {
        return null;
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? null : $plain;
}

/** Decrypts with the current key, then the previous one during a rotation; null when neither opens it. */
function crypto_open(string $raw): ?string
{
    global $config;
    foreach (['encryption_key', 'previous_encryption_key'] as $name) {
        $key = crypto_raw_key($config[$name] ?? null);
        if ($key !== null && ($plain = crypto_open_with($raw, $key)) !== null) {
            return $plain;
        }
    }
    return null;
}
