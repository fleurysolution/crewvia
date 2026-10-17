<?php
/**
 * P5-M97: Send home - the end of an assignment, in one step. Expects $sendHome = ['placement' => id, 'action' => url,
 * 'back' => url, 'worked' => bool, 'name' => string].
 */
require_once __DIR__ . '/../send-home.php';
?>
<details class="card fold" id="send-home">
  <summary><span><span class="eyebrow"><?= te('END OF ASSIGNMENT') ?></span><h2><?= te('Send home') ?></h2></span><span class="fold-note"><?= te('Ends the assignment, the hotel and what is open; asks for the flight home and the ADP termination') ?></span></summary>
  <form method="post" action="<?= e($sendHome['action']) ?>" class="mt-s" data-send-home="<?= (int) $sendHome['placement'] ?>" onsubmit="return confirm(this.dataset.ask)" data-ask="<?= te('Send :name home? This ends the assignment.', ['name' => $sendHome['name']]) ?>"><?= csrf_field() ?>
    <input type="hidden" name="do" value="send_home"><input type="hidden" name="placement_id" value="<?= (int) $sendHome['placement'] ?>"><input type="hidden" name="back" value="<?= e($sendHome['back']) ?>">
    <div class="row">
      <div><label for="sh-reason"><?= te('Why') ?></label><select id="sh-reason" name="reason" required><option value=""><?= te('Choose') ?></option>
        <?php foreach (send_home_reasons() as $k => $l): if ($k === 'terminated' && ! can('admin') && (user()['role'] ?? '') !== 'hr') { continue; } ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label for="sh-day"><?= te('Last day') ?></label><input id="sh-day" type="date" name="last_day" value="<?= e(date('Y-m-d')) ?>" required></div>
      <div><label for="sh-rehire"><?= te('Hire again?') ?></label><select id="sh-rehire" name="rehire"><option value="eligible"><?= te('Fine to call') ?></option><option value="review"><?= te('Check before calling') ?></option><option value="ineligible"><?= te('No rehire') ?></option></select></div>
    </div>
    <div class="field mt-s"><label for="sh-detail"><?= te('What happened') ?></label><textarea id="sh-detail" name="detail" rows="2" maxlength="1000" placeholder="<?= te('Required unless the assignment simply ended.') ?>"></textarea></div>
    <?php if ($sendHome['worked']): ?><input type="hidden" name="return_flight" value="0"><label class="inline-check mt-s"><input class="w-auto" type="checkbox" name="return_flight" value="1" checked> <?= te('Book their flight home (the project coordinator gets the task)') ?></label><?php else: ?><input type="hidden" name="return_flight" value="0"><?php endif; ?>
    <p class="small muted mt-s m0"><?= te('Payroll gets a task to end the employment in ADP. Their supervisor is told.') ?></p>
    <button class="btn btn-danger mt-s" type="submit"><?= te('Send home') ?></button> <a class="btn ghost mt-s" href="#" data-fold-close><?= te('Cancel') ?></a>
  </form>
</details>
