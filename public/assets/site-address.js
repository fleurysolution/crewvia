// P5-M71: the site address finds the site - its position for the clock-in, its city, state and ZIP.
// P5-M73: what was found is shown first and used only once confirmed (RSS: "some addresses are different,
// you need to confirm"), so a near match never moves the geofence by itself.
// <div data-geo data-lat="#id" data-lng="#id" data-city="#id" data-state="#id" data-zip="#id">
//   <input data-geo-address> <button type="button" data-geo-find>
//   <span data-geo-status data-found="..." data-searching="..." data-use="..." data-other="...">
(function () {
  document.querySelectorAll('[data-geo]').forEach(function (box) {
    var input = box.querySelector('[data-geo-address]');
    var button = box.querySelector('[data-geo-find]');
    var status = box.querySelector('[data-geo-status]');
    if (!input || !status) return;
    var last = '';
    var pick = function (k) { var sel = box.getAttribute('data-' + k); return sel ? document.querySelector(sel) : null; };
    var say = function (text, cls) { status.textContent = text; status.className = 'hint ' + (cls || ''); };
    var use = function (g) {
      var set = function (k, v) { var el = pick(k); if (el && v !== undefined && v !== '') el.value = v; };
      set('lat', g.lat); set('lng', g.lng); set('city', g.city); set('state', g.state); set('zip', g.zip);
      if (g.address) input.value = g.address;
      say(status.getAttribute('data-found').replace(':address', g.matched || '').replace(':lat', g.lat).replace(':lng', g.lng), 'ok');
    };
    var find = function (force) {
      var parts = [input.value];
      ['city', 'state', 'zip'].forEach(function (k) { var el = pick(k); if (el && el.value.trim()) parts.push(el.value.trim()); });
      var q = parts.join(', ').trim();
      if (q.length < 5 || (!force && q === last)) return;
      last = q;
      say(status.getAttribute('data-searching'), 'muted');
      fetch('/geocode?address=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (g) {
          if (!g.found) { say(g.message || '', 'err'); return; }
          status.textContent = '';
          status.className = 'hint';
          var strong = document.createElement('strong');
          strong.textContent = g.matched + ' (' + g.lat + ', ' + g.lng + ')';
          var yes = document.createElement('button');
          yes.type = 'button'; yes.className = 'btn sm'; yes.textContent = status.getAttribute('data-use');
          yes.setAttribute('data-geo-use', '');
          yes.addEventListener('click', function () { use(g); });
          var no = document.createElement('button');
          no.type = 'button'; no.className = 'btn ghost sm'; no.textContent = status.getAttribute('data-other');
          no.addEventListener('click', function () { say(status.getAttribute('data-hint'), 'muted'); input.focus(); });
          status.append(strong, document.createElement('br'), yes, ' ', no);
        })
        .catch(function () { say('', ''); });
    };
    input.addEventListener('change', function () { find(false); });
    if (button) button.addEventListener('click', function () { find(true); });
  });
})();
