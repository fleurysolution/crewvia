<?php
/**
 * RSS Ops - everything the app needs before it can answer a request.
 *
 * Deliberately small and dependency-free. This runs on cPanel shared hosting
 * with nothing installed, so there is no composer, no framework and no build
 * step: upload the folder, point the domain at /public, done.
 */

declare(strict_types=1);

date_default_timezone_set('America/New_York');   // RSS runs on Eastern time

// ── Configuration ────────────────────────────────────────────────────────
// Real values live in config.php, which is not in version control. This
// file only describes the shape and gives safe defaults.
$config = [
    'db_host' => '127.0.0.1',
    'db_name' => 'rss_ops',
    'db_user' => 'root',
    'db_pass' => '',
    'app_name' => 'Crewvia',
    'app_url'  => '',
    'debug'    => false,
];

if (is_file(__DIR__ . '/../config.php')) {
    $config = array_merge($config, require __DIR__ . '/../config.php');
}
require_once __DIR__.'/tenancy.php';
try {
    $config = workforce_tenant_config($config, $_SERVER, dirname(__DIR__), PHP_SAPI==='cli');
} catch (Throwable $e) {
    error_log('[workforce] Workspace configuration rejected.');
    http_response_code(404);
    exit('Workspace unavailable.');
}

// ── Errors ───────────────────────────────────────────────────────────────
// A white screen during a demo is worse than an ugly message, but a stack
// trace in front of a client is worse than both.
error_reporting(E_ALL);
ini_set('display_errors', $config['debug'] ? '1' : '0');
ini_set('log_errors', '1');

// ── Session ──────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on')
                      || !empty($config['secure_cookies']),
    ]);
    session_name(workforce_session_name($config));
    session_start();
}

// ── Idle sessions ────────────────────────────────────────────────────────
//
// Pay rates, screening outcomes and identity documents live behind this
// session. A machine left open on a site desk should not keep offering
// them, so a session with no request for a while is ended and the reason
// is carried to the sign-in screen.
$idleMinutes = (int) ($config['idle_timeout_minutes'] ?? 30);

if ($idleMinutes > 0 && ! empty($_SESSION['user'])) {
    $seen = (int) ($_SESSION['last_seen'] ?? 0);

    if ($seen > 0 && time() - $seen > $idleMinutes * 60) {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'],
                      $p['secure'], $p['httponly']);
        }

        session_regenerate_id(true);
        $_SESSION['flash'] = ['msg' => 'You were signed out after a while without activity.',
                              'kind' => 'err'];

        header('Location: /login');
        exit;
    }

    $_SESSION['last_seen'] = time();
}


// ── Database ───// ── Database ─────────────────────────────────────────────────────────────
function db(): PDO
{
    static $pdo = null;
    global $config;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['db_host'], $config['db_name']);

    try {
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } catch (PDOException $e) {
        error_log('[rss-ops] database: ' . $e->getMessage());
        http_response_code(500);
        exit('The database is not reachable. Check config.php.');
    }

    return $pdo;
}

/** Run a query with bound parameters and get the statement back. */
function q(string $sql, array $args = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($args);

    return $st;
}

function rows(string $sql, array $args = []): array { return q($sql, $args)->fetchAll(); }
function row(string $sql, array $args = []): ?array  { $r = q($sql, $args)->fetch(); return $r ?: null; }
function val(string $sql, array $args = [])          { $r = q($sql, $args)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }

// ── Who is asking ────────────────────────────────────────────────────────
// Translation. Loaded after the database helpers because set_locale()
// writes the chosen language against the account.
require_once __DIR__ . '/i18n.php';

function user(): ?array { return $_SESSION['user'] ?? null; }
function uid(): int     { return (int) (user()['id'] ?? 0); }

/**
 * Role check.
 *
 * admin passes everything. The other three are desks, not ranks: hotels does
 * not outrank recruiting, it is simply a different job, so there is no
 * hierarchy to walk.
 */
function can(string ...$roles): bool
{
    $u = user();

    if (! $u) {
        return false;
    }

    if ($u['role'] === 'admin') {
        return true;
    }

    if(in_array($u['role'], $roles, true)) return true;
    if(in_array($u['role'],['worker','supervisor','client'],true)) return false;
    foreach($roles as $desk) {
        if($desk==='admin') continue;
        if(row('SELECT desk FROM role_permissions WHERE role_name=? AND desk=?',[$u['role'],$desk])) return true;
    }
    return false;
}

