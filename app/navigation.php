<?php
/**
 * The workspace menu, and the order it reads in.
 *
 * It lives here rather than inside the layout because more than one screen
 * needs it: the sidebar prints it, and Settings uses it to show an
 * administrator which screens a desk actually opens before granting it. A
 * second, hand-kept copy is what made the worker menu offer eleven screens
 * the pages themselves refused.
 */

declare(strict_types=1);

/**
 * Every entry a signed-in person may be offered: [path, label, role spec].
 * A null role spec means anybody signed in; a string or list of strings is
 * passed to can().
 *
 * @return list<array{0:string,1:string,2:string|list<string>|null}>
 */
function workspace_nav(?array $u): array
{
    $nav = [
        ['/activity',           'Activity',                           null],
        ['/search',             'Search',                             ['recruiter','hotels','payroll']],
        ['/approvals',          'Approvals',                          null],
        ['/approval-chains',    'Approval chains',                    'admin'],
        ['/client-orders',      'Client requests',                    'recruiter'],
        ['/client-access',      'Client access',                      'admin'],
        ['/overview',           'Executive overview',                 'admin'],
        ['/reports',            'Reports',                            ['recruiter','payroll']],
        ['/analytics',          'Dashboards',                         ['recruiter','payroll','hotels']],
        ['/report-builder',     'Report builder',                     ['recruiter','payroll']],
        ['/',                   'Project dashboard',                  null],
        ['/clients',            'Clients',                            'admin'],
        ['/projects',           'Projects',                           'admin'],
        ['/project-tasks',      'Project tasks',                      ['recruiter','hotels','payroll']],
        ['/project-board',      'Project board',                      ['recruiter','hotels','payroll']],
        ['/workload',           'Workload',                           'recruiter'],
        ['/my-tasks',           'My tasks',                           null],
        ['/manning',            'Manning',                            ['recruiter','hotels','payroll']],
        ['/personnel',          'Promotions & transfers',             'recruiter'],
        ['/organization',       'Organization',                       ['recruiter','hotels','payroll']],
        ['/employees',          'Employee folders',                   ['recruiter','payroll']],
        ['/change-requests',    'Change requests',                    ['recruiter','payroll']],
        ['/tax-forms',          'Forms to sign',                      'recruiter'],
        ['/forms-register',     'Forms register',                     'admin'],
        ['/timeoff',            'Time off',                           'recruiter'],
        ['/appraisals',         'Performance reviews',                'recruiter'],
        ['/performance',        'Goals and development',              'recruiter'],
        ['/hr-records',         'HR records',                         'recruiter'],
        ['/hr-requests',        'HR requests',                        ['recruiter','payroll']],
        ['/contracts',          'Contracts & signatures',             'recruiter'],
        ['/agreements',         'Documents',                          'recruiter'],
        ['/checks',             'Background & drug',                  'recruiter'],
        ['/driver-checks',      'Driving records',                    'recruiter'],
        ['/qualifications',     'Qualifications & expiry',            'recruiter'],
        ['/screening-workflow', 'Screening questionnaire & contacts', 'recruiter'],
        ['/employment',         'I-9 & W-4',                          'recruiter'],
        ['/proofs',             'Document proofs',                    'recruiter'],
        ['/mining',             'Talent search',                      'recruiter'],
        ['/resumes',            'Candidate documents',                'recruiter'],
        ['/reviews',            'Candidate reviews',                  ['recruiter','supervisor']],
        ['/message-templates',  'Text messages',                      'recruiter'],
        ['/learning',           'Safety & learning',                  null],
        ['/training',           'Training',                           'recruiter'],
        ['/my-training',        'My training',                        null],
        ['/recognition',        'Recognition',                        null],
        ['/feedback',           'Feedback',                           null],
        ['/surveys',            'Surveys',                            null],
        ['/devices',            'Phones and notifications',           null],
        ['/safety-plan',        'Site safety plan',                   ['recruiter','hotels','supervisor']],
        ['/offboarding',        'Offboarding',                        'recruiter'],
        ['/comms',              'Communications',                     null],
        ['/inbox',              'Gmail',                              null],
        ['/notifications',      'Notifications',                      null],
        ['/advances',           'Advances',                           ['payroll','recruiter']],
        ['/expenses',           'Reimbursements',                     'payroll'],
        ['/accounts-payable',   'Vendor payments',                    'payroll'],
        ['/attendance',         'Attendance',                         'payroll'],
        ['/lateness',           'Lateness',                           'payroll'],
        ['/attendance-week',    'Week check',                         'payroll'],
        ['/pay-rules',          'Pay rules',                          'payroll'],
        ['/leave-types',        'Leave types',                        'admin'],
        ['/pay-grades',         'Pay grades',                         'admin'],
        ['/appraisal-templates','Appraisal templates',                'admin'],
        ['/pay-items',          'Pay items',                          'payroll'],
        ['/payroll-runs',       'Pay periods',                        'payroll'],
        ['/payroll-export',     'Payroll export',                     'payroll'],
        ['/adp',                'ADP',                                'payroll'],
        ['/arrivals',           'Flight arrivals',                    ['hotels','recruiter','payroll']],
        ['/checklists',         'Checklists',                         'recruiter'],
        ['/client-invoices',    'Client invoices',                    'payroll'],
        ['/imports',            'Import spreadsheets',                'admin'],
        ['/settings',           'Settings',                           'admin'],
        ['/audit-log',          'Audit log',                          'admin'],
        ['/system-health',      'System health',                      'admin'],
        ['/privacy-requests',   'Privacy',                            'admin'],
        ['/agency-setup',       'Agency configuration',               'admin'],
        ['/subscription',       'Subscription & workspace',           'admin'],
        // P5-M100: the count Fleury Solutions bills, as RSS sees it before the invoice.
        ['/crewvia-billing',    'Crewvia subscription',               'admin'],
        ['/recruitment',        'Application screening',              'recruiter'],
        ['/onboarding',         'Onboarding',                         'recruiter'],
        ['/hiring',             'Hiring',                             'recruiter'],
        ['/campaigns',          'Campaign email',                     'recruiter'],
        ['/requisitions',       'Requisitions',                       'recruiter'],
        // P5-M100: approved people waiting for their next step.
        ['/follow-up',          'Approved, next step',                'recruiter'],
        // P5-M104: the emails every applicant receives.
        ['/candidate-emails',   'Emails to applicants',               'admin'],
        // P5-M93: the public job board, every open job, as applicants see it.
        ['/careers',            'Careers page',                       ['recruiter','hotels','payroll']],
        ['/screening-questions','Screening questions',                'recruiter'],
        ['/channels',           'Distribution channels',              'recruiter'],
        ['/ai-review',          'AI Review',                          'recruiter'],
        ['/structure',          'Deployment view',                  'admin'],
        ['/email-delivery',     'Email delivery',                     'admin'],
        ['/integrations',       'Integrations',                       'admin'],
        ['/operations',         'Operations',                         ['recruiter','hotels']],
        ['/assets',             'Assets',                             ['recruiter','hotels']],
        ['/asset-valuation',    'Asset valuation',                    ['hotels','payroll']],
        ['/candidates',         'Candidates',                         'recruiter'],
        ['/roster',             'Roster',                             'recruiter'],
        ['/hotels',             'Hotels',                             'hotels'],
        ['/folios',             'Hotel folios',                       ['hotels', 'payroll']],
        ['/procurement',        'Procurement',                        ['hotels','payroll','supervisor']],
        ['/vendors',            'Vendors',                            ['hotels','payroll']],
        ['/travel',             'Travel',                             'hotels'],
        ['/hours',              'Hours',                              'payroll'],
        ['/billing',            'Billing',                            'payroll'],
        ['/project-costs',      'Project costs',                      'payroll'],
        ['/benefits',           'Benefits',                           'payroll'],
        ['/balances',           'Receivables and payables',           'payroll'],
        ['/bill-matching',      'Bill matching',                      'payroll'],
        ['/ledger',             'Ledger',                             'payroll'],
        ['/statements',         'Financial statements',               'payroll'],
        ['/accounting',         'QuickBooks export',                  'payroll'],
        ['/periods',            'Financial periods',                  'payroll'],
        ['/people',             'Team',                               'admin'],
        ['/job',                'Job setup',                          'admin'],
    ];

    // A worker and a supervisor both sit outside the desks, but they are not
    // the same person: a worker has a crew record and reads their own file, a
    // supervisor is staff and has none. Every "my ..." screen decides what to
    // show with is_worker_account(), so the menu asks the same question and
    // stops offering screens that answer "Not your desk".
    if (in_array($u['role'] ?? '', ['worker', 'supervisor'], true)) {
        $crew = is_worker_account();

        $nav = [['/activity', 'Activity', null], ['/my-tasks', 'My tasks', null], ['/my-training', 'My training', null],
                ['/recognition', 'Recognition', null], ['/feedback', 'Feedback', null], ['/surveys', 'Surveys', null],
                ['/devices', 'Phones and notifications', null]];

        if ($crew) {
            $nav[] = ['/portal', 'My portal', null];
            $nav[] = ['/my-jobs', 'Jobs for you', null];
            $nav[] = ['/hr-requests', 'My HR requests', null];
        }

        $nav = array_merge($nav, [
            ['/learning',      'Safety & learning', null],
            ['/safety-plan',   'Site safety plan',  null],
            ['/timeoff',       'Time off',          null],
            ['/appraisals',    'Performance reviews', null],
            ['/performance',   'Goals and development', null],
            ['/hr-records',    'HR records',          null],
            ['/attendance',    'My attendance',     null],
            ['/lateness',      'Lateness',          null],
            ['/comms',         'Communications',    null],
            ['/inbox',         'Gmail',             null],
            ['/notifications', 'Notifications',     null],
        ]);

        if ($crew) {
            $nav = array_merge($nav, [
                ['/contracts',          'Contracts & signatures',             null],
                ['/agreements',         'Documents',                          null],
                ['/proofs',             'Proof uploads',                      null],
                ['/employment',         'I-9 & W-4',                          null],
                ['/employee-folder',    'My employee folder',                 null],
                ['/checks',             'My screening',                       null],
                ['/qualifications',     'Qualifications & expiry',            null],
                ['/screening-workflow', 'Screening questionnaire & contacts', null],
                ['/expenses',           'My reimbursements',                  null],
                ['/my-payslips',        'My pay statements',                  null],
                ['/benefits',           'My benefits',                        null],
                ['/offboarding',        'Offboarding',                        null],
            ]);
        }
    }

    // Wave 2: the recruiting, Logistics and payroll desks open on Today.
    if (in_array($u['role'] ?? '', ['recruiter', 'hotels', 'payroll'], true)) {
        array_unshift($nav, ['/home', 'Today', null]);
    }

    if (($u['role'] ?? '') === 'supervisor') {
        array_unshift($nav, ['/my-team', 'My team', null]);
        // P5-M44: the applicants passed to them for a decision, and the interviews they hold.
        $nav[] = ['/reviews', 'Candidate reviews', null];
        // P5-M01: the tasks of the projects they supervise a crew on.
        $nav[] = ['/project-tasks', 'Project tasks', null];
        $nav[] = ['/project-board', 'Project board', null];
        $nav[] = ['/workload', 'Workload', null];
        // A supervisor raises what the crew needs, and may own the budget.
        $nav[] = ['/procurement', 'Procurement', null];
    }

    if (($u['role'] ?? '') === 'client') {
        $nav = [['/client-portal', 'Client portal', null]];
    }

    // P5-M54: a candidate's menu holds what a candidate does; the employee screens come with the hire.
    if (worker_is_candidate($u)) {
        $nav = array_values(array_filter($nav, static fn($item) => in_array($item[0], candidate_paths(), true)));
    }

    return $nav;
}

