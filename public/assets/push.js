// P5-M06c: turn push notifications on for this phone. Asks the browser for
// permission, subscribes with the agency's public key, keeps the words the
// service worker shows (in this person's language), and sends the
// subscription's address to Crewvia with the form.
(function () {
  'use strict';
  var form = document.getElementById('push-form'), button = document.getElementById('push-on'), status = document.getElementById('push-status');
  if (!form || !button) return;

  function key(b64) {
    var s = (b64 + '='.repeat((4 - b64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/'), raw = atob(s), out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }

  button.addEventListener('click', function () {
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) { status.textContent = form.dataset.unsupported; return; }
    button.disabled = true;
    Notification.requestPermission().then(function (p) {
      if (p !== 'granted') throw new Error('denied');
      return navigator.serviceWorker.ready;
    }).then(function (reg) {
      return reg.pushManager.getSubscription().then(function (old) {
        return old || reg.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: key(form.dataset.key)});
      });
    }).then(function (sub) {
      form.elements.endpoint.value = sub.endpoint;
      return window.CrewviaSync ? CrewviaSync.meta('push', {title: form.dataset.title, body: form.dataset.body}) : null;
    }).then(function () {
      form.submit();
    }).catch(function (e) {
      button.disabled = false;
      status.textContent = e && e.message === 'denied' ? form.dataset.denied : form.dataset.unsupported;
    });
  });
})();
