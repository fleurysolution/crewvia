<?php
/**
 * P5-M23 (layout wave 4): how a count reads, in one language per run.
 *   php page_pass_probe.php fr   -> JSON of tn() for 0, 1 and 2, and the new strings
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

// After the bootstrap, which may open a session; nothing has asked for the locale yet.
$want = $argv[1] ?? 'en';
if ($want !== 'en') { $_SESSION['user'] = ['locale' => $want]; }

$out = ['locale' => locale()];
foreach ([0, 1, 2] as $n) {
    $out['requests'][$n] = tn($n, ':n request waiting', ':n requests waiting');
    $out['people'][$n] = tn($n, ':n person out on one job', ':n people out on one job');
}
$out['guarantee'] = t(':n-hour guarantee', ['n' => 50]);
$out['week'] = t('Week ending :date', ['date' => 'X']);
$out['folder'] = t('Employee folder');
$out['escaped'] = ten(1, '<b>:n</b> one', ':n many');
echo json_encode($out, JSON_UNESCAPED_UNICODE);