/**
 * P5-M58: whether the signed-in person can open this screen, by the menu's own definition (the screen
 * is in their menu, and their desk opens it). A link to a screen that answers "Not your desk" is not
 * shown as a link: the audit of 16 Oct 2026 found the project dashboard sending Hotels, Payroll and
 * Recruiting to screens their desk does not open.
 */
function workspace_can_open(string $path, ?array $u = null): bool
{
    $u ??= user();
    foreach (workspace_nav($u) as [$href, , $need]) {
        if ($href === $path) {
            return $need === null || can(...(array) $need);
        }
    }

    return false;
}

/**
 * Wave 3: the screens each role opens every day, in the order of its day.
 * They stay in view at the top of the menu; every other screen the role may
 * open is folded under "More", in its section. A screen listed here that the
 * person may not open is simply not shown, so the list never widens access.
 *
 * @return list<string> paths
 */
function workspace_daily(?array $u): array
{
    return match ($u['role'] ?? '') {
        // P5-M99: the owner's and managers' day in eight screens; the rest under More (or bookmarked).
        'admin'      => ['/overview', '/activity', '/approvals', '/projects', '/hiring', '/reviews', '/people', '/careers'],
        'recruiter'  => ['/home', '/activity', '/candidates', '/reviews', '/careers', '/requisitions', '/recruitment', '/onboarding', '/tax-forms', '/contracts', '/client-orders', '/approvals'],
        'hr'         => ['/activity', '/hiring', '/onboarding', '/tax-forms', '/candidates', '/contracts', '/manning', '/my-tasks', '/approvals'],
        'hotels'     => ['/home', '/activity', '/arrivals', '/hotels', '/travel', '/folios', '/manning', '/operations', '/procurement', '/approvals'],
        'payroll'    => ['/home', '/activity', '/hours', '/attendance-week', '/lateness', '/payroll-runs', '/payroll-export', '/billing', '/client-invoices', '/accounts-payable', '/approvals'],
        'supervisor' => ['/my-team', '/activity', '/reviews', '/attendance', '/lateness', '/my-tasks'],
        'worker'     => worker_is_candidate($u) ? ['/portal', '/my-jobs', '/notifications', '/activity'] : ['/portal', '/my-jobs', '/attendance', '/timeoff', '/my-payslips', '/notifications', '/activity'],
        default      => [],
    };
}

