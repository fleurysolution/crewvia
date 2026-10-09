<?php
declare(strict_types=1);

/** Resolve an explicitly registered host before opening any session/database. */
function workforce_tenant_config(array $base, array $server, string $root, bool $cli = false): array
{
    $map = $base['tenant_hosts'] ?? [];
    if (!$map) return $base;
    if (!is_array($map)) throw new RuntimeException('Invalid workspace registry.');
    $host = strtolower(trim((string)($server['HTTP_HOST'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host);
    if ($cli) $host = strtolower(trim((string)getenv('WORKFORCE_TENANT_HOST')));
    if (!$host || !preg_match('/^[a-z0-9.-]+$/D', $host) || !array_key_exists($host, $map)) {
        throw new RuntimeException('Workspace unavailable.');
    }
    $directory = realpath($root . '/tenants');
    $file = $directory ? realpath($directory . '/' . $map[$host]) : false;
    if (!$file || !str_starts_with($file, $directory . DIRECTORY_SEPARATOR) || !is_file($file)) {
        throw new RuntimeException('Workspace configuration unavailable.');
    }
    $tenant = require $file;
    if (!is_array($tenant) || !preg_match('/^[a-z0-9-]{3,64}$/D', (string)($tenant['tenant_id'] ?? ''))) {
        throw new RuntimeException('Workspace identity unavailable.');
    }
    foreach (['db_host','db_name','db_user','db_pass','app_url','encryption_key'] as $key) {
        if (!array_key_exists($key, $tenant)) throw new RuntimeException('Incomplete workspace configuration.');
    }
    $url = parse_url($tenant['app_url']);
    if (($url['scheme'] ?? '') !== 'https' || strtolower($url['host'] ?? '') !== $host) {
        throw new RuntimeException('Workspace URL does not match registered host.');
    }
    $key = base64_decode($tenant['encryption_key'], true);
    if (!$key || strlen($key) !== 32) throw new RuntimeException('Workspace encryption key unavailable.');
    return array_merge($base, $tenant);
}

function workforce_storage_path(array $config): string
{
    $base = __DIR__ . '/../storage';
    if (empty($config['tenant_id'])) return $base;
    if (!preg_match('/^[a-z0-9-]{3,64}$/D', (string)$config['tenant_id'])) throw new RuntimeException('Invalid workspace identity.');
    return $base . '/tenants/' . $config['tenant_id'];
}

function workforce_session_name(array $config): string
{
    return empty($config['tenant_id']) ? 'rssops' : 'workforce_' . substr(hash('sha256', $config['tenant_id']), 0, 20);
}
