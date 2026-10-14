<?php $mtPurpose = msg_purposes(); $mtLang = msg_languages(); ?>
<div class="page-head"><div><h1><?= te('Text messages') ?></h1>
<p class="sub"><?= te('The wording texts are written from. Crewvia fills the placeholders from what it already holds, the text opens on your phone (or is copied for Google Voice), and it is logged in the person\'s contact history. Crewvia sends nothing itself.') ?></p></div></div>
<div class="grid g2">
  <div class="card scroll" id="templates" data-count="<?= count($templates) ?>"><h2><?= te('Messages') ?></h2>
    <table><?php foreach ($templates as $tpl): ?><tr data-template="<?= (int) $tpl['id'] ?>"<?= (int) $tpl['is_active'] ? '' : ' class="muted"' ?>>
      <td><a href="/message-templates?id=<?= (int) $tpl['id'] ?>"><?= e($tpl['name']) ?></a><div class="small wrap-any"><?= e($tpl['body']) ?></div></td>
      <td class="small"><?= e(strtoupper($tpl['language'])) ?></td><td class="small"><?= e($mtPurpose[$tpl['purpose']]) ?></td></tr><?php endforeach; ?></table>
  </div>
  <form method="post" class="card" id="template-form"><?= csrf_field() ?><input type="hidden" name="template_id" value="<?= (int) ($edit['id'] ?? 0) ?>">
    <h2><?= $edit ? e($edit['name']) : te('New message') ?></h2>
    <label><?= te('Name') ?><input name="name" required maxlength="120" value="<?= e((string) ($edit['name'] ?? '')) ?>"></label>
    <div class="grid g2">
      <label><?= te('Language') ?><select name="language"><?php foreach ($mtLang as $k => $l): ?><option value="<?= e($k) ?>"<?= ($edit['language'] ?? 'en') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label><?= te('Sent from') ?><select name="purpose"><?php foreach ($mtPurpose as $k => $l): ?><option value="<?= e($k) ?>"<?= ($edit['purpose'] ?? 'candidate') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    </div>
    <label><?= te('Message') ?><textarea name="body" rows="5" required maxlength="1000"><?= e((string) ($edit['body'] ?? '')) ?></textarea></label>
    <p class="small muted"><?= te('Placeholders: :list. Assignment details (project, start date, hotel, travel, pickup) only from a placement.', ['list' => '{' . implode('}, {', MSG_PLACEHOLDERS) . '}']) ?></p>
    <?php if ($edit): ?><label><input type="checkbox" name="is_active" value="1"<?= (int) $edit['is_active'] ? ' checked' : '' ?>> <?= te('In use') ?></label><?php endif; ?>
    <button class="btn"><?= $edit ? te('Save') : te('Create') ?></button>
  </form>
</div>
