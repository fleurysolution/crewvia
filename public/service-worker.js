// P5-M06a: the phone foundation.
//
// What is kept on the device: the offline page and the static files it
// needs (stylesheet and icons). Nothing else. Signed-in pages, employee
// records, documents and pay are never cached; every page still comes from
// the server, and only when the server cannot be reached is the offline
// page shown instead of the browser's own error.
//
// Offline attendance (P5-M06b) and push (P5-M06c) are separate steps.

const SHELL = 'crewvia-shell-v1';
const OFFLINE = '/offline.html';
const PRECACHE = [OFFLINE, '/assets/fleury-brand.css?v=20261008', '/assets/app-icon-192.png', '/assets/app-icon.svg'];

self.addEventListener('install', event => {
  event.waitUntil(caches.open(SHELL).then(cache => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
  event.waitUntil(caches.keys()
    .then(keys => Promise.all(keys.filter(key => key !== SHELL).map(key => caches.delete(key))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', event => {
  const request = event.request;
  // Only a page being opened, and only a read: a form sent while offline
  // fails visibly rather than being kept and replayed.
  if (request.method !== 'GET' || request.mode !== 'navigate') {
    return;
  }
  event.respondWith(fetch(request).catch(() => caches.match(OFFLINE)));
});