/**
 * The pages that read the working project, where the project bar above the
 * page means something. Elsewhere (Team, Settings, Integrations...) it only
 * suggested the page depended on a project, and is not shown.
 */
function workspace_project_page(string $path): bool
{
    static $pages = ['/', '/accounts-payable', '/activity', '/advances', '/agency-setup', '/agreements', '/ai-review', '/appraisals', '/attendance', '/attendance-week', '/billing', '/channels', '/checks', '/client-invoices', '/comms', '/contracts', '/employment', '/expenses', '/folios', '/hotels', '/hours', '/imports', '/job', '/lateness', '/learning', '/manning', '/offboarding', '/onboarding', '/operations', '/organization', '/pay-rules', '/payroll-export', '/personnel', '/procurement', '/project-board', '/project-costs', '/project-tasks', '/qualifications', '/recruitment', '/requisitions', '/roster', '/safety-plan', '/screening-questions', '/screening-workflow', '/structure', '/timeoff', '/travel'];

    return in_array($path, $pages, true) || (bool) preg_match('#^/(candidates|placements)/\d+$#', $path);
}

/** The sidebar section a path belongs to, for the label above its title. */
function workspace_section_of(string $path, array $sections): ?string
{
    $path = preg_replace('#/\d+$#', '', $path) ?: '/';
    foreach ($sections as $section => $links) {
        if (in_array($path, $links, true)) {
            return $section;
        }
    }

    return null;
}

