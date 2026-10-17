// P5-M71: the site address finds the site - its position for the clock-in, its city, state and ZIP.
// <div data-geo data-lat="#id" data-lng="#id" data-city="#id" data-state="#id" data-zip="#id">
//   <input data-geo-address> <button type="button" data-geo-find> <span data-geo-status data-found="..." data-searching="...">
(function () {
  document.querySelectorAll('[data-geo]').forEach(function (box) {
    var input = box.querySelector('[data-geo-address]');
    var button = box.querySelector('[data-geo-find]');
    var status = box.querySelector('[data-geo-status]');
    if (!input) return;
    var last = '';
    var pick = function (k) { var sel = box.getAttribute('data-' + k); return sel ? document.querySelector(sel) : null; };
    var find = function (force) {
      var parts = [input.value];
      ['city', 'state', 'zip'].forEach(function (k) { var el = pick(k); if (el && el.value.trim()) parts.push(el.value.trim()); });
      var q = parts.join(', ').trim();
      if (q.length < 5 || (!force && q === last)) return;
      last = q;
      if (status) { status.textContent = status.getAttribute('data-searching'); status.className = 'small muted'; }
      fetch('/geocode?address=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (g) {
          if (!g.found) { if (status) { status.textContent = g.message || ''; status.className = 'small err'; } return; }
          var set = function (k, v) { var el = pick(k); if (el && v !== undefined && v !== '') el.value = v; };
          set('lat', g.lat); set('lng', g.lng); set('city', g.city); set('state', g.state); set('zip', g.zip);
          if (g.address && g.address.length >= input.value.trim().length - 4) input.value = g.address;
          if (status) { status.textContent = status.getAttribute('data-found').replace(':address', g.matched || '').replace(':lat', g.lat).replace(':lng', g.lng); status.className = 'small ok'; }
        })
        .catch(function () { if (status) { status.textContent = ''; } });
    };
    input.addEventListener('change', function () { find(false); });
    if (button) button.addEventListener('click', function () { find(true); });
  });
})();
