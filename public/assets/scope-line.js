// P5-M72: the scope line shows what the trade is billed against what it is paid, as it is typed.
(function () {
  var pay = document.getElementById('l-pay_rate');
  var bill = document.getElementById('l-bill_rate');
  var markup = document.getElementById('l-markup');
  var out = document.querySelector('[data-line-figures]');
  if (!pay || !bill || !markup) return;
  var radios = document.querySelectorAll('input[name="bill_by"]');
  var mode = function () { var r = document.querySelector('input[name="bill_by"]:checked'); return r ? r.value : 'rate'; };
  var num = function (el) { var v = parseFloat(el.value); return isFinite(v) ? v : null; };
  var update = function () {
    var p = num(pay), b = num(bill), m = num(markup);
    var byMarkup = mode() === 'markup';
    bill.readOnly = byMarkup;
    markup.readOnly = !byMarkup;
    if (byMarkup && p !== null && m !== null) { b = Math.round(p * (1 + m / 100) * 100) / 100; bill.value = b.toFixed(2); }
    if (!byMarkup && p && b !== null) { markup.value = (Math.round((b / p - 1) * 1000) / 10).toString(); }
    if (!out) return;
    if (p && b !== null) {
      out.textContent = out.getAttribute('data-text').replace(':bill', b.toFixed(2)).replace(':pay', p.toFixed(2))
        .replace(':margin', (b - p).toFixed(2)).replace(':pct', (Math.round((b / p - 1) * 1000) / 10).toString());
      out.className = 'small m0 ' + (b < p ? 'err' : 'ok');
    } else { out.textContent = ''; }
  };
  [pay, bill, markup].forEach(function (el) { el.addEventListener('input', update); });
  Array.prototype.forEach.call(radios, function (r) { r.addEventListener('change', update); });
  update();
})();
