<?php
/** A request for quotation or a purchase order on paper: Letter, black on white, no browser stamp. */
$isOrder = $kind !== 'rfq';
$qty = static fn($v): string => rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.');
$authorised = $isOrder && $doc['status'] === 'approved';
?><!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
<meta charset="utf-8">
<title><?= e(($isOrder ? t('Purchase order') : t('Request for quotation')) . ' ' . $doc['reference']) ?></title>
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
  th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #000; vertical-align: top; }
  th { font-size: 9pt; text-transform: uppercase; letter-spacing: .04em; }
  .num { text-align: right; }
  .grid { display: flex; gap: 32px; margin-bottom: 8px; }
  .grid div { flex: 1; }
  .label { font-size: 9pt; text-transform: uppercase; letter-spacing: .04em; }
  .stamp { border: 2px solid #000; padding: 8px 12px; font-weight: bold; text-align: center; margin: 12px 0; }
  footer { margin-top: 28px; font-size: 9pt; }
  @media screen { body { max-width: 8.5in; margin: 0 auto; box-shadow: 0 0 0 1px #ccc; } }
</style>
</head>
<body>
<header>
  <div class="brand"><img src="/assets/app-icon.svg" alt=""><?= e($brand) ?></div>
  <div><h1><?= $isOrder ? te('Purchase order') : te('Request for quotation') ?></h1>
    <div class="ref"><?= e($doc['reference']) ?><?= $isOrder && (int) $doc['revision'] > 0 ? ' · ' . te('revision :n', ['n' => (int) $doc['revision']]) : '' ?><br><?= e(d(date('Y-m-d'))) ?></div></div>
</header>

<div class="grid">
  <div><div class="label"><?= te('To') ?></div><strong><?= e($doc['vendor_name']) ?></strong><?= ! empty($doc['hotel_name']) ? '<br>' . e($doc['hotel_name']) : '' ?></div>
  <div><div class="label"><?= te('For') ?></div><?= e($doc['project']) ?><?= $doc['site_name'] ? '<br>' . e($doc['site_name']) : '' ?></div>
  <div><div class="label"><?= $isOrder ? te('Needed') : te('Reply by') ?></div><?= $isOrder ? ($doc['needed_from'] ? e(d((string) $doc['needed_from'])) . ($doc['needed_to'] ? ' – ' . e(d((string) $doc['needed_to'])) : '') : '—') : e(d((string) $doc['reply_by'])) ?></div>
</div>

<table>
  <tr><th><?= te('Item') ?></th><th class="num"><?= te('Quantity') ?></th><?php if ($isOrder): ?><th class="num"><?= te('Unit price') ?></th><th class="num"><?= te('Total') ?></th><?php else: ?><th class="num"><?= te('Your unit price') ?></th><?php endif; ?></tr>
  <tr><td><strong><?= e($doc['title']) ?></strong><?= $doc['description'] ? '<br>' . e($doc['description']) : '' ?></td>
    <td class="num"><?= e($qty($doc['quantity'])) ?> <?= e(t((string) $doc['unit_label'])) ?></td>
    <?php if ($isOrder): ?><td class="num"><?= e(money($doc['unit_price'])) ?></td><td class="num"><strong><?= e(money($doc['total'])) ?></strong></td><?php else: ?><td class="num">&nbsp;</td><?php endif; ?></tr>
</table>

<?php if ($isOrder): ?>
  <?php if (count($doc['shares']) > 1): ?><p><span class="label"><?= te('Charged to') ?></span><br><?php foreach ($doc['shares'] as $s): ?><?= e($s['title'] . ' · ' . money($s['amount'])) ?><br><?php endforeach; ?></p><?php endif; ?>
  <?php if ($authorised): ?>
  <p><span class="label"><?= te('Authorised by') ?></span><br><?php foreach ($doc['approvals'] as $a): ?><?= e($a['name'] . ' · ' . ($a['approver'] === 'admin' ? t('administrator') : t('budget owner')) . ' · ' . d(substr((string) $a['created_at'], 0, 10))) ?><br><?php endforeach; ?></p>
  <p><?= te('Please quote this order\'s reference on your delivery note and your invoice.') ?></p>
  <?php else: ?>
  <div class="stamp"><?= te('NOT AUTHORISED — do not send to the vendor') ?></div>
  <?php endif; ?>
<?php else: ?>
  <p><?= te('Please send your unit price, how long it holds, and any condition, quoting :ref, by :date. This is a request for a price, not an order.', ['ref' => $doc['reference'], 'date' => d((string) $doc['reply_by'])]) ?></p>
<?php endif; ?>

<footer><?= e($brand) ?> · <?= e($doc['reference']) ?></footer>
</body>
</html>