/** Stop here unless the person holds one of these desks. */
function require_role(string ...$roles): void
{
    if (! user()) {
        redirect('/login');
    }

    if (! can(...$roles)) {
        http_response_code(403);
        render('403', ['need' => $roles]);
        exit;
    }
}

function require_login(): void
{
    if (user()) {
        return;
    }

    // Where to send somebody after they sign in - but only if this request is
    // a page they were actually trying to read.
    //
    // A browser fetches sub-resources on its own. Opening the site unsigned
    // produced two requests: the page, and Chrome's automatic /favicon.ico.
    // Both reached this function, the favicon arrived last, and it overwrote
    // the destination - so signing in landed on /favicon.ico instead of the
    // dashboard. The fix is to recognise what a navigation looks like rather
    // than to special-case that one filename, because the same race applies
    // to a stylesheet, an icon or the web manifest.
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');

    $isNavigation =
        ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        && str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')
        && ! preg_match('/\.(ico|png|jpe?g|gif|svg|webp|css|js|map|json|webmanifest|txt|xml|woff2?)$/i', $path);

    // And it has to be somewhere inside this application: a destination taken
    // from the request must never be able to bounce somebody off-site.
    $isLocal = $uri !== '' && $uri[0] === '/' && ! str_starts_with($uri, '//');

    if ($isNavigation && $isLocal) {
        $_SESSION['intended'] = $uri;
    }

    redirect('/login');
}

// ── CSRF ─────────────────────────────────────────────────────────────────
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $sent = $_POST['_csrf'] ?? '';

    if (! is_string($sent) || ! hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('That form expired. Go back, reload the page and try again.');
    }
}

// ── Output ───────────────────────────────────────────────────────────────
function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function flash(?string $msg = null, string $kind = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'kind' => $kind];

        return null;
    }

    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return $f;
}

/**
 * Refuse a request, and still answer like an application rather than a
 * blank page with one sentence on it.
 *
 * A form post goes back where it came from with the reason in the flash
 * band, so the person keeps their place. A page request gets a real page
 * carrying the status code. Anything not asking for HTML - a fetch, a
 * webhook, a script - still gets the plain sentence it can read.
 */
function refuse(int $code, string $message, ?string $back = null): never
{
    $wantsHtml = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html');

    if (! $wantsHtml) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        // A Location header is only followed on a 3xx, so the refusal
        // travels in the flash band instead of the status line.
        flash($message, 'err');
        redirect($back ?? refuse_origin());
    }

    http_response_code($code);
    render('refused', ['message' => $message, 'code' => $code]);
    exit;
}

/** Where a refused post came from - never anywhere else. */
function refuse_origin(): string
{
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $host    = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $parts   = $referer === '' ? false : parse_url($referer);

    if ($parts && strtolower((string) ($parts['host'] ?? '')) === $host) {
        $path = (string) ($parts['path'] ?? '/');
        if (str_starts_with($path, '/') && ! str_starts_with($path, '//')) {
            return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }
    }

    return '/';
}

function render(string $view, array $data = []): void
{
    global $config;
    extract($data, EXTR_SKIP);
    $appName = $config['app_name'];
    try { $brand=val("SELECT setting_value FROM platform_settings WHERE setting_key='brand_name'"); if($brand) $appName=$brand; } catch(Throwable $ignored) {}
    $viewPath = __DIR__ . '/views/' . $view . '.php';

    if (! is_file($viewPath)) {
        http_response_code(404);
        $viewPath = __DIR__ . '/views/404.php';
    }

    require __DIR__ . '/views/_layout.php';
}

function log_activity(string $action, string $entity = '', int $entityId = 0, string $detail = ''): void
{
    try {
        q('INSERT INTO activity (user_id, action, entity, entity_id, detail)
           VALUES (?,?,?,?,?)',
          [uid() ?: null, $action, $entity ?: null, $entityId ?: null, mb_substr($detail, 0, 500)]);
    } catch (Throwable $e) {
        error_log('[rss-ops] activity: ' . $e->getMessage());
    }
}

