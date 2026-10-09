<?php
/**
 * Staging and production pre-flight.
 *
 * Run this on the server, after deploying and before putting any real data
 * in. It checks the things that quietly expose a workforce platform: a
 * document root pointing at the wrong folder, private storage reachable over
 * HTTP, debug left on, a missing or shared encryption key, an external
 * service switched on by accident.
 *
 * Every check reports what it actually found rather than what it expected,
 * so a failure says what to change.
 *
 *   php install/preflight.php
 *       local checks only
 *
 *   php install/preflight.php https://crewvia.example.com
 *       also probes the site's own URL
 *
 *   php install/preflight.php https://crewvia.example.com https://main.example.com/crewvia
 *       ALSO probes the lateral path - the one that actually matters when
 *       the host forces a document root under public_html.
 *
 * That second URL is not optional padding. When cPanel puts the application
 * inside public_html, the account's main domain serves the folder directly:
 * https://main.example.com/crewvia/storage/... reaches private documents even
 * though the site's own document root is public/ one level down. Probing only
 * the site's URL tests a path where those files were never reachable anyway,
 * and reports green while the real door stands open.
 *
 * The lateral probe writes a canary file with a random marker, asks for it
 * over HTTP, and fails if the marker comes back. A redirect or a 200 with
 * other content proves nothing on its own - only the absence of the marker
 * does.
 *
 * Exit status is 0 when nothing failed, 1 otherwise, so it can gate a deploy.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

// Loaded for the helpers and $config. The database is reached separately
// below, because bootstrap exits the process when it cannot connect and a
// pre-flight must survive that to report everything else.
$GLOBALS['__preflight'] = true;
require __DIR__ . '/../app/bootstrap.php';

global $config;

$base    = rtrim((string) ($argv[1] ?? ''), '/');
$lateral = rtrim((string) ($argv[2] ?? ''), '/');
$pass = $warn = $fail = 0;
$lines = [];

function result(string $level, string $what, string $found): void
{
    global $pass, $warn, $fail, $lines;

    $level === 'ok'   && $pass++;
    $level === 'warn' && $warn++;
    $level === 'fail' && $fail++;

    $lines[] = sprintf('%-6s %-46s %s',
        strtoupper($level), $what, $found);
}

function ok(string $w, string $f = ''): void   { result('ok', $w, $f); }
function warn(string $w, string $f): void      { result('warn', $w, $f); }
function fail(string $w, string $f): void      { result('fail', $w, $f); }

// ── PHP and extensions ────────────────────────────────────────────────────
version_compare(PHP_VERSION, '8.1', '>=')
    ? ok('PHP version', PHP_VERSION)
    : fail('PHP version', PHP_VERSION . ' - 8.1 or newer required');

foreach (['pdo_mysql', 'openssl', 'mbstring', 'fileinfo'] as $ext) {
    extension_loaded($ext)
        ? ok('extension ' . $ext, 'loaded')
        : fail('extension ' . $ext, 'MISSING');
}

foreach (['curl' => 'outbound integrations', 'zip' => 'XLSX import',
          'dom' => 'XLSX import'] as $ext => $why) {
    extension_loaded($ext)
        ? ok('extension ' . $ext, 'loaded')
        : warn('extension ' . $ext, 'missing - ' . $why . ' will not work');
}

// ── Configuration ─────────────────────────────────────────────────────────
empty($config['debug'])
    ? ok('debug', 'off')
    : fail('debug', 'ON - stack traces would be shown to users');

$key = base64_decode((string) ($config['encryption_key'] ?? ''), true);

if (! $key || strlen($key) !== 32) {
    fail('encryption key', 'missing or not 32 bytes - documents cannot be stored');
} else {
    // A key that shipped in an example or a test is not a key.
    $known = ['bG9jYWwtdmVyaWZpY2F0aW9uLW9ubHktMzItYnl0ZS1rZXk=',
              'dGVzdC1rZXktbG9jYWwtdmVyaWZpY2F0aW9uLW9ubHktMzI='];

    in_array((string) $config['encryption_key'], $known, true)
        ? fail('encryption key', 'this is a published test key - generate a fresh one')
        : ok('encryption key', '32 bytes, not a known test key');
}

$url = (string) ($config['app_url'] ?? '');

if ($url === '') {
    fail('app_url', 'not set - QR codes and invitation links will be wrong');
} elseif (! str_starts_with($url, 'https://')) {
    str_contains($url, '127.0.0.1') || str_contains($url, 'localhost')
        ? warn('app_url', $url . ' - fine locally, must be https on a server')
        : fail('app_url', $url . ' - must be https');
} else {
    ok('app_url', $url);
}

// ── External services: the owner's decision is that these stay off ────────
foreach ([
    'email_delivery_enabled' => 'e-mail would be sent',
    'ai_enabled'             => 'AI calls would be billed',
] as $flag => $consequence) {
    empty($config[$flag])
        ? ok('service off: ' . $flag, 'disabled')
        : warn('service ON: ' . $flag, $consequence . ' - confirm this is intended');
}

(($config['commercial_mode'] ?? 'demo') === 'demo')
    ? ok('commercial_mode', 'demo')
    : warn('commercial_mode', (string) $config['commercial_mode'] . ' - billing behaviour changes');

empty($config['recruiting_webhooks'])
    ? ok('partner webhooks', 'none registered')
    : warn('partner webhooks', count((array) $config['recruiting_webhooks']) . ' registered');

// ── The two .htaccess that carry the protection ───────────────────────────
//
// Checked here, not assumed, because both ship inside the archive precisely
// so that extracting cannot leave one of them behind. If somebody adds them
// by hand after extraction, the next deployment overwrites them and this is
// the check that notices.
$rootHt   = dirname(__DIR__) . '/.htaccess';
$publicHt = dirname(__DIR__) . '/public/.htaccess';

if (! is_file($rootHt)) {
    fail('application .htaccess', 'MISSING - the folder is not denied to the web');
} elseif (! preg_match('/^\s*Require\s+all\s+denied/mi', (string) file_get_contents($rootHt))) {
    fail('application .htaccess', 'present but does not deny - check its contents');
} else {
    ok('application .htaccess', 'denies everything above public/');
}

if (! is_file($publicHt)) {
    fail('public/.htaccess', 'MISSING - the site will return 403 everywhere');
} elseif (! preg_match('/^\s*Require\s+all\s+granted/mi', (string) file_get_contents($publicHt))) {
    fail('public/.htaccess', 'does not grant access back - the site will be 403');
} else {
    ok('public/.htaccess', 'grants access to the document root only');
}

// ── Files that must never be served ───────────────────────────────────────
$root = dirname(__DIR__);

foreach (['config.php', 'app', 'install', 'storage', 'tests'] as $name) {
    $path = $root . '/' . $name;

    if (! file_exists($path)) {
        continue;
    }

    // If any of these sit under public/, the document root is wrong.
    is_file($root . '/public/' . $name) || is_dir($root . '/public/' . $name)
        ? fail('not under public/: ' . $name, 'FOUND inside public/ - document root is wrong')
        : ok('not under public/: ' . $name, 'correct');
}

$perms = @fileperms($root . '/config.php');

if ($perms !== false) {
    $mode = $perms & 0777;

    ($mode & 0044)
        ? warn('config.php permissions', sprintf('%04o - readable by others', $mode))
        : ok('config.php permissions', sprintf('%04o', $mode));
}

$storage = $root . '/storage';

if (is_dir($storage)) {
    is_writable($storage)
        ? ok('storage writable', $storage)
        : fail('storage writable', 'NOT writable - uploads will fail');
} else {
    warn('storage', 'does not exist yet - it is created on first upload');
}

// ── Database ──────────────────────────────────────────────────────────────
//
// Connected directly rather than through db(), which exits the process when
// the database is unreachable. A pre-flight that dies on its first problem
// reports nothing about the others - and the file and .htaccess checks above
// are the ones that matter most on a fresh deployment, where the database
// not being ready yet is entirely normal.
try {
    new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
            $config['db_host'] ?? '', $config['db_name'] ?? ''),
        (string) ($config['db_user'] ?? ''), (string) ($config['db_pass'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );

    $tables = (int) val('SELECT COUNT(*) FROM information_schema.tables
                         WHERE table_schema = DATABASE()');

    $tables >= 90
        ? ok('database tables', (string) $tables)
        : fail('database tables', $tables . ' - run install/upgrade.php');

    $admins = (int) val("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1");

    $admins > 0
        ? ok('active administrators', (string) $admins)
        : fail('active administrators', 'none - nobody can administer this instance');

    $temp = (int) val('SELECT COUNT(*) FROM users WHERE must_change_pw=1 AND is_active=1');

    $temp === 0
        ? ok('temporary passwords', 'none outstanding')
        : warn('temporary passwords', $temp . ' account(s) still on a temporary password');

    // Legacy seed data must not reach another agency's installation.
    $legacy = (int) val("SELECT COUNT(*) FROM candidates WHERE source='Indeed'");

    $legacy === 0
        ? ok('no legacy seed data', 'clean')
        : warn('legacy seed data', $legacy . ' imported candidates present - '
             . 'acceptable only on the authorised RSS instance');
} catch (Throwable $e) {
    fail('database', $e->getMessage());
}

// ── Live URL ──────────────────────────────────────────────────────────────
if ($base !== '') {
    $probe = static function (string $path) use ($base): array {
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true,
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$code, $body];
    };

    [$code, $body] = $probe('/login');

    $code === 200
        ? ok('GET /login', '200')
        : fail('GET /login', 'HTTP ' . $code);

    // Anything that returns CONTENT here is being served that must not be.
    //
    // A redirect is not a leak: an unknown path falls through to the router,
    // which sends a signed-out visitor to /login. Only a 200 that actually
    // contains the file means the web server is serving it. Treating 302 as
    // a failure made this check cry wolf on a correctly configured instance.
    foreach (['/../config.php', '/config.php', '/install/install.php',
              '/app/bootstrap.php', '/storage/', '/tests/'] as $path) {
        [$c, $b] = $probe($path);

        $leaked = $c === 200 && (
            str_contains($b, '<?php')
            || str_contains($b, 'db_pass')
            || str_contains($b, 'encryption_key')
            || preg_match('/Index of |<title>Directory listing/i', $b)
        );

        if ($leaked) {
            fail('SERVED: ' . $path, 'HTTP 200 with file content - must not be reachable');
        } elseif (in_array($c, [403, 404], true)) {
            ok('not served: ' . $path, 'HTTP ' . $c);
        } elseif (in_array($c, [301, 302, 303], true)) {
            ok('not served: ' . $path, 'HTTP ' . $c . ' (redirected, no content)');
        } else {
            warn('check by hand: ' . $path, 'HTTP ' . $c . ' - confirm no content is returned');
        }
    }

    // A signed-out visitor must not reach an internal screen.
    [$c, $b] = $probe('/employees');

    in_array($c, [302, 303, 401, 403], true)
        ? ok('/employees requires a session', 'HTTP ' . $c)
        : fail('/employees', 'HTTP ' . $c . ' - reachable without signing in');

    if (str_starts_with($base, 'https://')) {
        [$c, $b] = $probe('/login');

        stripos($b, 'strict-transport-security') !== false
            ? ok('HSTS header', 'present')
            : warn('HSTS header', 'absent - add Strict-Transport-Security at the web server');
    }
}

// ── The lateral path: the risk this whole arrangement creates ─────────────
//
// Proof here is the ABSENCE of a known marker, never a status code. A 302 to
// a login page, a 200 serving the main site's homepage, a 404 - none of them
// distinguish "protected" from "served something else". Only asking for a
// string we planted and not getting it back does.
if ($lateral !== '') {
    $marker  = 'CREWVIA-CANARY-' . bin2hex(random_bytes(12));
    $planted = [];

    foreach ([dirname(__DIR__) . '/storage/preflight-canary.txt',
              dirname(__DIR__) . '/install/preflight-canary.txt'] as $file) {
        $dir = dirname($file);

        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        if (@file_put_contents($file, $marker . PHP_EOL) !== false) {
            $planted[] = $file;
        }
    }

    $fetch = static function (string $url): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        ]);
        $body = (string) curl_exec($ch);
        curl_close($ch);

        return $body;
    };

    foreach (['/storage/preflight-canary.txt', '/install/preflight-canary.txt'] as $path) {
        $body = $fetch($lateral . $path);

        str_contains($body, $marker)
            ? fail('LEAK via ' . $lateral . $path, 'the canary came back - private files are served')
            : ok('no leak: ' . $path, 'marker absent');
    }

    // The schema is not secret, but serving it proves the directory is open.
    $body = $fetch($lateral . '/install/schema.sql');

    (stripos($body, 'CREATE TABLE') !== false)
        ? fail('LEAK via ' . $lateral . '/install/schema.sql', 'schema served in clear')
        : ok('no leak: /install/schema.sql', 'not served');

    // config.php executes rather than printing, so look for the one thing
    // that would appear if it were ever served as text.
    $body = $fetch($lateral . '/config.php');

    (str_contains($body, 'encryption_key') || str_contains($body, 'db_pass'))
        ? fail('LEAK via ' . $lateral . '/config.php', 'configuration served as text')
        : ok('no leak: /config.php', 'not served as text');

    foreach ($planted as $file) {
        @unlink($file);
    }

    $left = array_filter($planted, 'is_file');

    empty($left)
        ? ok('canary files removed', 'clean')
        : warn('canary files', 'remove by hand: ' . implode(', ', $left));
} else {
    warn('lateral path NOT tested',
         'pass the main-domain URL as a second argument, e.g. https://veloraweb.com/crewvia');
}

// ── Report ────────────────────────────────────────────────────────────────
echo implode(PHP_EOL, $lines), PHP_EOL, PHP_EOL;
printf("%d passed, %d warnings, %d failed%s", $pass, $warn, $fail, PHP_EOL);

if ($fail) {
    echo PHP_EOL . "Do not put real data in this instance until the failures are fixed." . PHP_EOL;
}

exit($fail ? 1 : 0);
