// P5-M71: a discipline added beside the scope line is chosen at once, without losing what was typed on the line.
(function () {
  var form = document.getElementById('add-discipline');
  var select = document.getElementById('l-disc');
  if (!form || !select || !window.fetch) return;
  var status = document.querySelector('[data-add-discipline-status]');
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var data = new FormData(form);
    fetch(form.getAttribute('action'), { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (g) {
        if (status) { status.textContent = g.message || ''; status.className = 'small ' + (g.ok ? 'ok' : 'err'); }
        if (!g.ok) return;
        var opt = Array.prototype.find.call(select.options, function (o) { return o.value === g.slug; });
        if (!opt) { opt = document.createElement('option'); opt.value = g.slug; opt.textContent = g.label; select.appendChild(opt); }
        select.value = g.slug;
        var input = document.querySelector('[name="discipline_label"][form="add-discipline"]');
        if (input) input.value = '';
      })
      .catch(function () { form.submit(); });
  });
})();
