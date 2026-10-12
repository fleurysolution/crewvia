// P5-M06b: the offline attendance screen. Everything shown comes from what
// the phone keeps (offline-sync.js) and is written with textContent, never
// as markup.
(function () {
  'use strict';

  var S = window.CrewviaSync;
  var L = {};
  var EN = {
    title: 'Attendance without signal', intro: 'Typed here, it is kept on this phone and sent when there is a connection.',
    hours: 'Hours', assignment: 'Assignment', date: 'Date', keep: 'Keep it to send', gate: 'Gate', present: 'Present', absent: 'Absent',
    waiting: 'Waiting to be sent', sent: 'Answered', send: 'Send now', nothing: 'Nothing waiting.', offline: 'No connection: kept on this phone.',
    crew_expired: 'The crew list is more than a day old and was removed from this phone. Open Crewvia with a connection to get it again.',
    untrusted: 'This phone is no longer trusted. Sign in to Crewvia and trust it again.', saved: 'Saved', replaced: 'Replaced', refused: 'Refused',
    open: 'Open Crewvia', kept: 'Kept. It goes when there is a connection.', bad_hours: 'Hours are 0 to 24.'
  };
  function w(key) { return L[key] || EN[key]; }
  function el(tag, attrs, text) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
    if (text !== undefined) n.textContent = text;
    return n;
  }
  function status(text) { document.getElementById('status').textContent = text || ''; }

  var names = {};

  function drawHours(b, screen) {
    if (!b.assignments || !b.assignments.length) return;
    var card = el('form', {'class': 'card', id: 'hours-form'});
    card.appendChild(el('h2', {}, w('hours')));
    card.appendChild(el('label', {'for': 'h-assignment'}, w('assignment')));
    var sel = el('select', {id: 'h-assignment', name: 'placement_id'});
    b.assignments.forEach(function (a) { names['p' + a.placement_id] = a.project; sel.appendChild(el('option', {value: a.placement_id}, a.project)); });
    card.appendChild(sel);
    card.appendChild(el('label', {'for': 'h-date'}, w('date')));
    var date = el('input', {id: 'h-date', type: 'date', name: 'work_date', max: S.today(), value: S.today(), required: 'required'});
    card.appendChild(date);
    card.appendChild(el('label', {'for': 'h-hours'}, w('hours')));
    var hours = el('input', {id: 'h-hours', type: 'number', name: 'hours', min: '0', max: '24', step: '0.25', required: 'required', inputmode: 'decimal'});
    card.appendChild(hours);
    card.appendChild(el('button', {type: 'submit'}, w('keep')));
    card.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var h = parseFloat(hours.value);
      if (!(h >= 0 && h <= 24)) { status(w('bad_hours')); return; }
      S.keep({kind: 'hours', placement_id: Number(sel.value), work_date: date.value, hours: h}).then(function () { hours.value = ''; status(w('kept')); return trySend(); });
    });
    screen.appendChild(card);
  }

  function drawGate(b, screen) {
    if (b.crew_expired) { screen.appendChild(el('p', {'class': 'card', id: 'crew-expired'}, w('crew_expired'))); return; }
    if (!b.crew || !b.crew.length) return;
    var card = el('div', {'class': 'card', id: 'gate'});
    card.appendChild(el('h2', {}, w('gate') + ' · ' + S.today()));
    b.crew.forEach(function (c) {
      names['p' + c.placement_id] = c.name;
      var row = el('div', {'class': 'row', 'data-placement': c.placement_id});
      var who = el('div', {}); who.appendChild(el('div', {}, c.name)); who.appendChild(el('div', {'class': 'small muted'}, c.project));
      row.appendChild(who);
      var btns = el('div', {});
      [[1, w('present'), ''], [0, w('absent'), 'ghost']].forEach(function (x) {
        var btn = el('button', {type: 'button', 'class': x[2], 'data-present': x[0]}, x[1]);
        btn.addEventListener('click', function () {
          S.keep({kind: 'gate', placement_id: c.placement_id, work_date: S.today(), present: x[0]}).then(function () { status(c.name + ': ' + x[1] + '. ' + w('kept')); return trySend(); });
        });
        btns.appendChild(btn);
      });
      row.appendChild(btns);
      card.appendChild(row);
    });
    screen.appendChild(card);
  }

  function drawQueue() {
    return S.queue().then(function (q) {
      var waiting = document.getElementById('waiting'), answered = document.getElementById('answered');
      waiting.textContent = ''; answered.textContent = '';
      var wq = q.filter(function (e) { return e.state === 'waiting'; }), aq = q.filter(function (e) { return e.state === 'answered'; });
      if (!wq.length) waiting.appendChild(el('p', {'class': 'muted'}, w('nothing')));
      wq.forEach(function (e) { waiting.appendChild(line(e)); });
      aq.sort(function (a, b) { return b.answered_at - a.answered_at; }).forEach(function (e) { answered.appendChild(line(e)); });
    });
  }
  function line(e) {
    var row = el('div', {'class': 'row', 'data-entry': e.id, 'data-state': e.state, 'data-outcome': e.outcome || ''});
    var what = (names['p' + e.placement_id] || '#' + e.placement_id) + ' · ' + e.work_date + ' · ' + (e.kind === 'hours' ? e.hours + ' h' : (e.present ? w('present') : w('absent')));
    var left = el('div', {}); left.appendChild(el('div', {}, what));
    if (e.message) left.appendChild(el('div', {'class': 'small muted'}, e.message));
    row.appendChild(left);
    if (e.outcome) row.appendChild(el('span', {'class': 'tag ' + e.outcome}, w(e.outcome)));
    return row;
  }

  function trySend() {
    if (!navigator.onLine) { status(w('offline')); return drawQueue(); }
    return S.flush().then(function (r) { if (r && r.untrusted) status(w('untrusted')); return drawQueue(); }, function () { status(w('offline')); return drawQueue(); });
  }

  function draw(b) {
    L = b.labels || L;
    document.getElementById('title').textContent = w('title');
    document.getElementById('intro').textContent = w('intro');
    document.getElementById('waiting-title').textContent = w('waiting');
    document.getElementById('answered-title').textContent = w('sent');
    document.getElementById('send').textContent = w('send');
    document.getElementById('open').textContent = w('open');
    var screen = document.getElementById('screen');
    screen.textContent = '';
    if (b.untrusted || (!b.assignments && !b.crew && !b.crew_expired)) { screen.appendChild(el('p', {'class': 'card', id: 'untrusted'}, w('untrusted'))); }
    drawHours(b, screen);
    drawGate(b, screen);
    return drawQueue();
  }

  document.getElementById('send').addEventListener('click', trySend);
  window.addEventListener('online', trySend);

  var online = navigator.onLine ? S.refresh().catch(function () { return null; }) : Promise.resolve(null);
  online.then(function () { return S.bootstrap(); }).then(draw).then(trySend);
})();
