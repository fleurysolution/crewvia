<?php
/**
 * Operations (P4-M07): what an operator needs to know the platform is
 * running, and to find out quickly when it is not.
 *
 *  - Errors. Every uncaught exception and fatal error is written as one JSON
 *    line to storage/logs (private, never served), with a request id the
 *    person on the screen is shown. Paths are logged without their query
 *    string: links carry tokens.
 *  - Runs. Backups, restore drills and the scheduled jobs record when they
 *    ran and how it went in ops_runs.
 *  - Health. One list of checks shared by /health (for an uptime monitor),
 *    the System health page and install/health-check.php (for cron).
 */

declare(strict_types=1);

const OPS_ERROR_RETENTION_DAYS = 30;

function ops_request_id(): string
{
    static $id = null;
    return $id ??= bin2hex(random_bytes(6));
}

function ops_log_dir(): string
{
    global $config;
    return workforce_storage_path($config) . '/logs';
}

/** One error, as a line in today's log. Never throws: the logger must not become the failure. */
function ops_log_error(string $kind, string $class, string $message, string $file, int $line): void
{
    try {
        $dir = ops_log_dir();
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $root = dirname(__DIR__);
        $file = str_starts_with($file, $root) ? ltrim(substr($file, strlen($root)), '/\\') : basename($file);
        $entry = [
            'at'          => date('c'),
            'request_id'  => ops_request_id(),
            'kind'        => $kind,
            'class'       => $class,
            // The message can quote a value; it is kept short, on one line.
            'message'     => mb_substr(preg_replace('/\s+/', ' ', $message), 0, 500),
            'file'        => str_replace('\\', '/', $file),
            'line'        => $line,
            'method'      => PHP_SAPI === 'cli' ? 'CLI' : (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'path'        => PHP_SAPI === 'cli' ? implode(' ', array_map('basename', array_slice($_SERVER['argv'] ?? [], 0, 1))) : (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
            'user_id'     => (int) ($_SESSION['user']['id'] ?? 0) ?: null,
            'fingerprint' => substr(sha1($class . '|' . $file . '|' . $line), 0, 12),
        ];
        file_put_contents($dir . '/errors-' . date('Y-m-d') . '.log', json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable $ignored) {
        error_log('[crewvia] could not write the error log');
    }
}

/** The page a person sees when something broke: no detail, only the id to quote. */
function ops_error_page(): void
{
    if (headers_sent()) {
        echo "\n<p>Something went wrong. Reference: " . htmlspecialchars(ops_request_id()) . '</p>';
        return;
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Something went wrong</title>'
       . '<body style="font-family:system-ui,sans-serif;max-width:560px;margin:60px auto;padding:0 16px;color:#1f2a37">'
       . '<h1 style="font-size:22px">Something went wrong</h1><p>The error has been recorded. If it happens again, give this reference to your administrator:</p>'
       . '<p id="error-reference" style="font-family:monospace;font-size:18px">' . htmlspecialchars(ops_request_id()) . '</p><p><a href="/">Back to the start</a></p></body></html>';
}

function ops_install_handlers(): void
{
    if (PHP_SAPI !== 'cli' && ! headers_sent()) {
        header('X-Request-Id: ' . ops_request_id());
    }
    set_exception_handler(static function (Throwable $e): void {
        ops_log_error('exception', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
        error_log('[crewvia] ' . ops_request_id() . ' ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'PHP Fatal error: Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
            exit(255);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ops_error_page();
    });
    register_shutdown_function(static function (): void {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            // Out of memory leaves none for the logger: the limit is lifted for these last lines.
            if (str_contains((string) $e['message'], 'Allowed memory size')) {
                @ini_set('memory_limit', '-1');
            }
            ops_log_error('fatal', 'E' . $e['type'], (string) $e['message'], (string) $e['file'], (int) $e['line']);
            if (PHP_SAPI !== 'cli' && ! ini_get('display_errors')) {
                ops_error_page();
            }
        }
    });
}

/** Errors of the last $days days, newest first. */
function ops_recent_errors(int $days = 7, int $limit = 2000): array
{
    $dir = ops_log_dir();
    $out = [];
    for ($i = 0; $i < $days && count($out) < $limit; $i++) {
        $file = $dir . '/errors-' . date('Y-m-d', strtotime("-$i days")) . '.log';
        if (! is_file($file)) {
            continue;
        }
        foreach (array_reverse(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $out[] = $entry;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
    }
    return $out;
}

/** The same errors, one row per place in the code, most frequent first. */
function ops_error_groups(array $errors): array
{
    $groups = [];
    foreach ($errors as $e) {
        $k = $e['fingerprint'] ?? '?';
        $groups[$k] ??= ['fingerprint' => $k, 'class' => $e['class'] ?? '', 'message' => $e['message'] ?? '', 'where' => ($e['file'] ?? '') . ':' . ($e['line'] ?? ''), 'count' => 0, 'last' => $e['at'] ?? '', 'last_request' => $e['request_id'] ?? '', 'paths' => []];
        $groups[$k]['count']++;
        $groups[$k]['paths'][(string) ($e['path'] ?? '')] = true;
    }
    usort($groups, fn($a, $b) => [$b['count'], $b['last']] <=> [$a['count'], $a['last']]);
    return $groups;
}

/** Error logs older than the retention are deleted (run by install/run-workflows.php). */
function ops_purge_logs(): int
{
    $n = 0;
    foreach (glob(ops_log_dir() . '/errors-*.log') ?: [] as $file) {
        if (preg_match('/errors-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m) && $m[1] < date('Y-m-d', strtotime('-' . OPS_ERROR_RETENTION_DAYS . ' days')) && @unlink($file)) {
            $n++;
        }
    }
    return $n;
}

/** Records that a job ran. Never throws: a missing table must not stop the job itself. */
function ops_record(string $kind, string $status, array $detail = [], ?float $startedAt = null): void
{
    try {
        q('INSERT INTO ops_runs(kind,status,started_at,finished_at,seconds,detail,host) VALUES (?,?,?,NOW(),?,?,?)', [
            $kind, $status, date('Y-m-d H:i:s', (int) ($startedAt ?? microtime(true))),
            $startedAt === null ? null : round(microtime(true) - $startedAt, 2),
            json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), substr((string) gethostname(), 0, 120),
        ]);
    } catch (Throwable $e) {
        error_log('[crewvia] ops run not recorded: ' . $kind . ' ' . $status);
    }
}

function ops_last_run(string $kind, ?string $status = 'ok'): ?array
{
    try {
        return row('SELECT * FROM ops_runs WHERE kind = ?' . ($status ? ' AND status = ?' : '') . ' ORDER BY id DESC LIMIT 1', $status ? [$kind, $status] : [$kind]) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Where backups go. Never inside the web root: a dump there could be
 * downloaded. Configured as backup_dir, or ~/crewvia-backups.
 */
function ops_backup_dir(): ?string
{
    global $config;
    $dir = (string) ($config['backup_dir'] ?? '');
    if ($dir === '') {
        $home = (string) (getenv('HOME') ?: '');
        $dir = $home !== '' ? rtrim($home, '/\\') . '/crewvia-backups' : '';
    }
    return $dir !== '' ? $dir : null;
}

/** Why a backup folder may not be used, or null. */
function ops_backup_dir_refusal(string $dir): ?string
{
    $norm = str_replace('\\', '/', $dir);
    $app = str_replace('\\', '/', dirname(__DIR__));
    if (preg_match('#/(public_html|www|htdocs)(/|$)#i', $norm)) {
        return 'Backups cannot go under public_html, www or htdocs: a file there can be downloaded. Choose a folder outside the web root.';
    }
    if ($norm === $app || str_starts_with($norm, $app . '/')) {
        return 'Backups cannot go inside the application folder.';
    }
    return null;
}

/** The backups on disk, newest first, each with its manifest. */
function ops_backups(?string $dir = null): array
{
    $dir ??= ops_backup_dir();
    if (! $dir || ! is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (glob($dir . '/crewvia-*', GLOB_ONLYDIR) ?: [] as $path) {
        $manifest = json_decode((string) @file_get_contents($path . '/manifest.json'), true);
        if (is_array($manifest)) {
            $out[] = ['path' => $path, 'manifest' => $manifest];
        }
    }
    usort($out, fn($a, $b) => strcmp((string) $b['manifest']['created_at'], (string) $a['manifest']['created_at']));
    return $out;
}

/**
 * Every check, each [name => [status ok|warn|fail, what was found]].
 * $deep adds the ones that read files and history (not for /health).
 */
function ops_health(bool $deep = true): array
{
    global $config;
    $checks = [];
    try {
        val('SELECT COUNT(*) FROM users');
        $checks['database'] = ['ok', 'reachable, tables present'];
    } catch (Throwable $e) {
        $checks['database'] = ['fail', 'not reachable or tables missing'];
    }
    $storage = workforce_storage_path($config);
    $probe = $storage . '/.health-' . bin2hex(random_bytes(4));
    $checks['storage'] = @file_put_contents($probe, 'x') === 1 && @unlink($probe) ? ['ok', 'writable'] : ['fail', 'documents folder not writable'];
    if (! $deep) {
        return $checks;
    }

    $free = @disk_free_space($storage);
    $total = @disk_total_space($storage);
    if ($free !== false && $total) {
        $pct = $free / $total * 100;
        $checks['disk'] = [$pct < 5 ? 'fail' : ($pct < 15 ? 'warn' : 'ok'), sprintf('%.1f GB free (%.0f%%)', $free / 1073741824, $pct)];
    }

    $checks['debug'] = empty($config['debug']) ? ['ok', 'off'] : ['fail', 'on: errors are shown to visitors'];
    $key = base64_decode((string) ($config['encryption_key'] ?? ''), true);
    $checks['encryption_key'] = $key !== false && strlen($key) === 32 ? ['ok', 'configured (keep a copy offline: backups cannot be read without it)'] : ['fail', 'missing or not 32 bytes'];
    if (! empty($config['previous_encryption_key'])) {
        $checks['key_rotation'] = ['warn', 'a key rotation is under way: run php install/rotate-key.php --resume'];
    }

    $dir = ops_backup_dir();
    $backups = $dir ? ops_backups($dir) : [];
    if (! $dir) {
        $checks['backup'] = ['fail', 'no backup folder configured (backup_dir)'];
    } elseif (! $backups) {
        $checks['backup'] = ['fail', 'no backup found in ' . $dir];
    } else {
        $age = (time() - strtotime((string) $backups[0]['manifest']['created_at'])) / 3600;
        $checks['backup'] = [$age > 26 ? 'fail' : 'ok', sprintf('last %s, %.1f h ago, %d kept', $backups[0]['manifest']['created_at'], $age, count($backups))];
    }

    $drill = ops_last_run('restore_drill');
    $checks['restore_drill'] = $drill === null ? ['warn', 'no restore drill recorded']
        : [strtotime((string) $drill['finished_at']) < strtotime('-35 days') ? 'warn' : 'ok', 'last ' . $drill['finished_at']];

    foreach (['workflows' => 2, 'report_schedules' => 2] as $kind => $hours) {
        $run = ops_last_run($kind, null);
        $checks[$kind] = $run === null ? ['warn', 'never recorded: is the cron job set?']
            : [$run['status'] !== 'ok' ? 'fail' : (strtotime((string) $run['finished_at']) < time() - $hours * 3600 ? 'warn' : 'ok'), 'last ' . $run['finished_at'] . ' (' . $run['status'] . ')'];
    }

    // P5-M105b: a rehearsal left on silently stops the office's emails and notices.
    require_once __DIR__ . '/mail-routing.php';
    $since = mail_office_test_since();
    $checks['rehearsal'] = $since === '' ? ['ok', 'off']
        : [strtotime($since) < time() - 86400 ? 'fail' : 'warn', 'on since ' . $since . ': office emails and notices go to the test inbox'];
    // P5-M110: a reimbursement is paid one way, once (the database refuses otherwise; rows from before are found here).
    require_once __DIR__ . '/expense-claims.php';
    $odd = expense_claims_inconsistent();
    $checks['reimbursements'] = $odd ? ['warn', $odd . ' reimbursement claim(s) paid with no trace, or both on the pay and separately: see Reimbursements']
        : ['ok', 'every paid claim is paid one way'];
    // P5-M107: a staging copy of this workspace (for the demonstration films) is watched from here: it must be gone by
    // its deletion date. Server config: 'staging_watch' => ['path' => '/home/veloraweb/public_html/crewvia-staging', 'delete_by' => 'YYYY-MM-DD'].
    if (! empty($config['staging_watch']['path'])) {
        $path = (string) $config['staging_watch']['path'];
        $by = (string) ($config['staging_watch']['delete_by'] ?? '');
        if (! is_dir($path)) {
            $checks['staging_copy'] = ['ok', 'no staging copy at ' . $path];
        } elseif ($by === '' || strtotime($by . ' 23:59:59') < time()) {
            $checks['staging_copy'] = ['fail', 'the staging copy at ' . $path . ' is past its deletion date (' . ($by ?: 'none set') . '): it holds a copy of personal data - delete it'];
        } else {
            $checks['staging_copy'] = ['warn', 'a staging copy exists at ' . $path . ': delete it by ' . $by];
        }
    }
    // P5-M103b: a queue that stops looks like an empty one.
    $oldest = val("SELECT TIMESTAMPDIFF(MINUTE, MIN(created_at), NOW()) FROM mail_outbox WHERE status IN ('pending','sending') AND attempts < 5");
    $checks['mail_queue'] = $oldest === null ? ['ok', 'nothing waiting']
        : [(int) $oldest >= 60 ? 'fail' : ((int) $oldest >= 15 ? 'warn' : 'ok'), 'oldest email waiting ' . (int) $oldest . ' min: is install/deliver-mail.php on cron?'];

    $errors = ops_recent_errors(2);
    $day = array_filter($errors, fn($e) => strtotime((string) ($e['at'] ?? '')) >= time() - 86400);
    $checks['errors'] = [count($day) === 0 ? 'ok' : (count($day) >= 50 ? 'fail' : 'warn'), count($day) . ' in the last 24 hours'];

    return $checks;
}
