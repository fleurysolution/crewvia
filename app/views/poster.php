<?php
/**
 * The job poster (P5-M46): a standalone page sized for a Letter sheet, with
 * no menu, so what prints is the poster. The bar at the top (print, save
 * the QR code, back) is hidden when printing.
 */
$place = trim(($vacancy['site_city'] ?? '') . ', ' . ($vacancy['site_state'] ?? ''), ', ');
$starts = $vacancy['starts_on'] ?: ($vacancy['job_starts'] ?? null);
$qrOnly = isset($_GET['qr']);
$lang = locale();
?><!doctype html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($vacancy['title']) ?> · <?= e($brand) ?></title>
<style>
  :root { --ink: #111827; --muted: #4b5563; --accent: #1d4ed8; --line: #d1d5db; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: var(--ink); background: #f3f4f6; }
  .bar { display: flex; gap: 8px; align-items: center; justify-content: center; padding: 12px; background: #fff; border-bottom: 1px solid var(--line); flex-wrap: wrap; }
  .bar a, .bar button { font: inherit; padding: 8px 14px; border-radius: 8px; border: 1px solid var(--line); background: #fff; color: var(--ink); text-decoration: none; cursor: pointer; }
  .bar .primary { background: var(--accent); color: #fff; border-color: var(--accent); }
  .sheet { width: 8.5in; min-height: 11in; margin: 16px auto; background: #fff; padding: 0.6in 0.6in 0.5in; display: flex; flex-direction: column; box-shadow: 0 1px 4px rgba(0,0,0,.12); }
  .brand { font-size: 18px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--accent); }
  .now { margin-top: 18px; font-size: 26px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
  h1 { font-size: 54px; line-height: 1.05; margin: 6px 0 10px; }
  .where { font-size: 24px; color: var(--muted); margin: 0 0 22px; }
  .facts { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 22px; }
  .fact { border: 2px solid var(--ink); border-radius: 12px; padding: 12px 18px; min-width: 2in; }
  .fact .v { font-size: 30px; font-weight: 800; }
  .fact .l { font-size: 15px; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; }
  .ask { font-size: 18px; line-height: 1.45; color: var(--ink); margin: 0 0 18px; white-space: pre-line; }
  .apply { margin-top: auto; display: flex; gap: 28px; align-items: center; border-top: 3px solid var(--ink); padding-top: 22px; }
  .qr { width: 2.6in; height: 2.6in; flex: none; }
  .qr canvas, .qr img { width: 100% !important; height: 100% !important; }
  .how .big { font-size: 34px; font-weight: 800; margin: 0 0 6px; }
  .how p { font-size: 18px; margin: 0 0 6px; color: var(--muted); }
  .how .url { font-size: 14px; color: var(--ink); word-break: break-all; }
  .qr-alone { display: flex; justify-content: center; padding: 24px; }
  .qr-alone .qr { width: 4in; height: 4in; background: #fff; padding: 16px; }
  @page { size: letter; margin: 0; }
  @media print { body { background: #fff; } .bar { display: none; } .sheet { margin: 0; box-shadow: none; } }
  @media (max-width: 860px) { .sheet { width: auto; min-height: 0; margin: 0; padding: 24px 16px; } h1 { font-size: 36px; } .apply { flex-direction: column; align-items: flex-start; } }
</style>
</head>
<body>
<div class="bar">
  <button type="button" class="primary" onclick="window.print()"><?= te('Print the poster') ?></button>
  <a id="qr-download" href="#" download="<?= e('apply-' . (int) $vacancy['id'] . '.png') ?>"><?= te('Save the QR code as an image') ?></a>
  <a href="<?= e($applyUrl) ?>" target="_blank" rel="noopener"><?= te('Open the application page') ?></a>
  <a href="/hiring?v=<?= (int) $vacancy['id'] ?>"><?= te('Back to Hiring') ?></a>
</div>
<?php if ($qrOnly): ?>
<div class="qr-alone"><div class="qr" id="poster-qr" data-url="<?= e($applyUrl) ?>"></div></div>
<?php else: ?>
<main class="sheet" id="poster">
  <div class="brand"><?= e($brand) ?></div>
  <div class="now"><?= te('Now hiring') ?></div>
  <h1><?= e($vacancy['title']) ?></h1>
  <p class="where"><?= e($place !== '' ? $place : (string) $vacancy['project']) ?><?= $starts ? ' · ' . e(starts_words((string) $starts)) : '' ?></p>
  <div class="facts">
    <?php if ($vacancy['shown_pay'] !== null): ?><div class="fact"><div class="v"><?= e(money((float) $vacancy['shown_pay'])) ?></div><div class="l"><?= te('an hour') ?></div></div><?php endif; ?>
    <?php if ($vacancy['shown_diem'] !== null && (float) $vacancy['shown_diem'] > 0): ?><div class="fact"><div class="v"><?= e(money((float) $vacancy['shown_diem'])) ?></div><div class="l"><?= te('per diem, a day') ?></div></div><?php endif; ?>
    <?php if ($vacancy['shown_hours'] !== null && (int) $vacancy['shown_hours'] > 0): ?><div class="fact"><div class="v"><?= (int) $vacancy['shown_hours'] ?></div><div class="l"><?= te('hours a week guaranteed') ?></div></div><?php endif; ?>
    <?php if ((int) $vacancy['openings'] > 0): ?><div class="fact"><div class="v"><?= (int) $vacancy['openings'] ?></div><div class="l"><?= te('openings') ?></div></div><?php endif; ?>
  </div>
  <?php if (trim((string) ($vacancy['requirements'] ?? '')) !== ''): ?><p class="ask"><strong><?= te('What it asks') ?>:</strong> <?= e(mb_strimwidth((string) $vacancy['requirements'], 0, 500, '…')) ?></p>
  <?php elseif (trim((string) $vacancy['description']) !== ''): ?><p class="ask"><?= e(mb_strimwidth((string) $vacancy['description'], 0, 600, '…')) ?></p><?php endif; ?>
  <div class="apply">
    <div class="qr" id="poster-qr" data-url="<?= e($applyUrl) ?>"></div>
    <div class="how">
      <p class="big"><?= te('Scan to apply') ?></p>
      <p><?= te('Apply from your phone in two minutes: your details, your CV, when you can start.') ?></p>
      <p><?= te('You get your own portal to follow your application. No phone call needed.') ?></p>
      <p class="url"><?= e($applyUrl) ?></p>
    </div>
  </div>
</main>
<?php endif; ?>
<script src="/assets/qrcode.min.js"></script>
<script>
  (function () {
    var box = document.getElementById('poster-qr');
    if (!box || typeof QRCode === 'undefined') { return; }
    new QRCode(box, { text: box.dataset.url, width: 600, height: 600, correctLevel: QRCode.CorrectLevel.M });
    var link = document.getElementById('qr-download');
    setTimeout(function () {
      var canvas = box.querySelector('canvas');
      if (canvas && link) { link.href = canvas.toDataURL('image/png'); }
    }, 200);
  })();
</script>
</body>
</html>
