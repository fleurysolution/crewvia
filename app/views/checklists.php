<?php // P5-M86: the recruiter's checklist templates. ?>
<div class="page-heading"><div><span class="eyebrow"><?= te('RECRUITING') ?></span><h1><?= te('Checklists') ?></h1>
  <p class="sub"><?= te('The questions the recruiter asks, by type of job. Link them to a requisition.') ?></p></div>
  <div class="flex gap-s"><a class="btn" href="#new-template"><?= te('+ New template') ?></a></div></div>

<div class="card tight"><div class="card-bar"><h2 class="m0"><?= tn(count($templates), ':n template', ':n templates') ?></h2></div>
  <?php if (! $templates): ?><div class="empty"><?= te('No template yet.') ?></div><?php else: ?>
  <table><thead><tr><th><?= te('Template') ?></th><th class="num"><?= te('Questions') ?></th><th class="num"><?= te('Requisitions') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($templates as $tp): ?><tr data-template="<?= (int) $tp['id'] ?>"><td><strong><?= e($tp['name']) ?></strong></td><td class="num"><?= (int) $tp['items'] ?></td><td class="num"><?= (int) $tp['used'] ?></td>
    <td class="right nowrap"><a class="btn ghost sm" href="/checklists?id=<?= (int) $tp['id'] ?>#edit-template"><?= te('Edit') ?></a>
      <form method="post" class="inline" onsubmit="return confirm(this.dataset.ask)" data-ask="<?= te('Remove this template? Requisitions stop using it.') ?>"><?= csrf_field() ?><input type="hidden" name="do" value="retire"><input type="hidden" name="template_id" value="<?= (int) $tp['id'] ?>"><button class="btn ghost sm"><?= te('Remove') ?></button></form></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</div>

<?php $tpl = static function (?array $t, array $items, string $id) { ?>
  <form method="post" action="/checklists"><?= csrf_field() ?><input type="hidden" name="do" value="save"><?php if ($t): ?><input type="hidden" name="template_id" value="<?= (int) $t['id'] ?>"><?php endif; ?>
    <label for="<?= $id ?>-name"><?= te('Name') ?></label><input id="<?= $id ?>-name" name="name" required maxlength="120" value="<?= e((string) ($t['name'] ?? '')) ?>" placeholder="<?= te('e.g. Forklift driver, CDL driver') ?>">
    <label for="<?= $id ?>-q"><?= te('Questions, one per line') ?></label>
    <textarea id="<?= $id ?>-q" name="questions" rows="8" required placeholder="<?= te('e.g. Has a current forklift certification') ?>"><?= e(implode("\n", array_column($items, 'question'))) ?></textarea>
    <button class="btn mt-s"><?= te('Save the template') ?></button> <button type="button" class="btn ghost mt-s" data-fold-close><?= te('Cancel') ?></button>
  </form>
<?php }; ?>
<?php if ($editing): ?>
<div class="card" id="edit-template"><h2><?= te('Edit :name', ['name' => $editing['name']]) ?></h2><?php $tpl($editing, $editingItems, 'et'); ?></div>
<?php endif; ?>
<details class="card fold" id="new-template" data-fold-forget<?= $templates ? '' : ' open' ?>><summary><h2><?= te('New template') ?></h2></summary><?php $tpl(null, [], 'nt'); ?></details>
<script src="/assets/fold.js"></script>
