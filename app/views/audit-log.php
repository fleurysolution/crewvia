<div class="page-head"><div><h1><?= te('Audit log') ?></h1>
<p class="sub"><?= te('Every action recorded, with who did it, on what, and from which address. Nobody can change or delete an entry, here or in the database.') ?></p></div></div>
<form method="get" class="card row" id="audit-filters">
  <label for="audit-user" class="sr-only"><?= te('Person') ?></label><select id="audit-user" name="user"><option value="0"><?= te('Everybody') ?></option><?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $filters['user'] === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['name'] . ' · ' . $p['role']) ?></option><?php endforeach; ?></select>
  <label for="audit-action" class="sr-only"><?= te('Action contains') ?></label><input id="audit-action" name="action" value="<?= e($filters['action']) ?>" placeholder="<?= te('Action contains') ?>" maxlength="60">
  <label for="audit-entity" class="sr-only"><?= te('Record') ?></label><select id="audit-entity" name="entity"><option value=""><?= te('Any record') ?></option><?php foreach ($entities as $en): ?><option value="<?= e($en) ?>"<?= $filters['entity'] === $en ? ' selected' : '' ?>><?= e($en) ?></option><?php endforeach; ?></select>
  <label for="audit-id" class="sr-only"><?= te('Number') ?></label><input id="audit-id" name="id" type="number" min="0" value="<?= $filters['id'] ?: '' ?>" placeholder="#" class="fw-xs">
  <label for="audit-from"><?= te('From') ?></label><input id="audit-from" type="date" name="from" value="<?= e($filters['from']) ?>">
  <label for="audit-to"><?= te('To') ?></label><input id="audit-to" type="date" name="to" value="<?= e($filters['to']) ?>">
  <button class="btn ghost"><?= te('Show') ?></button>
</form>
<div class="card scroll" id="audit-entries" data-count="<?= count($entries) ?>">
  <?php if (! $entries): ?><p class="muted"><?= te('Nothing recorded for these filters.') ?></p><?php else: ?>
  <table><tr><th><?= te('When') ?></th><th><?= te('Who') ?></th><th><?= te('What') ?></th><th><?= te('Record') ?></th><th><?= te('Detail') ?></th><th><?= te('Address') ?></th></tr>
  <?php foreach ($entries as $a): ?><tr data-entry="<?= (int) $a['id'] ?>" data-action="<?= e($a['action']) ?>"><td class="small"><?= e((string) $a['created_at']) ?></td><td><?= e((string) ($a['by_name'] ?? '–')) ?></td>
    <td><?= e($a['action']) ?></td><td class="small mono"><?= e(trim($a['entity'] . ($a['entity_id'] ? ' #' . $a['entity_id'] : ''))) ?></td><td class="small wrap-any"><?= e((string) $a['detail']) ?></td><td class="small mono"><?= e((string) ($a['ip'] ?? '')) ?></td></tr><?php endforeach; ?></table>
  <?php endif; ?>
  <p class="row"><?php if ($page > 1): ?><a class="btn sm ghost" href="/audit-log?<?= e(http_build_query(array_filter($filters) + ['page' => $page - 1])) ?>"><?= te('Newer') ?></a><?php endif; ?>
    <?php if ($more): ?><a class="btn sm ghost" href="/audit-log?<?= e(http_build_query(array_filter($filters) + ['page' => $page + 1])) ?>"><?= te('Older') ?></a><?php endif; ?></p>
</div>