// ── The money rules, in one place ────────────────────────────────────────
/**
 * What a week is worth, to the engineer and to RSS.
 *
 * The guarantee is the whole point of it: Paco promises 50 hours whether or
 * not the plant gives them 50, and 60 once the strike goes live. So a man who
 * works 41 hours is still paid for 50, and the cost of that gap is RSS's, not
 * his. Computing it anywhere other than here would mean computing it twice.
 *
 * Per-diem is paid per DAY PRESENT, not per day worked, and it is not hours -
 * it does not scale with overtime and it is not part of the hourly cost.
 */
function week_money(array $t, array $p, array $job): array
{
    if (!empty($t['snapshot_json'])) { return json_decode($t['snapshot_json'],true,512,JSON_THROW_ON_ERROR); }
    $pay      = (float) ($p['pay_rate']      ?? $job['pay_rate']);
    $bill     = (float) ($p['bill_rate']     ?? $job['bill_rate'] ?? 0);
    $perDiem  = (float) ($p['per_diem_rate'] ?? $job['per_diem_rate']);

    $guarantee = (int) ($job['strike_live'] ? $job['strike_hours'] : $job['guarantee_hours']);
    $worked    = (float) $t['hours_worked'];
    // Paid leave is paid at the base rate and counts toward the guarantee -
    // a week of leave is not also topped up to the guarantee - but it is not
    // time worked, so it never makes overtime.
    $leaveHours = (float) ($t['paid_leave_hours'] ?? 0);
    $guarantee  = max(0, $guarantee - $leaveHours);

    // The guarantee is a floor, never a ceiling.
    $paidHours  = max($worked, (float) $guarantee);
    $shortBy    = max(0.0, $guarantee - $worked);

    $perDiemAmt = (int) $t['per_diem_days'] * $perDiem;
    $expenses   = (float) $t['expenses'];

    require_once __DIR__.'/payroll-calculation.php';
    $gross=payroll_gross($worked,(float)$guarantee,$pay,isset($job['weekly_overtime_after'])?(float)$job['weekly_overtime_after']:null,(float)($job['overtime_multiplier']??1.5),($p['employment_type']??'')==='salaried'&&isset($p['salary_per_period'])?(float)$p['salary_per_period']:null);
    // A project given a confirmed pay rule set is paid under it: daily and
    // weekly lines, double time, holidays, shift premiums, the person's
    // other assignments. A project without one is paid exactly as above.
    require_once __DIR__.'/pay-rules.php';
    $ruled = pay_rules_week_for($t, $p, $job, (float) $guarantee);
    if ($ruled !== null) { $gross = $ruled; }
    $leavePay   = round($leaveHours * $pay, 2);
    $labourCost = $gross['labour_cost'];
    $payTotal   = $labourCost + $leavePay + $perDiemAmt + $expenses;

    // Billing follows hours actually worked unless the client agreed to carry
    // the guarantee. Until RSS tells us otherwise we bill what was worked,
    // which is the conservative reading and never over-states revenue.
    $billTotal  = $bill > 0 ? $worked * $bill : 0.0;

    return $gross + [
        'guarantee'   => $guarantee,
        'worked'      => $worked,
        'paid_hours'  => $paidHours,
        'short_by'    => $shortBy,
        'pay_rate'    => $pay,
        'bill_rate'   => $bill,
        'labour_cost' => $labourCost,
        'paid_leave_hours' => $leaveHours,
        'leave_pay'   => $leavePay,
        'per_diem'    => $perDiemAmt,
        'expenses'    => $expenses,
        'pay_total'   => $payTotal,
        'bill_total'  => $billTotal,
        'margin'      => $billTotal > 0 ? $billTotal - $payTotal : 0.0,
    ];
}

function money($v): string { return '$' . number_format((float) $v, 2); }

/** Saturday of the week a date falls in - the week always ends Saturday. */
function week_ending(?string $date = null): string
{
    $ts = strtotime($date ?: 'today');
    $dow = (int) date('w', $ts);           // 0 Sun .. 6 Sat

    return date('Y-m-d', strtotime('+' . ((6 - $dow + 7) % 7) . ' days', $ts));
}

