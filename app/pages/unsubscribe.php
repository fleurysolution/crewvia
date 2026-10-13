<?php
/**
 * "Stop these emails" (T7), from the link at the foot of a campaign email.
 * Open to anyone: the link's token is the authentication. A visit shows a
 * button, so a mail scanner opening the link changes nothing; the button,
 * or a mail program's one-click unsubscribe (RFC 8058, a POST of
 * "List-Unsubscribe=One-Click"), records it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../campaigns.php';

$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$recipient = cm_recipient($token);
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $recipient) {
    cm_unsubscribe($recipient);
    $done = true;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
    http_response_code($recipient ? 200 : 404);
    exit($recipient ? 'Unsubscribed.' : 'Unknown link.');
}
if (! $recipient) {
    http_response_code(404);
}
?><!doctype html>
<html lang="<?= e(locale()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= te('Email preferences') ?> · <?= e($config['app_name']) ?></title>
<style>body{margin:0;font:15px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f4f6fa;color:#16202b;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:20px}
.box{background:#fff;border-radius:14px;padding:30px;max-width:440px;box-shadow:0 10px 30px rgba(5,20,40,.12)}h1{font-size:20px;margin:0 0 10px;color:#0d2137}
button{background:#346eb6;color:#fff;border:0;border-radius:9px;padding:11px 18px;font:inherit;font-weight:600;cursor:pointer}</style></head>
<body><div class="box" id="unsubscribe">
<?php if (! $recipient): ?>
  <h1><?= te('This link is not valid') ?></h1><p><?= te('It may have been copied incompletely. Nothing was changed.') ?></p>
<?php elseif ($done): ?>
  <h1 id="unsubscribed"><?= te('Done') ?></h1><p><?= te('You will not receive these job emails any more. You can still apply for jobs and use your portal.') ?></p>
<?php else: ?>
  <h1><?= te('Stop these job emails?') ?></h1><p><?= te(':name, you will no longer receive job offers by email from :agency.', ['name' => strtok((string) $recipient['full_name'], ' '), 'agency' => $config['app_name']]) ?></p>
  <form method="post" action="/unsubscribe"><input type="hidden" name="t" value="<?= e($token) ?>"><button id="confirm-unsubscribe"><?= te('Stop the emails') ?></button></form>
<?php endif; ?>
</div></body></html>
