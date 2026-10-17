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
// P5-M74: Cancel closes the form it is in (RSS: "an X, just in case I clicked it and I don't need it");
// Copy link puts a requisition's application link on the clipboard.
document.addEventListener('click', function (ev) {
  var close = ev.target.closest('[data-fold-close]');
  if (close) {
    var form = close.closest('form');
    var d = close.closest('details');
    // A whole form folded in its section is emptied; a section inside a larger form empties only its own fields.
    if (form && d && d.contains(form)) form.reset();
    else if (d) d.querySelectorAll('input:not([type=hidden]), textarea, select').forEach(function (i) { if (i.type === 'checkbox' || i.type === 'radio') i.checked = i.defaultChecked; else i.value = i.defaultValue; });
    if (d) { d.open = false; var s = d.querySelector('summary'); if (s) s.scrollIntoView({ block: 'nearest' }); }
    return;
  }
  var copy = ev.target.closest('[data-copy-link]');
  if (copy) {
    var url = copy.getAttribute('data-copy-link');
    var done = function () { var t = copy.textContent; copy.textContent = copy.getAttribute('data-copied') || t; setTimeout(function () { copy.textContent = t; }, 1800); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(url).then(done, function () { window.prompt('', url); }); }
    else { window.prompt('', url); }
  }
});
// P5-M74: a requisition's panel opens beside its button, above the table (which scrolls sideways); one at a time.
document.querySelectorAll('details.req-more').forEach(function (d) {
  d.addEventListener('toggle', function () {
    if (!d.open) return;
    document.querySelectorAll('details.req-more[open]').forEach(function (o) { if (o !== d) o.open = false; });
    var r = d.querySelector('summary').getBoundingClientRect(), pop = d.querySelector('.req-pop');
    pop.style.top = Math.min(r.bottom + 6, window.innerHeight - pop.offsetHeight - 8) + 'px';
    pop.style.left = Math.max(8, Math.min(r.right - pop.offsetWidth, window.innerWidth - pop.offsetWidth - 8)) + 'px';
  });
});
window.addEventListener('scroll', function () { document.querySelectorAll('details.req-more[open]').forEach(function (o) { o.open = false; }); }, { passive: true });
