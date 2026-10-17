// P5-M72: on "What the client is billed", each amount shows only with the way of billing that uses it.
(function () {
  document.querySelectorAll('#billing-terms select[data-bt-mode]').forEach(function (select) {
    var row = select.closest('tr');
    var update = function () {
      row.querySelectorAll('[data-bt-when]').forEach(function (el) {
        var on = el.getAttribute('data-bt-when').split(' ').indexOf(select.value) !== -1;
        el.hidden = !on;
        el.querySelectorAll('input').forEach(function (i) { i.disabled = !on; });
      });
    };
    select.addEventListener('change', update);
    update();
  });
})();
