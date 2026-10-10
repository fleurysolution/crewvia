<?php
/** A report on paper: Letter, sideways when it is wide, black on white, no browser stamp, the brand mark as an image. */
$wide = count($result['columns']) > 6;
$scope = [];
if (in_array('period', $def['filters'], true)) { $scope[] = t(':a to :b', ['a' => d($filters['from']), 'b' => d($filters['to'])]); }
if (in_array('asof', $def['filters'], true)) { $scope[] = t('at :d', ['d' => d($filters['asof'])]); }
if (in_array('year', $def['filters'], true)) { $scope[] = (string) $filters['year']; }
if ($project !== '') { $scope[] = $project; }
?><!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
<meta charset="utf-8">
<title><?= e(t($def['title']) . ' · ' . $brand) ?></title>
<style>
  @page { size: Letter<?= $wide ? ' landscape' : '' ?>; margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; padding: 0.6in 0.6in; font: 9.5pt/1.4 Georgia, "Times New Roman", serif; color: #000; background: #fff; }
  header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 14px; }
  header img { height: 40px; width: 40px; }
  .brand { display: flex; gap: 12px; align-items: center; font-size: 14pt; font-weight: bold; }
  h1 { font-size: 15pt; margin: 0; text-align: right; }
  .ref { text-align: right; font-size: 9pt; }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; padding: 3px 5px; border-bottom: 1px solid #000; vertical-align: top; }
  th { font-size: 8pt; text-transform: uppercase; letter-spacing: .03em; }
  tr.total th { border-top: 2px solid #000; text-transform: none; font-size: 9pt; }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; }
  .num { text-align: right; white-space: nowrap; }
  .small, .muted { color: #000; font-size: 8.5pt; }
  footer { margin-top: 18px; font-size: 8pt; }
  @media screen { body { max-width: <?= $wide ? '11in' : '8.5in' ?>; margin: 0 auto; box-shadow: 0 0 0 1px #ccc; } }
</style>
</head>
<body>
<header>
  <div class="brand"><img src="/assets/app-icon.svg" alt=""><?= e($brand) ?></div>
  <div><h1><?= te($def['title']) ?></h1><div class="ref"><?= e(implode(' · ', $scope)) ?></div></div>
</header>
<?php require __DIR__ . '/_report-table.php'; ?>
<footer><?= te('Prepared from the Crewvia records on :d by :name. Confidential: for the desks that keep these records.', ['d' => d(date('Y-m-d')), 'name' => (string) (user()['name'] ?? '')]) ?></footer>
</body>
</html>
