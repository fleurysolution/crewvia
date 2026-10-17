// P5-M73: folded sections - opened by a link to them (or to something inside), and remembered as left.
(function () {
  var key = 'fold:' + location.pathname;
  var saved = {};
  try { saved = JSON.parse(localStorage.getItem(key) || '{}'); } catch (e) { saved = {}; }
  var folds = document.querySelectorAll('details.fold[id]');
  folds.forEach(function (d) { if (d.hasAttribute('data-fold-forget')) return; if (saved[d.id] === true) d.open = true; if (saved[d.id] === false) d.open = false; });
  var openFor = function () {
    if (!location.hash) return;
    var target = document.getElementById(location.hash.slice(1));
    var d = target && (target.matches('details.fold') ? target : target.closest('details.fold'));
    if (d) { d.open = true; (target || d).scrollIntoView({ block: 'start' }); }
  };
  openFor();
  window.addEventListener('hashchange', openFor);
  folds.forEach(function (d) {
    if (d.hasAttribute('data-fold-forget')) return;
    d.addEventListener('toggle', function () {
      saved[d.id] = d.open;
      try { localStorage.setItem(key, JSON.stringify(saved)); } catch (e) {}
    });
  });
})();
