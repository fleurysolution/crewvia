<?php
/**
 * RSS Ops - the single way in.
 *
 * One front controller and a flat routing table. At this size a router that
 * can do more than match a path would be more code to read than the routes
 * themselves.
 */

declare(strict_types=1);

if (PHP_SAPI==='cli-server') {
    $asset=realpath(__DIR__.rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/'));
    if($asset && str_starts_with($asset,__DIR__.DIRECTORY_SEPARATOR) && is_file($asset) && $asset!==__FILE__) return false;
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
// P4-M06: no script, style or frame from elsewhere; forms post here, or to the
// payment, signature and Google pages their actions redirect to. Inline
// handlers remain in the views, so scripts are 'self' plus inline.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; font-src 'self' data:; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self' https://checkout.stripe.com https://billing.stripe.com https://accounts.google.com https://*.docusign.net https://*.docusign.com");
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
if ((($_SERVER['HTTPS'] ?? '') === 'on') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
    header('Strict-Transport-Security: max-age=31536000');
}
header('Cache-Control: no-store');
require __DIR__ . '/../app/bootstrap.php';

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path   = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

// P4-M07: for an uptime monitor. Up or down, nothing more: no version, no detail.
if ($path === '/health') {
    $health = ops_health(false);
    $up = ! in_array('fail', array_column($health, 0), true);
    http_response_code($up ? 200 : 503);
    header('Content-Type: application/json');
    echo json_encode(['status' => $up ? 'ok' : 'down']);
    exit;
}
// Stripe authenticates the raw POST with its signature, not a browser CSRF token.
if ($path === '/stripe-webhook') { require __DIR__.'/../app/pages/saas-webhook.php'; exit; }
if ($path === '/job-feed') { if($method!=='GET'){http_response_code(405);exit('GET required.');} require __DIR__.'/../app/pages/job-feed.php'; exit; }
if($path==='/recruiting-webhook'){require __DIR__.'/../app/pages/recruiting-webhook.php';exit;}
if($path==='/app-manifest'){require __DIR__.'/../app/pages/app-manifest.php';exit;}
if($path==='/sitemap.xml'){require __DIR__.'/../app/pages/sitemap.php';exit;}
if($path==='/docusign-webhook'){require __DIR__.'/../app/pages/docusign-webhook.php';exit;}
// P5-M06b: trusted phones authenticate with their own cookie and header, not a session form token.
if(str_starts_with($path,'/sync/')){require __DIR__.'/../app/pages/sync.php';exit;}
// P5-M08: Twilio signs its callbacks; the signature is its authentication.
if($path==='/twilio-webhook'){require __DIR__.'/../app/pages/twilio-webhook.php';exit;}
// P5-M18: a campaign email's unsubscribe link carries its own secret token; mail programs post to it with no session (RFC 8058).
if($path==='/unsubscribe'){require __DIR__.'/../app/pages/unsubscribe.php';exit;}
csrf_check();

if ($path === '/google-start') { require __DIR__.'/../app/pages/google-start.php'; exit; }
if ($path === '/google-callback') { require __DIR__.'/../app/pages/google-callback.php'; exit; }
// Public application and one-time account activation.
if (in_array($path, ['/apply','/activate','/join','/careers','/privacy'], true)) { require __DIR__.'/../app/pages/'.substr($path,1).'.php'; exit; }
if(in_array($path,['/recover','/mfa'],true)){require __DIR__.'/../app/pages/'.substr($path,1).'.php';exit;}
// Routes that do not need a session.
if ($path === '/login') {
    require __DIR__ . '/../app/pages/login.php';
    exit;
}

if ($path === '/logout') {
    if ($method !== 'POST') { http_response_code(405); exit('Use the sign-out button.'); }
    log_activity('signed out');
    // P5-M06b: signing out on a trusted phone ends its trust and clears what it kept.
    if (isset($_COOKIE['crewvia_device'])) { require_once __DIR__.'/../app/offline-sync.php'; os_revoke_cookie_grant(uid() ?: null, 'signed_out'); header('Clear-Site-Data: "storage"'); }
    // P5-M06c: and its push notifications stop (clearing its storage also drops the browser's subscription).
    if (isset($_COOKIE['crewvia_push'])) { require_once __DIR__.'/../app/push.php'; push_end_cookie('signed_out'); header('Clear-Site-Data: "storage"'); }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']); }
    session_destroy();
    redirect('/login');
}

if ($path === '/language') {
    if($method!=='POST'){http_response_code(405);exit('POST required.');}
    set_locale((string) ($_POST['locale'] ?? $_GET['lang'] ?? ''));
    $back = (string) ($_POST['back'] ?? '/');
    // Only ever return somewhere inside this application.
    if ($back === '' || $back[0] !== '/' || str_starts_with($back, '//') || preg_match('/[\\\\\x00-\x1f\x7f]/',rawurldecode($back))) $back = '/';
    redirect($back);
}

require_login();
$fresh = row('SELECT id,name,email,role,locale,is_active,must_change_pw FROM users WHERE id=?', [uid()]);
if (!$fresh || !$fresh['is_active']) { $_SESSION=[]; session_destroy(); redirect('/login'); }
require_once __DIR__.'/../app/security.php';
$version=(int)val('SELECT session_version FROM account_security WHERE user_id=?',[uid()]);
if($version!==(int)($_SESSION['auth_version']??0)){$_SESSION=[];session_destroy();redirect('/login');}
$_SESSION['user'] = $fresh;
// Q2: a client account works only while the client portal is switched on.
if ($fresh['role'] === 'client' && ! client_portal_enabled()) { $_SESSION=[]; session_destroy(); redirect('/login'); }
// P4-M06: where an administrator requires it, administrators and payroll
// work only once two-step verification is on.
if (in_array($fresh['role'], ['admin', 'payroll'], true) && ! in_array($path, ['/account-security', '/account', '/language'], true)
    && (string) val("SELECT setting_value FROM platform_settings WHERE setting_key = 'require_two_step_privileged'") === '1'
    && ! val('SELECT encrypted_totp FROM account_security WHERE user_id = ?', [uid()])) {
    flash(t('Two-step verification is required for your role. Turn it on here to continue.'), 'err');
    redirect('/account-security');
}
if ($fresh['role']==='client' && !in_array($path,['/client-portal','/account','/account-security','/language'],true)) {
    if($path==='/') redirect('/client-portal');
    http_response_code(403);render('403',['need'=>['client']]);exit;
}
if ($fresh['role'] === 'supervisor' && !in_array($path,['/lateness','/select-project','/project-tasks','/project-board','/workload','/my-tasks','/my-training','/recognition','/feedback','/surveys','/devices','/procurement','/appraisals','/performance','/hr-records','/activity','/approvals','/reviews','/my-team','/safety-plan','/portal','/account','/account-security','/language','/qualifications','/screening-workflow','/resumes','/offboarding','/safety-plan','/comms','/notifications','/notification-feed','/timeoff','/learning','/agreements','/contracts','/inbox','/notification-feed','/proofs','/employment','/employee-folder','/checks','/expenses','/attendance'],true)) redirect('/my-team');
if ($fresh['role'] === 'worker' && !in_array($path, ['/lateness','/my-jobs','/my-tasks','/my-training','/recognition','/feedback','/surveys','/devices','/my-payslips','/appraisals','/performance','/benefits','/hr-records','/hr-requests','/self-service','/payslip','/activity','/approvals','/portal','/account','/account-security','/language','/qualifications','/screening-workflow','/resumes','/offboarding','/safety-plan','/comms','/notifications','/learning','/timeoff','/agreements','/contracts','/inbox','/notification-feed','/proofs','/employment','/employee-folder','/checks','/expenses','/attendance'], true)) redirect('/portal');
// P5-M54: a candidate (not placed yet) opens their applications, offers and papers; the employee screens wait until they are hired.
if ($fresh['role'] === 'worker' && !in_array($path, candidate_paths(), true) && worker_is_candidate($fresh)) {
    flash(t('That part of the portal opens once you are hired.'));
    redirect('/portal');
}

if ($fresh['must_change_pw'] && $path !== '/account') redirect('/account');
if (($config['commercial_mode'] ?? 'demo')==='subscription' && !empty($config['saas_enforce_subscription'])
    && !in_array($path,['/subscription','/account','/logout'],true)) {
    $billingStatus=val('SELECT status FROM saas_subscription WHERE id=1');
    if (!in_array($billingStatus,['active','trialing'],true)) {
        if ($fresh['role']==='admin') redirect('/subscription');
        http_response_code(402);exit('Workspace subscription requires attention. Contact your company administrator.');
    }
}
if ($path === '/select-project') {
    if ($method !== 'POST') { http_response_code(405); exit; }
    $selected = row('SELECT id FROM jobs WHERE id=?', [(int)($_POST['job_id'] ?? 0)]);
    if (!$selected) { http_response_code(422); exit('Choose an existing project.'); }
    // P4-M06: a supervisor switches between the projects they supervise, no others.
    if ($fresh['role'] === 'supervisor' && !in_array((int)$selected['id'], supervised_job_ids(uid()), true)) { http_response_code(403); exit(t('Choose a project you supervise a crew on.')); }
    $_SESSION['job_id'] = (int)$selected['id'];
    // The switcher in the header wants the dashboard. The project list
    // wants the settings for the project just chosen. Only a path inside
    // this application is ever accepted.
    $then = (string)($_POST['then'] ?? '/');
    // P4-M06: '/\\host' is read by browsers as '//host': only a plain local path is followed.
    if ($then === '' || $then[0] !== '/' || str_starts_with($then, '//') || preg_match('/[\\\\\x00-\x1f]/', $then)) $then = '/';
    redirect($then);
}


// id-bearing paths, longest first so /candidates/12 does not match /candidates.
if (preg_match('#^/candidates/(\d+)$#', $path, $m)) {
    $_GET['id'] = (int) $m[1];
    require __DIR__ . '/../app/pages/candidate.php';
    exit;
}

if (preg_match('#^/placements/(\d+)$#', $path, $m)) {
    $_GET['id'] = (int) $m[1];
    require __DIR__ . '/../app/pages/placement.php';
    exit;
}

$routes = [
    '/activity'        => 'activity',
    '/search'          => 'search',
    '/screening-questions' => 'screening-questions',
    '/approvals'       => 'approvals',
    '/approval-chains' => 'approval-chains',
    '/contracts' => 'contracts',
    '/client-orders' => 'client-orders',
    '/client-portal' => 'client-portal',
    '/client-access' => 'client-access',
    '/'            => 'dashboard',
    '/candidates'  => 'candidates',
    '/roster'      => 'roster',
    '/hotels'      => 'hotels',
    '/travel'      => 'travel',
    '/hours'       => 'hours',
    '/billing'     => 'billing',
    '/people'      => 'people',
    '/job'         => 'job',
    '/account'     => 'account',
    '/account-security' => 'account-security',
    '/agency-setup' => 'agency-setup',
    '/offboarding' => 'offboarding',
    '/safety-plan' => 'safety-plan',
    '/qualifications' => 'qualifications',
    '/resumes' => 'resumes',
    '/screening-workflow' => 'screening-workflow',
    '/projects' => 'projects',
    '/portal' => 'portal',
    '/onboarding' => 'onboarding',
    '/operations' => 'operations',
    '/settings' => 'settings',
    '/privacy-requests' => 'privacy-requests',
    '/audit-log' => 'audit-log',
    '/system-health' => 'system-health',
    '/project-tasks' => 'project-tasks',
    '/my-tasks' => 'my-tasks',
    '/project-board' => 'project-board',
    '/workload' => 'workload',
    '/asset-valuation' => 'asset-valuation',
    '/training' => 'training',
    '/my-training' => 'my-training',
    '/recognition' => 'recognition',
    '/feedback' => 'feedback',
    '/surveys' => 'surveys',
    '/devices' => 'devices',
    '/contacts' => 'contacts',
    '/message-templates' => 'message-templates',
    '/driver-checks' => 'driver-checks',
    '/hiring' => 'hiring',
    '/reviews' => 'reviews',
    '/poster' => 'poster',
    '/my-jobs' => 'my-jobs',
    '/lateness' => 'lateness',
    '/folios' => 'folios',
    '/campaigns' => 'campaigns',
    '/home' => 'home',
    '/subscription' => 'subscription',
    '/requisitions' => 'requisitions',
    '/channels' => 'channels',
    '/ai-review' => 'ai-review',
    '/email-delivery' => 'email-delivery',
    '/structure' => 'structure',
    '/comms' => 'comms',
    '/notifications' => 'notifications',
    '/learning' => 'learning',
    '/organization' => 'organization',
    '/mining' => 'mining',
    '/manning' => 'manning',
    '/personnel' => 'personnel',
    '/recruitment' => 'recruitment',
    '/overview' => 'overview',
    '/timeoff' => 'timeoff',
    '/agreements' => 'agreements',
    '/inbox' => 'inbox',
    '/proofs' => 'proofs',
    '/employment' => 'employment',
    '/employee-folder' => 'employee-folder',
    '/checks' => 'checks',
    '/expenses' => 'expenses',
    '/advances' => 'advances',
    '/accounts-payable' => 'accounts-payable',
    '/payroll-export' => 'payroll-export',
    '/attendance' => 'attendance',
    '/attendance-week' => 'attendance-week',
    '/pay-rules' => 'pay-rules',
    '/leave-types' => 'leave-types',
    '/pay-items' => 'pay-items',
    '/payroll-runs' => 'payroll-runs',
    '/payslip' => 'payslip',
    '/my-payslips' => 'my-payslips',
    '/procurement' => 'procurement',
    '/change-requests' => 'change-requests',
    '/pay-grades' => 'pay-grades',
    '/assets' => 'assets',
    '/appraisals' => 'appraisals',
    '/appraisal-templates' => 'appraisal-templates',
    '/project-costs' => 'project-costs',
    '/accounting' => 'accounting',
    '/ledger' => 'ledger',
    '/statements' => 'statements',
    '/reports' => 'reports',
    '/analytics' => 'analytics',
    '/report-builder' => 'report-builder',
    '/integrations' => 'integrations',
    '/balances' => 'balances',
    '/periods' => 'periods',
    '/vendors' => 'vendors',
    '/bill-matching' => 'bill-matching',
    '/performance' => 'performance',
    '/benefits' => 'benefits',
    '/hr-records' => 'hr-records',
    '/hr-requests' => 'hr-requests',
    '/self-service' => 'self-service',
    '/client-invoices' => 'client-invoices',
    '/imports' => 'imports',
    '/employees' => 'employees',
    '/my-team' => 'my-team',
    '/notification-feed' => 'notification-feed',
];

$page = $routes[$path] ?? null;

if ($page === null) {
    http_response_code(404);
    render('404', ['path' => $path]);
    exit;
}

require __DIR__ . '/../app/pages/' . $page . '.php';
