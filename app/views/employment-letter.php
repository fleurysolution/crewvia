<?php
/** An employment letter on paper: Letter, black on white, the brand mark, no browser stamp. */
?><!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
<meta charset="utf-8">
<title><?= e(t('Employment verification letter') . ' ' . $r['reference']) ?></title>
<style>
  @page { size: Letter; margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; padding: 0.9in 1in; font: 12pt/1.6 Georgia, "Times New Roman", serif; color: #000; background: #fff; }
  header { display: flex; align-items: center; gap: 12px; border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 28px; font-size: 15pt; font-weight: bold; }
  header img { height: 44px; width: 44px; }
  .date { text-align: right; margin-bottom: 24px; }
  .letter { white-space: pre-wrap; }
  .sign { margin-top: 36px; }
  footer { margin-top: 48px; font-size: 9pt; }
  @media screen { body { max-width: 8.5in; margin: 0 auto; box-shadow: 0 0 0 1px #ccc; } }
</style>
</head>
<body>
<header><img src="/assets/app-icon.svg" alt=""><?= e($brand) ?></header>
<div class="date"><?= e(d(substr((string) $r['letter_issued_at'], 0, 10))) ?></div>
<div class="letter"><?= e((string) $r['letter_text']) ?></div>
<div class="sign"><?= e($issuer) ?><br><?= e($brand) ?></div>
<footer><?= e($r['reference']) ?> · <?= te('Fingerprint') ?> <?= e(substr((string) $r['letter_sha256'], 0, 16)) ?></footer>
</body>
</html>
