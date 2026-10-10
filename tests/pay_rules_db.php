<?php
/**
 * P1-M03 at the database level, on the isolated test database only:
 * rollback, upgrade, silence on a repeat, the seeded draft, and the
 * project pay_rules_http.py is tested on. Runs after the older HTTP suites,
 * so the ADP settings it writes cannot change what they expected.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') {
    fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n");
    exit(2);
}

$results = [];

function check(string $label, bool $condition, string $detail = ''): void
{
    global $results;
    if (! $condition) { fwrite(STDERR, 'FAIL ' . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL); exit(1); }
    $results[] = $label;
    echo 'PASS ' . $label . PHP_EOL;
}

function sql_file(string $path): void
{
    foreach (preg_split('/;\s*\n/', (string) file_get_contents($path)) as $chunk) {
        $lines = array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'));
        $statement = trim(implode("\n", $lines));
        if ($statement !== '') { db()->exec($statement); }
    }
}

function run_php(string $script): array
{
    $out = []; $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

$has = static fn(string $table, ?string $column = null): bool => (bool) ($column === null
    ? val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table])
    : val('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $column]));
$app = __DIR__ . '/test-app';

check('P1-M03 is in place before the rollback',
      $has('pay_rule_sets') && $has('pay_rule_holidays') && $has('pay_rule_shift_premiums') && $has('jobs', 'pay_rule_set_id'));
$jobs = (int) val('SELECT COUNT(*) FROM jobs');
sql_file($app . '/install/rollback/p1-m03.sql');
check('Rollback removes every P1-M03 object',
      ! $has('pay_rule_sets') && ! $has('pay_rule_holidays') && ! $has('pay_rule_shift_premiums') && ! $has('jobs', 'pay_rule_set_id'));
check('Rollback keeps every project', (int) val('SELECT COUNT(*) FROM jobs') === $jobs);

[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade after the rollback succeeds', $code === 0, $first);
check('Upgrade announces the project column', str_contains($first, 'Pay rules: projects can now be given a confirmed pay rule set'), $first);
check('Upgrade seeds the federal baseline as a draft, not active',
      str_contains($first, 'a draft federal baseline was added')
      && val("SELECT status FROM pay_rule_sets WHERE name = 'US federal baseline'") === 'draft');
check('No project is given a rule set by the upgrade', (int) val('SELECT COUNT(*) FROM jobs WHERE pay_rule_set_id IS NOT NULL') === 0);

[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P1-M03', $code === 0 && ! str_contains($second, 'Pay rules:'), $second);
check('A second upgrade adds no second baseline', (int) val("SELECT COUNT(*) FROM pay_rule_sets WHERE name = 'US federal baseline'") === 1);

// ── the project the screens are tested on ──────────────────────────────
$fixture  = json_decode((string) file_get_contents(__DIR__ . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$admin    = (int) val("SELECT id FROM users WHERE email = 'admin@test.invalid'");
$week     = week_ending(date('Y-m-d', strtotime('-14 days')));
$week2    = date('Y-m-d', strtotime($week . ' -7 days'));
$day      = static fn(string $we, int $back): string => date('Y-m-d', strtotime($we . ' -' . $back . ' days'));

q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status)
   VALUES (?,'M03 rules project','Synthetic project for pay rules.',20,35,0,0,0,'active')", [(int) $fixture['client']]);
$job = (int) db()->lastInsertId();

$worker = static function (string $label, string $adp) use ($password): int {
    $email = 'm03-' . $label . '@test.invalid';
    q('INSERT INTO candidates(full_name,email) VALUES (?,?)', ['M03 ' . $label, $email]);
    $cid = (int) db()->lastInsertId();
    q("INSERT INTO employee_profiles(candidate_id,employment_type,flsa_status,adp_employee_id,payment_method) VALUES (?,'hourly','non_exempt',?,'direct_deposit')", [$cid, $adp]);
    return $cid;
};
$place = static function (int $cid, int $jobId, string $start) {
    q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate) VALUES (?,?,'on_site',?,20,35)", [$cid, $jobId, $start]);
    $pid = (int) db()->lastInsertId();
    q("INSERT INTO assignment_details(placement_id,trade,shift_label) VALUES (?,'Welder','Night shift')", [$pid]);
    return $pid;
};
$attend = static function (int $pid, string $date, float $h) use ($admin) {
    q("INSERT INTO attendance_records(placement_id,work_date,hours,status,submitted_by,reviewed_by,reviewed_at) VALUES (?,?,?,'approved',?,?,NOW())",
      [$pid, $date, $h, $admin, $admin]);
};

// One person, one week: 12, 12, 8, 8, 8 - Monday to Friday.
$long = $worker('long week', 'M03LONG');
$pLong = $place($long, $job, $day($week2, 6));
foreach ([5 => 12, 4 => 12, 3 => 8, 2 => 8, 1 => 8] as $back => $h) { $attend($pLong, $day($week, $back), (float) $h); }

// One person the week before, with a day on another project as well.
$split = $worker('two projects', 'M03SPLIT');
$pSplit = $place($split, $job, $day($week2, 6));
$pElse = $place($split, (int) $fixture['b'], $day($week2, 6));
$attend($pSplit, $day($week2, 5), 8.0);
$attend($pElse, $day($week2, 4), 8.0);

foreach (['adp_company_code' => 'M03CO', 'adp_hours_code' => 'REG', 'adp_overtime_code' => 'OT', 'adp_perdiem_code' => 'PD'] as $k => $v) {
    q('INSERT INTO platform_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)', [$k, $v]);
}

// Its own administrator: the shared one is limited to 10 sign-ins in 15
// minutes (BACKLOG Q1), and every suite signing in as it ran it out.
q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,'admin',0)",
  ['M03 administrator', 'm03-admin@test.invalid', password_hash('TestPassword123!', PASSWORD_DEFAULT)]);

file_put_contents(__DIR__ . '/m03.json', json_encode([
    'job' => $job, 'week' => $week, 'week2' => $week2, 'holiday' => $day($week, 3),
    'p_long' => $pLong, 'p_split' => $pSplit,
]));
file_put_contents(__DIR__ . '/m03-db-results.json', json_encode($results, JSON_PRETTY_PRINT));