/** The four desks, for menus and forms. */
/**
 * The trades this agency staffs.
 *
 * One list. A requisition asks for one of these and a candidate is
 * filed under one of these, and for a while they were different lists:
 * an order could ask for an electrician that nobody could ever be.
 */
function disciplines(bool $includeRetired = false): array
{
    static $cache = [];

    $key = $includeRetired ? 'all' : 'active';

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    // The six the application shipped with. Returned whole when the
    // catalogue table is not there yet, because an empty list would make
    // every discipline invalid and silently reject every requisition.
    $built_in = [
        'mechanical'      => 'Mechanical',
        'chemical'        => 'Chemical',
        'electrical'      => 'Electrical',
        'instrumentation' => 'Instrumentation',
        'operator'        => 'Operator',
        'other'           => 'Other',
    ];

    try {
        $found = rows('SELECT slug, label FROM disciplines'
                      . ($includeRetired ? '' : ' WHERE is_active = 1')
                      . ' ORDER BY sort_order, label');
    } catch (Throwable $e) {
        return $cache[$key] = $built_in;
    }

    if (! $found) {
        return $cache[$key] = $built_in;
    }

    $list = [];

    foreach ($found as $row) {
        $list[(string) $row['slug']] = (string) $row['label'];
    }

    return $cache[$key] = $list;
}

function roles(): array
{
    return [
        'worker' => 'Worker portal',
        'client' => 'Client portal',
        'supervisor' => 'Supervisor',
        'admin' => 'Full access',
        'recruiter' => 'Recruiting',
        'hotels'    => 'Hotels & Travel',
        'payroll'   => 'Payroll & Billing',
    ];
}

/**
 * Where a person stands with the agency, in one list.
 *
 * This was written out by hand in two views, which is how two screens
 * come to disagree about what a stage is called. The database is the
 * authority for the keys; this is the authority for the words.
 */
function candidate_stages(): array
{
    return [
        'new'       => 'Never contacted',
        'contacted' => 'Contacted',
        'screening' => 'Screening',
        'offered'   => 'Offered',
        'accepted'  => 'Accepted',
        'placed'    => 'Placed',
        'declined'  => 'Declined',
        'rejected'  => 'Rejected',
    ];
}

/**
 * How a contact went.
 *
 * A recruiter reaches somebody by telephone or by email, and the record
 * has to say which: "spoke to them" and "wrote to them" are not the same
 * claim to make three weeks later when nobody remembers. The telephone
 * outcomes came first, so their keys are unchanged and already on file.
 */
function contact_outcomes(): array
{
    return [
        'reached'        => 'Reached by telephone',
        'voicemail'      => 'Left a voicemail',
        'no_answer'      => 'No answer',
        'callback'       => 'Call back later',
        'emailed'        => 'Emailed them',
        'replied_email'  => 'They replied by email',
        'not_interested' => 'Not interested',
        'wrong_number'   => 'Wrong number or bad address',
    ];
}

/**
 * Where a placement stands, in words and in colour.
 *
 * A placement status is not a candidate stage, and the two were being
 * mixed: Manning coloured 'on_site' with stage_colour(), which knows
 * nothing about it and returned grey for everything.
 */
