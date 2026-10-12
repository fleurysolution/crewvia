<?php
/**
 * /sync (P5-M06b): what a trusted phone talks to. No web session: the
 * device cookie is the only way in (see app/offline-sync.php).
 *
 *   GET  /sync/bootstrap   what the phone may keep
 *   POST /sync/push        {"entries":[...]}: each answered on its own
 *
 * Cross-site defence instead of a form token: the cookie is SameSite=Strict,
 * every call must carry the X-Crewvia-Sync header (which a cross-site form
 * cannot send, and which no CORS rule here allows), a push must be JSON, and
 * an Origin, when the browser sends one, must be this site.
 */

declare(strict_types=1);

require_once __DIR__ . '/../offline-sync.php';

header('Content-Type: application/json; charset=utf-8');
$reply = static function (int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['HTTP_X_CREWVIA_SYNC'] ?? '') !== '1') {
    $reply(403, ['error' => 'header']);
}
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && strtolower((string) parse_url($origin, PHP_URL_HOST)) !== strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')))) {
    $reply(403, ['error' => 'origin']);
}
$grant = os_current_grant();
if (! $grant) {
    $reply(401, ['error' => 'not_trusted']);
}
// The phone's owner's language, for every word in the answer.
if (isset(WORKFORCE_LOCALES[(string) $grant['locale']])) {
    $_GET['lang'] = (string) $grant['locale'];
}
q('UPDATE device_grants SET last_used_at = NOW() WHERE id = ?', [(int) $grant['id']]);

if ($path === '/sync/bootstrap') {
    if ($method !== 'GET') { $reply(405, ['error' => 'method']); }
    $reply(200, os_bootstrap($grant));
}

if ($path === '/sync/push') {
    if ($method !== 'POST') { $reply(405, ['error' => 'method']); }
    if (! str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) { $reply(415, ['error' => 'json']); }
    $raw = (string) file_get_contents('php://input', false, null, 0, 262145);
    if (strlen($raw) > 262144) { $reply(413, ['error' => 'size']); }
    $body = json_decode($raw, true);
    $entries = is_array($body) && is_array($body['entries'] ?? null) ? array_values($body['entries']) : null;
    if ($entries === null) { $reply(400, ['error' => 'entries']); }
    if (count($entries) > OS_MAX_ENTRIES) { $reply(413, ['error' => 'too_many']); }
    $results = [];
    foreach ($entries as $e) {
        $results[] = os_apply($grant, is_array($e) ? $e : []);
    }
    $reply(200, ['results' => $results]);
}

$reply(404, ['error' => 'path']);
