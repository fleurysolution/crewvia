// P5-M07: the text panel. The message is already filled in on the server;
// here it can be changed, opened in the phone's texting app (sms:) or
// copied for Google Voice. Either one logs it as texted.
(function () {
  'use strict';
  document.querySelectorAll('.text-panel').forEach(function (panel) {
    var sel = panel.querySelector('select[name=template]'), box = panel.querySelector('textarea[name=text]');
    var link = panel.querySelector('a.text-send'), copy = panel.querySelector('button.text-copy');
    var count = panel.querySelector('.text-count'), missing = panel.querySelector('.text-missing'), status = panel.querySelector('.text-status');
    if (!sel || !box || !link) return;

    function update() {
      link.href = 'sms:' + panel.dataset.phone + '?&body=' + encodeURIComponent(box.value);
      var n = box.value.length;
      count.textContent = n + ' / ' + (Math.max(1, Math.ceil(n / 160)) * 160);
    }
    function pick() {
      var o = sel.options[sel.selectedIndex];
      box.value = o ? o.dataset.text : '';
      missing.textContent = o && o.dataset.missing ? panel.dataset.missingLabel + ' ' + o.dataset.missing : '';
      update();
    }
    function log() {
      var f = new FormData();
      f.append('_csrf', panel.dataset.csrf);
      f.append('do', 'texted');
      f.append('candidate_id', panel.dataset.candidate);
      f.append('placement_id', panel.dataset.placement);
      f.append('template_id', sel.value || '0');
      f.append('text', box.value);
      return fetch('/contacts', {method: 'POST', body: f, credentials: 'same-origin', keepalive: true}).catch(function () {});
    }

    sel.addEventListener('change', pick);
    box.addEventListener('input', update);
    link.addEventListener('click', log);
    if (copy) {
      copy.addEventListener('click', function () {
        var done = function () { status.textContent = panel.dataset.copied; };
        (navigator.clipboard ? navigator.clipboard.writeText(box.value) : Promise.reject()).then(done, function () { box.select(); document.execCommand('copy'); done(); });
        log();
        window.open(panel.dataset.voice, '_blank', 'noopener');
      });
    }
    update();
  });
})();