function placement_word(string $status): string
{
    return [
        'offered'    => 'Offered',
        'confirmed'  => 'Confirmed',
        'travelling' => 'Travelling',
        'on_site'    => 'On site',
        'completed'  => 'Completed',
        'cancelled'  => 'Cancelled',
    ][$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function placement_tone(string $status): string
{
    return match ($status) {
        'on_site'    => 'green',
        'travelling' => 'amber',
        'confirmed'  => 'blue',
        'cancelled'  => 'red',
        default      => 'grey',
    };
}

/**
 * Whether somebody can be called again.
 *
 * A flag nobody can see is the redlined spreadsheet with extra steps,
 * so these have words, a colour, and a reason recorded against them.
 * "No rehire" means do not put them back on a job. "Do not use" means
 * do not contact them at all.
 */
/**
 * What the agency staffs for, as opposed to which department somebody
 * belongs to.
 *
 * Discipline answers "mechanical or chemical". It does not answer
 * "can they weld", which is the question a recruiter is actually
 * asked. A person has several of these; a discipline is one.
 */
function skills_list(bool $includeRetired = false): array
{
    static $cache = [];

    $key = $includeRetired ? 'all' : 'active';

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    try {
        $found = rows('SELECT slug, label FROM skills'
                      . ($includeRetired ? '' : ' WHERE is_active = 1')
                      . ' ORDER BY sort_order, label');
    } catch (Throwable $e) {
        // Until the upgrade has run there is no list. An empty one
        // would make every skill invalid and refuse every save.
        return $cache[$key] = [];
    }

    $list = [];

    foreach ($found as $row) {
        $list[(string) $row['slug']] = (string) $row['label'];
    }

    return $cache[$key] = $list;
}

function rehire_states(): array
{
    return [
        'review'     => 'Check before calling',
        'eligible'   => 'Fine to call',
        'ineligible' => 'No rehire',
        'do_not_use' => 'DO NOT USE',
    ];
}

/** The states that mean stop, for a filter and for a warning. */
function rehire_blocked(): array
{
    return ['ineligible', 'do_not_use'];
}

/**
 * The red tag beside a name, or an empty string.
 *
 * Returned ready to print so that every list showing people shows the
 * same warning in the same place, rather than each screen deciding for
 * itself whether it is worth mentioning.
 */
function rehire_tag(?string $status): string
{
    if ($status === null || ! in_array($status, rehire_blocked(), true)) {
        return '';
    }

    return '<span class="tag red">'
         . e(t(rehire_states()[$status]))
         . '</span>';
}

function stage_colour(string $stage): string
{
    return match ($stage) {
        'new'                    => 'blue',
        'contacted', 'screening', 'offered' => 'amber',
        'accepted', 'placed'     => 'green',
        'declined', 'rejected'   => 'red',
        default                  => 'grey',
    };
}

/**
 * The working project. A choice from the selector persists across desks; when
 * nobody has chosen yet, the live project is opened rather than none.
 */
function current_job(): ?array {
    $id   = (int) ($_SESSION['job_id'] ?? 0);
    $live = session_status() === PHP_SESSION_ACTIVE;

    // A project that no longer exists must not stay the working project:
    // every screen would filter on an id nothing matches and show an empty
    // workspace instead of saying the selection is gone.
    if ($id && ! val('SELECT id FROM jobs WHERE id=?', [$id])) {
        $id = 0;
        if ($live) { unset($_SESSION['job_id']); }
    }

    // Nothing chosen yet. Every project-scoped screen filters on this id, so
    // a recruiter signing in for the first time met an empty screening board,
    // an empty roster and empty hours, and every action answered
    // "Application unavailable in this project" - because no project was
    // open, not because there was no work. Open the live one instead: the
    // project that is running, or failing that the newest being set up.
    if (! $id && $live && user()
        && ! in_array(user()['role'] ?? '', ['worker', 'client'], true)) {
        $id = (int) val("SELECT id FROM jobs
                         ORDER BY FIELD(status,'active','planning','closed'), id DESC
                         LIMIT 1");

        if ($id) { $_SESSION['job_id'] = $id; }
    }

    return $id ? row('SELECT j.*, c.name AS client_name, pp.weekly_overtime_after,pp.overtime_multiplier FROM jobs j LEFT JOIN clients c ON c.id=j.client_id LEFT JOIN project_pay_policies pp ON pp.job_id=j.id WHERE j.id=?', [$id]) : null;
}
function valid_date(string $date): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function is_worker_account(): bool {
 return user() && (bool)row('SELECT user_id FROM worker_accounts WHERE user_id=?',[uid()]);
}

function csv_cell($value): string {
 $text=(string)$value; return preg_match('/^[\s]*[=+@-]/u',$text) ? "'".$text : $text;
}

function page_controls(int $page,int $shown,int $size=100): string {
 $query=$_GET;unset($query['page']);$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
 $html='<nav class="card row" aria-label="'.te('Pagination').'">';
 if($page>1)$html.='<a class="btn ghost" href="'.e($path.'?'.http_build_query($query+['page'=>$page-1])).'">'.te('Previous').'</a>';
 $html.='<span>'.te('Page :page',['page'=>$page]).'</span>';
 if($shown===$size)$html.='<a class="btn ghost" href="'.e($path.'?'.http_build_query($query+['page'=>$page+1])).'">'.te('Next').'</a>';
 return $html.'</nav>';
}
