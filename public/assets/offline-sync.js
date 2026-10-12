// P5-M06b: offline attendance on a trusted phone.
//
// Kept on the phone (IndexedDB "crewvia-offline"):
//   meta/bootstrap  what /sync/bootstrap gave: the person's own live
//                   assignments, a supervisor's crew (removed after 24
//                   hours), the screen's words in their language
//   queue           what was typed and not yet answered, then the answer
//                   for 7 days
// Nothing about anyone else, no page, record, document or pay. Signing out
// on the phone clears all of it (Clear-Site-Data). A phone no longer
// trusted loses what it was given, and keeps only what its owner typed, to
// send once it is trusted again.
//
// Used by the offline screen and by the service worker (background sync
// on Android); it touches no page.

(function (global) {
  'use strict';

  var DB = 'crewvia-offline';
  var HEADERS = {'X-Crewvia-Sync': '1'};
  var CREW_MS = 24 * 3600 * 1000;
  var ANSWER_MS = 7 * 24 * 3600 * 1000;

  function req(r) {
    return new Promise(function (resolve, reject) { r.onsuccess = function () { resolve(r.result); }; r.onerror = function () { reject(r.error); }; });
  }
  function open() {
    var r = indexedDB.open(DB, 1);
    r.onupgradeneeded = function () {
      var db = r.result;
      if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta');
      if (!db.objectStoreNames.contains('queue')) db.createObjectStore('queue', {keyPath: 'id'});
    };
    return req(r);
  }
  function store(name, mode) {
    return open().then(function (db) { return db.transaction(name, mode).objectStore(name); });
  }
  function get(name, key) { return store(name, 'readonly').then(function (s) { return req(s.get(key)); }); }
  function all(name) { return store(name, 'readonly').then(function (s) { return req(s.getAll()); }); }
  function put(name, value, key) { return store(name, 'readwrite').then(function (s) { return req(key === undefined ? s.put(value) : s.put(value, key)); }); }
  function del(name, key) { return store(name, 'readwrite').then(function (s) { return req(s.delete(key)); }); }

  function pad(n) { return String(n).padStart(2, '0'); }
  function localDateTime(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
  }
  function today() { var d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function uuid() {
    if (global.crypto && crypto.randomUUID) return crypto.randomUUID();
    var b = crypto.getRandomValues(new Uint8Array(16)), h = Array.prototype.map.call(b, function (x) { return pad(x.toString(16)); }).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }

  /** No longer trusted: forget what the phone was given, keep what was typed. */
  function untrust() {
    return del('meta', 'bootstrap').then(function () { return put('meta', {untrusted: true}, 'state'); });
  }

  /** Ask the server what this phone may keep. Null when it is not trusted. */
  function refresh() {
    return fetch('/sync/bootstrap', {headers: HEADERS, credentials: 'same-origin', cache: 'no-store'}).then(function (r) {
      if (r.status === 401) return untrust().then(function () { return null; });
      if (!r.ok) throw new Error('bootstrap ' + r.status);
      return r.json().then(function (b) {
        b.fetched_at = Date.now();
        return put('meta', b, 'bootstrap').then(function () { return put('meta', {untrusted: false}, 'state'); }).then(function () { return b; });
      });
    });
  }

  /** What the phone holds now; a crew list older than a day is removed first. */
  function bootstrap() {
    return Promise.all([get('meta', 'bootstrap'), get('meta', 'state')]).then(function (got) {
      var b = got[0], state = got[1] || {};
      if (!b) return {untrusted: !!state.untrusted};
      if (b.crew && Date.now() - b.fetched_at > CREW_MS) {
        delete b.crew;
        b.crew_expired = true;
        return put('meta', b, 'bootstrap').then(function () { return b; });
      }
      return b;
    });
  }

  function register() {
    if (global.navigator && navigator.serviceWorker && global.SyncManager) {
      navigator.serviceWorker.ready.then(function (r) { return r.sync.register('crewvia-attendance'); }).catch(function () {});
    }
  }

  /** Keep one entry to send: {kind:'hours', placement_id, work_date, hours} or {kind:'gate', placement_id, work_date, present}. */
  function keep(entry) {
    entry.id = uuid();
    entry.captured_at = localDateTime(new Date());
    entry.state = 'waiting';
    return put('queue', entry).then(function () { register(); return entry; });
  }

  var flushing = null;
  function flush() {
    if (!flushing) {
      flushing = doFlush().then(function (r) { flushing = null; return r; }, function (e) { flushing = null; throw e; });
    }
    return flushing;
  }
  function doFlush() {
    return all('queue').then(function (queue) {
      var waiting = queue.filter(function (e) { return e.state === 'waiting'; });
      var old = queue.filter(function (e) { return e.state === 'answered' && Date.now() - e.answered_at > ANSWER_MS; });
      return Promise.all(old.map(function (e) { return del('queue', e.id); })).then(function () {
        if (!waiting.length) return {sent: 0};
        var body = JSON.stringify({entries: waiting.map(function (e) {
          return {id: e.id, kind: e.kind, placement_id: e.placement_id, work_date: e.work_date, hours: e.hours, present: e.present, captured_at: e.captured_at};
        })});
        return fetch('/sync/push', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-Crewvia-Sync': '1'}, body: body}).then(function (r) {
          if (r.status === 401) return untrust().then(function () { return {sent: 0, untrusted: true}; });
          if (!r.ok) throw new Error('push ' + r.status);
          return r.json().then(function (out) {
            var results = out.results || [];
            return Promise.all(results.map(function (x) {
              var e = waiting.filter(function (w) { return w.id === x.id; })[0];
              if (!e) return null;
              e.state = 'answered'; e.outcome = x.outcome; e.message = x.message; e.answered_at = Date.now();
              return put('queue', e);
            })).then(function () { return {sent: results.length}; });
          });
        });
      });
    });
  }

  global.CrewviaSync = {refresh: refresh, bootstrap: bootstrap, keep: keep, flush: flush, queue: function () { return all('queue'); }, today: today};
})(typeof self !== 'undefined' ? self : this);
