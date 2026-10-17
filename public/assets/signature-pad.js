// P5-M65: the signature drawn with a finger, a pen or the mouse; sent as a PNG with the form.
(function () {
  var pad = document.getElementById('sg-pad');
  if (!pad) { return; }
  var ctx = pad.getContext('2d');
  var drawn = false, down = false, last = null;
  ctx.lineWidth = 2.6; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0d2137';
  var at = function (e) {
    var r = pad.getBoundingClientRect();
    return { x: (e.clientX - r.left) * pad.width / r.width, y: (e.clientY - r.top) * pad.height / r.height };
  };
  pad.addEventListener('pointerdown', function (e) { down = true; last = at(e); pad.setPointerCapture(e.pointerId); e.preventDefault(); });
  pad.addEventListener('pointermove', function (e) {
    if (!down) { return; }
    var p = at(e);
    ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
    last = p; drawn = true; e.preventDefault();
  });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (k) { pad.addEventListener(k, function () { down = false; }); });
  document.getElementById('sg-clear').addEventListener('click', function () { ctx.clearRect(0, 0, pad.width, pad.height); drawn = false; });
  document.getElementById('sign-form').addEventListener('submit', function (e) {
    if (!drawn) { e.preventDefault(); document.getElementById('sg-empty').hidden = false; return; }
    document.getElementById('sg-png').value = pad.toDataURL('image/png');
  });
})();
