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
        ['/resumes',            'Candidate resumes',                  'recruiter'],
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
        ['/client-invoices',    'Client invoices',                    'payroll'],
        ['/imports',            'Import spreadsheets',                'admin'],
        ['/settings',           'Settings',                           'admin'],
        ['/audit-log',          'Audit log',                          'admin'],
        ['/system-health',      'System health',                      'admin'],
        ['/privacy-requests',   'Privacy',                            'admin'],
        ['/agency-setup',       'Agency configuration',               'admin'],
        ['/subscription',       'Subscription & workspace',           'admin'],
        ['/recruitment',        'Application screening',              'recruiter'],
        ['/onboarding',         'Onboarding',                         'recruiter'],
        ['/hiring',             'Hiring',                             'recruiter'],
        ['/requisitions',       'Requisitions',                       'recruiter'],
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
            $nav[] = ['/self-service', 'My self-service', null];
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

    if (($u['role'] ?? '') === 'supervisor') {
        array_unshift($nav, ['/my-team', 'My team', null]);
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

    return $nav;
}

/**
 * The sidebar sections, in the order the business runs: find people, hire
 * them, send them, run the job, pay everyone, bill the client. Read top to
 * bottom it explains the product.
 *
 * @return array<string,list<string>>
 */
function workspace_sections(): array
{
    return [
        'Overview' => ['/activity', '/approvals', '/search', '/overview', '/reports', '/analytics', '/report-builder', '/', '/projects',
                       '/my-team', '/portal', '/project-tasks', '/project-board', '/workload', '/my-tasks'],
        'Recruiting' => ['/hiring', '/requisitions', '/candidates', '/recruitment', '/screening-questions', '/mining',
                         '/resumes', '/ai-review', '/channels', '/message-templates'],
        'Hiring' => ['/contracts', '/onboarding', '/employment', '/checks', '/driver-checks',
                     '/qualifications', '/screening-workflow', '/proofs',
                     '/agreements', '/employee-folder', '/change-requests'],
        'Deployment' => ['/structure', '/roster', '/manning', '/hotels', '/folios', '/procurement', '/vendors', '/travel', '/operations', '/assets', '/asset-valuation'],
        'Running the job' => ['/attendance', '/lateness', '/attendance-week', '/timeoff', '/hr-requests', '/appraisals', '/performance', '/learning', '/training', '/my-training', '/recognition', '/feedback', '/surveys', '/devices', '/safety-plan',
                              '/comms', '/inbox', '/notifications'],
        'Pay and billing' => ['/hours', '/payroll-runs', '/pay-rules', '/pay-items', '/benefits', '/advances', '/expenses', '/payroll-export',
                              '/accounts-payable', '/billing', '/project-costs', '/client-invoices', '/balances', '/bill-matching', '/ledger', '/statements', '/accounting', '/periods'],
        'Clients' => ['/client-orders', '/client-access', '/client-portal'],
        'Administration' => ['/people', '/hr-records', '/leave-types', '/pay-grades', '/appraisal-templates', '/organization', '/job', '/employees', '/personnel',
                             '/offboarding', '/imports', '/settings', '/agency-setup',
                             '/email-delivery', '/integrations', '/audit-log', '/system-health', '/privacy-requests', '/subscription', '/approval-chains'],
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
