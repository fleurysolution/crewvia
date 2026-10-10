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
        ['/',                   'Project dashboard',                  null],
        ['/projects',           'Projects',                           'admin'],
        ['/manning',            'Manning',                            ['recruiter','hotels','payroll']],
        ['/personnel',          'Promotions & transfers',             'recruiter'],
        ['/organization',       'Organization',                       ['recruiter','hotels','payroll']],
        ['/employees',          'Employee folders',                   ['recruiter','payroll']],
        ['/change-requests',    'Change requests',                    ['recruiter','payroll']],
        ['/timeoff',            'Time off',                           'recruiter'],
        ['/appraisals',         'Performance reviews',                'recruiter'],
        ['/contracts',          'Contracts & signatures',             'recruiter'],
        ['/agreements',         'Documents',                          'recruiter'],
        ['/checks',             'Background & drug',                  'recruiter'],
        ['/qualifications',     'Qualifications & expiry',            'recruiter'],
        ['/screening-workflow', 'Screening questionnaire & contacts', 'recruiter'],
        ['/employment',         'I-9 & W-4',                          'recruiter'],
        ['/proofs',             'Document proofs',                    'recruiter'],
        ['/mining',             'Talent search',                      'recruiter'],
        ['/resumes',            'Candidate resumes',                  'recruiter'],
        ['/learning',           'Safety & learning',                  null],
        ['/safety-plan',        'Site safety plan',                   ['recruiter','hotels','supervisor']],
        ['/offboarding',        'Offboarding',                        'recruiter'],
        ['/comms',              'Communications',                     null],
        ['/inbox',              'Gmail',                              null],
        ['/notifications',      'Notifications',                      null],
        ['/advances',           'Advances',                           ['payroll','recruiter']],
        ['/expenses',           'Reimbursements',                     'payroll'],
        ['/accounts-payable',   'Vendor payments',                    'payroll'],
        ['/attendance',         'Attendance',                         'payroll'],
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
        ['/agency-setup',       'Agency configuration',               'admin'],
        ['/subscription',       'Subscription & workspace',           'admin'],
        ['/recruitment',        'Application screening',              'recruiter'],
        ['/onboarding',         'Onboarding',                         'recruiter'],
        ['/requisitions',       'Requisitions',                       'recruiter'],
        ['/screening-questions','Screening questions',                'recruiter'],
        ['/channels',           'Distribution channels',              'recruiter'],
        ['/ai-review',          'AI Review',                          'recruiter'],
        ['/structure',          'Deployment view',                  'admin'],
        ['/email-delivery',     'Email delivery',                     'admin'],
        ['/operations',         'Operations',                         ['recruiter','hotels']],
        ['/assets',             'Assets',                             ['recruiter','hotels']],
        ['/candidates',         'Candidates',                         'recruiter'],
        ['/roster',             'Roster',                             'recruiter'],
        ['/hotels',             'Hotels',                             'hotels'],
        ['/procurement',        'Procurement',                        ['hotels','payroll','supervisor']],
        ['/vendors',            'Vendors',                            ['hotels','payroll']],
        ['/travel',             'Travel',                             'hotels'],
        ['/hours',              'Hours',                              'payroll'],
        ['/billing',            'Billing',                            'payroll'],
        ['/project-costs',      'Project costs',                      'payroll'],
        ['/balances',           'Receivables and payables',           'payroll'],
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

        $nav = [['/activity', 'Activity', null]];

        if ($crew) {
            $nav[] = ['/portal', 'My portal', null];
        }

        $nav = array_merge($nav, [
            ['/learning',      'Safety & learning', null],
            ['/safety-plan',   'Site safety plan',  null],
            ['/timeoff',       'Time off',          null],
            ['/appraisals',    'Performance reviews', null],
            ['/attendance',    'My attendance',     null],
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
                ['/offboarding',        'Offboarding',                        null],
            ]);
        }
    }

    if (($u['role'] ?? '') === 'supervisor') {
        array_unshift($nav, ['/my-team', 'My team', null]);
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
        'Overview' => ['/activity', '/approvals', '/search', '/overview', '/', '/projects',
                       '/my-team', '/portal'],
        'Recruiting' => ['/requisitions', '/candidates', '/recruitment', '/screening-questions', '/mining',
                         '/resumes', '/ai-review', '/channels'],
        'Hiring' => ['/contracts', '/onboarding', '/employment', '/checks',
                     '/qualifications', '/screening-workflow', '/proofs',
                     '/agreements', '/employee-folder', '/change-requests'],
        'Deployment' => ['/structure', '/roster', '/manning', '/hotels', '/procurement', '/vendors', '/travel', '/operations', '/assets'],
        'Running the job' => ['/attendance', '/attendance-week', '/timeoff', '/appraisals', '/learning', '/safety-plan',
                              '/comms', '/inbox', '/notifications'],
        'Pay and billing' => ['/hours', '/payroll-runs', '/pay-rules', '/pay-items', '/advances', '/expenses', '/payroll-export',
                              '/accounts-payable', '/billing', '/project-costs', '/client-invoices', '/balances', '/accounting', '/periods'],
        'Clients' => ['/client-orders', '/client-access', '/client-portal'],
        'Administration' => ['/people', '/leave-types', '/pay-grades', '/appraisal-templates', '/organization', '/job', '/employees', '/personnel',
                             '/offboarding', '/imports', '/settings', '/agency-setup',
                             '/email-delivery', '/subscription', '/approval-chains'],
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