/**
 * The sidebar sections, in the order the business runs: find people, hire
 * them, send them, run the job, pay everyone, bill the client. Read top to
 * bottom it explains the product.
 *
 * @return array<string,list<string>>
 */
function workspace_sections(?array $u = null): array
{
    // A worker or a supervisor sees their own menu in their own words: the
    // desks' sections filed their contracts under "Hiring" and their HR
    // record under "Administration".
    if (in_array($u['role'] ?? '', ['worker', 'supervisor'], true)) {
        return [
            'My work' => ['/portal', '/my-jobs', '/my-team', '/reviews', '/my-tasks', '/project-tasks', '/project-board', '/workload', '/attendance', '/lateness', '/timeoff', '/procurement'],
            'Safety and training' => ['/safety-plan', '/learning', '/my-training'],
            'My papers' => ['/contracts', '/agreements', '/proofs', '/employment', '/employee-folder', '/checks', '/qualifications', '/screening-workflow'],
            'My pay' => ['/self-service', '/my-payslips', '/expenses', '/benefits'],
            'HR' => ['/hr-requests', '/hr-records', '/appraisals', '/performance', '/offboarding'],
            'Messages and recognition' => ['/activity', '/notifications', '/comms', '/inbox', '/recognition', '/feedback', '/surveys', '/devices'],
        ];
    }

    return [
        'Overview' => ['/home', '/activity', '/approvals', '/search', '/overview', '/reports', '/analytics', '/report-builder', '/', '/projects',
                       '/my-team', '/portal', '/project-tasks', '/project-board', '/workload', '/my-tasks'],
        'Recruiting' => ['/careers', '/reviews', '/follow-up', '/candidate-emails', '/campaigns', '/checklists', '/requisitions', '/candidates', '/recruitment', '/screening-questions', '/mining',
                         '/resumes', '/ai-review', '/channels', '/message-templates'],
        'Hiring' => ['/hiring', '/contracts', '/onboarding', '/employment', '/checks', '/driver-checks',
                     '/qualifications', '/screening-workflow', '/proofs',
                     '/agreements', '/employee-folder', '/change-requests', '/tax-forms', '/forms-register'],
        'Deployment' => ['/structure', '/roster', '/manning', '/hotels', '/folios', '/procurement', '/vendors', '/arrivals', '/travel', '/operations', '/assets', '/asset-valuation'],
        'Running the job' => ['/attendance', '/lateness', '/attendance-week', '/timeoff', '/hr-requests', '/appraisals', '/performance', '/learning', '/training', '/my-training', '/recognition', '/feedback', '/surveys', '/devices', '/safety-plan',
                              '/comms', '/inbox', '/notifications'],
        'Pay and billing' => ['/hours', '/payroll-runs', '/pay-rules', '/pay-items', '/benefits', '/advances', '/expenses', '/payroll-export', '/adp',
                              '/accounts-payable', '/billing', '/project-costs', '/client-invoices', '/balances', '/bill-matching', '/ledger', '/statements', '/accounting', '/periods'],
        'Clients' => ['/clients', '/client-orders', '/client-access', '/client-portal'],
        'Administration' => ['/people', '/hr-records', '/leave-types', '/pay-grades', '/appraisal-templates', '/organization', '/job', '/employees', '/personnel',
                             '/offboarding', '/imports', '/settings', '/agency-setup',
                             '/email-delivery', '/integrations', '/audit-log', '/system-health', '/privacy-requests', '/subscription', '/crewvia-billing', '/approval-chains'],
    ];
}

/**
 * Which screens a desk opens, by the menu's own definition.
 *
 * Settings grants a desk to a role, and an administrator should be able to see
 * what that means before doing it - without anybody maintaining a description
 * of the list alongside the list.
 *
 * @return list<string> labels, in sidebar order
 */
function workspace_desk_screens(string $desk): array
{
    $order = [];
    $place = 0;

    foreach (workspace_sections() as $links) {
        foreach ($links as $link) {
            $order[$link] = $place++;
        }
    }

    $screens = [];

    foreach (workspace_nav(['role' => 'admin']) as [$href, $label, $need]) {
        if ($need !== null && in_array($desk, (array) $need, true)) {
            $screens[$href] = $label;
        }
    }

    uksort($screens, fn ($a, $b) => ($order[$a] ?? 999) <=> ($order[$b] ?? 999));

    return array_values($screens);
}
