// P5-M77: on a new project, a site already kept on the chosen client fills the site fields.
(function () {
  var client = document.getElementById('p-client');
  var pick = document.getElementById('p-saved-site');
  var row = document.getElementById('saved-site-row');
  if (!client || !pick || !row) return;
  var sites = {};
  try { sites = JSON.parse(pick.getAttribute('data-sites') || '{}'); } catch (e) { sites = {}; }
  var first = pick.options[0];
  var fill = function () {
    var list = sites[client.value] || [];
    pick.innerHTML = '';
    pick.appendChild(first);
    list.forEach(function (s, i) {
      var o = document.createElement('option');
      o.value = String(i);
      o.textContent = [s.name, s.address, [s.city, s.state].filter(Boolean).join(', ')].filter(Boolean).join(' - ');
      pick.appendChild(o);
    });
    row.hidden = list.length === 0;
  };
  var set = function (id, v) { var el = document.getElementById(id); if (el && v !== null && v !== undefined) el.value = v; };
  pick.addEventListener('change', function () {
    var s = (sites[client.value] || [])[parseInt(pick.value, 10)];
    if (!s) return;
    set('p-site', s.name || ''); set('p-address', s.address || ''); set('p-city', s.city || ''); set('p-state', s.state || ''); set('p-zip', s.zip || '');
    set('p-lat', s.lat || ''); set('p-lng', s.lng || ''); if (s.radius_m) set('p-radius', s.radius_m);
  });
  client.addEventListener('change', fill);
  fill();
  // Coming from a site of the client ("New project here"), that site is chosen.
  var wanted = new URLSearchParams(location.search).get('site');
  if (wanted) {
    (sites[client.value] || []).forEach(function (s, i) { if (String(s.id) === wanted) { pick.value = String(i); pick.dispatchEvent(new Event('change')); } });
  }
})();
