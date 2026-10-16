// P5-M64: the forms filled in the portal.
//  - A secret (a Social Security number, an account or document number) is masked as it is typed and
//    typed twice; the second entry says at once whether the two match, and the form is not sent until
//    they do. "Show" reveals it while the person checks what they typed.
//  - Parts of a form that apply only to some answers (data-when="name:value,value", data-unless) are
//    shown with them; hidden, their fields are disabled, so they are neither required nor sent.
(function () {
  // Masked by style, not as password fields: a password field makes the browser offer the person's saved
  // sign-in (their email in the field before it, their password in it). Without the style, a password field.
  var masksByStyle = window.CSS && CSS.supports('-webkit-text-security', 'disc');
  if (!masksByStyle) {
    document.querySelectorAll('input.secret').forEach(function (i) { i.type = 'password'; i.autocomplete = 'new-password'; });
  }
  var norm = function (v) { return v.replace(/[\s-]/g, '').toUpperCase(); };
  document.querySelectorAll('[data-secret-pair]').forEach(function (second) {
    var first = document.getElementById(second.dataset.secretPair);
    var out = document.getElementById(second.id + '-match');
    var check = function () {
      if (!second.value) { out.textContent = ''; second.setCustomValidity(''); return; }
      var ok = norm(first.value) === norm(second.value);
      out.textContent = ok ? out.dataset.ok : out.dataset.no;
      out.className = 'small ' + (ok ? 't-green' : 't-red');
      second.setCustomValidity(ok ? '' : out.dataset.no);
    };
    first.addEventListener('input', check);
    second.addEventListener('input', check);
  });
  document.querySelectorAll('[data-reveal]').forEach(function (b) {
    b.addEventListener('click', function () {
      var i = document.getElementById(b.dataset.reveal);
      var show = masksByStyle ? !i.classList.contains('shown') : i.type === 'password';
      if (masksByStyle) { i.classList.toggle('shown', show); } else { i.type = show ? 'text' : 'password'; }
      b.textContent = show ? b.dataset.hide : b.dataset.show;
      b.setAttribute('aria-pressed', show ? 'true' : 'false');
    });
  });
  var valueOf = function (form, name) {
    var v = '';
    form.querySelectorAll('[name="' + name + '"]').forEach(function (e) {
      if (e.type === 'radio' || e.type === 'checkbox') { if (e.checked) { v = e.value; } } else { v = e.value; }
    });
    return v;
  };
  var refresh = function (form) {
    form.querySelectorAll('[data-when],[data-unless]').forEach(function (box) {
      var spec = (box.dataset.when || box.dataset.unless).split(':');
      var hit = spec[1].split(',').indexOf(valueOf(form, spec[0])) !== -1;
      var outer = box.parentElement.closest('[data-when],[data-unless]');
      var show = (box.dataset.when ? hit : !hit) && !(outer && outer.hidden);
      box.hidden = !show;
      box.querySelectorAll('input,select,textarea').forEach(function (i) { i.disabled = !show; });
    });
    var total = form.querySelector('[data-step3-total]');
    if (total) {
      var n = function (k) { var e = form.querySelector('[name="' + k + '"]'); return e && !e.disabled ? (parseFloat(String(e.value).replace(/[,$\s]/g, '')) || 0) : 0; };
      var sum = n('children') * Number(total.dataset.child) + n('other_dependents') * Number(total.dataset.other) + n('other_credits');
      total.textContent = '$' + sum.toLocaleString('en-US', { maximumFractionDigits: 2 });
    }
  };
  document.querySelectorAll('form[data-conditional]').forEach(function (f) {
    f.addEventListener('change', function () { refresh(f); });
    f.addEventListener('input', function () { refresh(f); });
    refresh(f);
  });
})();
