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
header('Cache-Control: no-store');
require __DIR__ . '/../app/bootstrap.php';

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path   = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

// Stripe authenticates the raw POST with its signature, not a browser CSRF token.
if ($path === '/stripe-webhook') { require __DIR__.'/../app/pages/saas-webhook.php'; exit; }
if ($path === '/job-feed') { if($method!=='GET'){http_response_code(405);exit('GET required.');} require __DIR__.'/../app/pages/job-feed.php'; exit; }
if($path==='/recruiting-webhook'){require __DIR__.'/../app/pages/recruiting-webhook.php';exit;}
if($path==='/app-manifest'){require __DIR__.'/../app/pages/app-manifest.php';exit;}
if($path==='/sitemap.xml'){require __DIR__.'/../app/pages/sitemap.php';exit;}
if($path==='/docusign-webhook'){require __DIR__.'/../app/pages/docusign-webhook.php';exit;}
csrf_check();

if ($path === '/google-start') { require __DIR__.'/../app/pages/google-start.php'; exit; }
if ($path === '/google-callback') { require __DIR__.'/../app/pages/google-callback.php'; exit; }
// Public application and one-time account activation.
if (in_array($path, ['/apply','/activate','/join','/careers'], true)) { require __DIR__.'/../app/pages/'.substr($path,1).'.php'; exit; }
if(in_array($path,['/recover','/mfa'],true)){require __DIR__.'/../app/pages/'.substr($path,1).'.php';exit;}
// Routes that do not need a session.
if ($path === '/login') {
    require __DIR__ . '/../app/pages/login.php';
    exit;
}

if ($path === '/logout') {
    if ($method !== 'POST') { http_response_code(405); exit('Use the sign-out button.'); }
    log_activity('signed out');
    $_SESSION = [];
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
if ($fresh['role']==='client' && !in_array($path,['/client-portal','/account','/account-security','/language'],true)) {
    if($path==='/') redirect('/client-portal');
    http_response_code(403);render('403',['need'=>['client']]);exit;
}
if ($fresh['role'] === 'supervisor' && !in_array($path,['/procurement','/appraisals','/activity','/approvals','/my-team','/safety-plan','/portal','/account','/account-security','/language','/qualifications','/screening-workflow','/resumes','/offboarding','/safety-plan','/comms','/notifications','/notification-feed','/timeoff','/learning','/agreements','/contracts','/inbox','/notification-feed','/proofs','/employment','/employee-folder','/checks','/expenses','/attendance'],true)) redirect('/my-team');
if ($fresh['role'] === 'worker' && !in_array($path, ['/my-payslips','/appraisals','/payslip','/activity','/approvals','/portal','/account','/account-security','/language','/qualifications','/screening-workflow','/resumes','/offboarding','/safety-plan','/comms','/notifications','/learning','/timeoff','/agreements','/contracts','/inbox','/notification-feed','/proofs','/employment','/employee-folder','/checks','/expenses','/attendance'], true)) redirect('/portal');

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
    $_SESSION['job_id'] = (int)$selected['id'];
    // The switcher in the header wants the dashboard. The project list
    // wants the settings for the project just chosen. Only a path inside
    // this application is ever accepted.
    $then = (string)($_POST['then'] ?? '/');
    if ($then === '' || $then[0] !== '/' || str_starts_with($then, '//')) $then = '/';
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
