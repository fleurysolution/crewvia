<h1><?= te('My pay statements') ?></h1>
<p class="sub"><?= te('How each week was worked out, before taxes. Taxes are withheld by the payroll provider, whose statement is the official one.') ?></p>
<div class="card">
  <?php if (! $slips): ?>
    <p class="muted"><?= te('No pay statement yet. One appears here once a week you worked has been approved for pay.') ?></p>
  <?php else: ?>
  <table>
    <tr><th><?= te('Week ending') ?></th><th class="num"><?= te('To be paid, before taxes') ?></th><th></th></tr>
    <?php foreach ($slips as $s): ?>
    <tr><td><?= e(d($s['run']['week_ending'])) ?></td><td class="num mono"><?= e(money($s['payable'])) ?></td>
      <td><a class="btn ghost sm" href="/payslip?run=<?= (int) $s['run']['id'] ?>"><?= te('Open') ?></a></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
