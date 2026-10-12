<?php
/**
 * The text panel (P5-M07, P5-M08), for one person and, from a placement, one
 * assignment. Needs $textPanel = msg_panel(...). The message is filled in
 * on the server; the page only lets it be edited, opened on the phone,
 * copied for Google Voice, or, once Twilio is set up, sent from Crewvia.
 * See app/messaging.php and app/twilio.php.
 */
require_once __DIR__ . '/../twilio.php';
$tp = $textPanel;
$tpPref = $tp['preferences'];
$tpTw = tw_summary();
$tpTwOn = $tpTw['configured'] && $tpTw['mode'] !== 'disabled';
$tpSandbox = $tpTw['mode'] === 'sandbox';
?>
<div class="card text-panel" id="text-panel" data-phone="<?= e((string) $tp['phone']) ?>" data-candidate="<?= (int) $tp['candidate']['id'] ?>" data-placement="<?= (int) $tp['placement_id'] ?>"
     data-csrf="<?= e(csrf_token()) ?>" data-missing-label="<?= te('Not on file yet, shown in brackets:') ?>" data-copied="<?= te('Copied. Paste it into Google Voice.') ?>" data-voice="<?= e(MSG_GOOGLE_VOICE) ?>"
     data-twilio="<?= $tpTwOn ? e($tpTw['mode']) : 'off' ?>">
  <h3><?= te('Text') ?></h3>
  <?php if ($tpPref['no_texts_since'] !== null): ?>
    <p id="no-texts"><span class="tag red"><?= te('Asked not to be texted') ?></span> <span class="small"><?= e(d(substr((string) $tpPref['no_texts_since'], 0, 10))) ?><?= $tpPref['reason'] ? ' · ' . e($tpPref['reason']) : '' ?></span></p>
  <?php elseif (! $tp['phone']): ?>
    <p class="muted" id="no-phone"><?= te('No telephone number on file') ?></p>
  <?php elseif (! $tp['messages']): ?>
    <p class="muted"><?= te('No text message is written yet. Recruiters write them under Text messages.') ?></p>
  <?php else: ?>
  <form method="post" action="/contacts" id="send-form"><?= csrf_field() ?><input type="hidden" name="do" value="send_sms"><input type="hidden" name="candidate_id" value="<?= (int) $tp['candidate']['id'] ?>"><input type="hidden" name="placement_id" value="<?= (int) $tp['placement_id'] ?>">
    <label><?= te('Message') ?><select name="template">
      <?php foreach ($tp['messages'] as $i => $m): ?><option value="<?= (int) $m['id'] ?>" data-text="<?= e($m['text']) ?>" data-missing="<?= e(implode(', ', $m['missing'])) ?>"<?= $i === 0 ? ' selected' : '' ?>><?= e($m['name'] . ' · ' . strtoupper($m['language'])) ?></option><?php endforeach; ?>
    </select></label>
    <label><?= te('Text to send (you can change it)') ?><textarea name="text" rows="4" maxlength="1000"><?= e($tp['messages'][0]['text']) ?></textarea></label>
    <p class="small muted"><span class="text-count"></span> <span class="text-missing" style="color:#8a5600"><?= $tp['messages'][0]['missing'] ? te('Not on file yet, shown in brackets:') . ' ' . e(implode(', ', $tp['messages'][0]['missing'])) : '' ?></span></p>
    <div class="row">
      <a class="btn text-send" href="<?= e(msg_sms_link((string) $tp['phone'], $tp['messages'][0]['text'])) ?>"><?= te('Text :number', ['number' => e((string) $tp['candidate']['phone'])]) ?></a>
      <button type="button" class="btn ghost text-copy"><?= te('Copy for Google Voice') ?></button>
      <?php if ($tpTwOn): ?><button class="btn ghost" id="send-twilio"><?= $tpSandbox ? te('Send from Crewvia (sandbox: not sent)') : te('Send from Crewvia') ?></button><?php endif; ?>
    </div>
  </form>
    <p class="small muted"><?= te('Text opens the texting app of this phone. On an iPhone that is Messages, which sends from the phone\'s own number. To send from the agency\'s Google Voice number, copy the message and paste it in Google Voice. Either way it is logged here as texted.') ?></p>
    <p class="small text-status" role="status"></p>
  <?php endif; ?>
  <?php if ($tpTwOn && $tp['phone'] && $tpPref['no_calls_since'] === null): ?>
  <form method="post" action="/contacts" id="call-form"><?= csrf_field() ?><input type="hidden" name="do" value="call_twilio"><input type="hidden" name="candidate_id" value="<?= (int) $tp['candidate']['id'] ?>"><input type="hidden" name="placement_id" value="<?= (int) $tp['placement_id'] ?>">
    <button class="btn ghost sm"><?= $tpSandbox ? te('Call through Crewvia (sandbox: not dialled)') : te('Call through Crewvia') ?></button> <span class="small muted"><?= te('Twilio rings your phone first, then connects them, showing the agency\'s number.') ?></span>
  </form>
  <?php endif; ?>
</div>
<script src="/assets/messaging.js?v=1"></script>
