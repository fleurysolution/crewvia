<?php
/** A statement on paper: Letter, black on white, no browser stamp, the brand mark as an image. */
$titles = ['income' => t('Income statement'), 'balance' => t('Balance sheet'), 'cash' => t('Cash flow')];
?><!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
<meta charset="utf-8">
<title><?= e($titles[$statement] . ' · ' . $brand) ?></title>
<style>
  @page { size: Letter; margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; padding: 0.7in 0.75in; font: 11pt/1.45 Georgia, "Times New Roman", serif; color: #000; background: #fff; }
  header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 18px; }
  header img { height: 44px; width: 44px; }
  .brand { display: flex; gap: 12px; align-items: center; font-size: 15pt; font-weight: bold; }
  h1 { font-size: 17pt; margin: 0; text-align: right; }
  .ref { text-align: right; font-size: 10pt; }
  table { width: 100%; border-collapse: collapse; margin: 10px 0 16px; }
  th, td { text-align: left; padding: 5px 8px; vertical-align: top; }
  tr.head th { padding-top: 14px; font-size: 10pt; text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid #000; }
  tr.total th, tr.grand th { border-top: 1px solid #000; }
  tr.grand th { border-top: 2px solid #000; border-bottom: 3px double #000; }
  .num { text-align: right; white-space: nowrap; }
  .muted { color: #000; }
  .err { font-weight: bold; }
  footer { margin-top: 28px; font-size: 9pt; }
  @media screen { body { max-width: 8.5in; margin: 0 auto; box-shadow: 0 0 0 1px #ccc; } }
</style>
</head>
<body>
<header>
  <div class="brand"><img src="/assets/app-icon.svg" alt=""><?= e($brand) ?></div>
  <div><h1><?= e($titles[$statement]) ?></h1>
    <div class="ref"><?= $statement === 'balance' ? te('at :d', ['d' => d($asof)]) : te(':a to :b', ['a' => d($from), 'b' => d($to)]) ?><?= $statement === 'income' && $class !== '' ? '<br>' . e($class) : '' ?></div></div>
</header>
<?php require __DIR__ . '/_statement-body.php'; ?>
<?php if (! $integrity['ok']): ?><p class="err"><?= te('The ledger integrity check fails: these figures cannot be trusted until it is resolved.') ?></p><?php endif; ?>
<footer><?= te('Prepared from the Crewvia ledger on :d. Not an audited statement.', ['d' => d(date('Y-m-d'))]) ?></footer>
</body>
</html>
