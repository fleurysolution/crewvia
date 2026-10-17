// P5-M67: a clock-in or clock-out form (data-geo) asks the phone where it is before it is sent; refused or
// unavailable, it is sent without (and flagged to the supervisor). Ten seconds at most.
(function () {
  document.querySelectorAll('form[data-geo]').forEach(function (form) {
    var sent = false;
    form.addEventListener('submit', function (e) {
      if (sent || !navigator.geolocation) { return; }
      e.preventDefault();
      var button = form.querySelector('button');
      if (button) { button.disabled = true; button.textContent = form.dataset.wait || button.textContent; }
      var go = function () { sent = true; form.submit(); };
      navigator.geolocation.getCurrentPosition(function (pos) {
        form.querySelector('[name=lat]').value = pos.coords.latitude;
        form.querySelector('[name=lng]').value = pos.coords.longitude;
        form.querySelector('[name=accuracy]').value = pos.coords.accuracy;
        go();
      }, go, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
    });
  });
  // The supervisor's screen: a new code every 30 seconds, and who scanned.
  var qr = document.getElementById('clock-qr');
  if (!qr || !window.QRCode) { return; }
  var code = new QRCode(qr, { text: qr.dataset.url, width: 260, height: 260, correctLevel: QRCode.CorrectLevel.M });
  var left = document.getElementById('clock-qr-left');
  var list = document.getElementById('clock-qr-scans');
  var refresh = function () {
    fetch(qr.dataset.feed, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.url !== qr.dataset.url) { qr.dataset.url = d.url; code.clear(); code.makeCode(d.url); }
      if (left) { left.dataset.until = String(Date.now() + d.expires_in * 1000); }
      if (list) {
        list.textContent = '';
        d.scans.forEach(function (s) {
          var li = document.createElement('li');
          li.textContent = s.text;
          if (s.flag) { li.className = 't-red'; }
          list.appendChild(li);
        });
      }
    }).catch(function () {});
  };
  setInterval(function () {
    var until = Number(left && left.dataset.until || 0);
    var s = Math.max(0, Math.ceil((until - Date.now()) / 1000));
    if (left) { left.textContent = s; }
    if (s === 0) { refresh(); left.dataset.until = String(Date.now() + 2000); }
  }, 1000);
  refresh();
})();
