// P5-M06a and P5-M06b: the phone foundation and offline attendance.
//
// What is kept on the device: the offline page, the offline attendance
// screen and the static files they need (stylesheet, icons, two scripts).
// Nothing else. Signed-in pages, employee records, documents and pay are
// never cached; every page still comes from the server, and only when the
// server cannot be reached is a kept static page shown instead of the
// browser's own error. What a worker or supervisor types offline lives in
// IndexedDB (see /assets/offline-sync.js), not here.
//
// Push (P5-M06c) is a separate step.

const SHELL = 'crewvia-shell-v3';
const OFFLINE = '/offline.html';
const PRECACHE = [OFFLINE, '/offline-entry.html', '/assets/offline-sync.js?v=1', '/assets/offline-entry.js?v=1',
                  '/assets/fleury-brand.css?v=20261008', '/assets/app-icon-192.png', '/assets/app-icon.svg'];

importScripts('/assets/offline-sync.js?v=1');

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
  // Only reads: a form sent while offline fails visibly rather than being
  // kept and replayed.
  if (request.method !== 'GET') {
    return;
  }
  // A file this worker keeps (the offline screens' stylesheet, icons and
  // scripts) comes from what was kept. Every other file is left alone.
  if (request.mode !== 'navigate') {
    const url = new URL(request.url);
    if (url.origin === self.location.origin && PRECACHE.includes(url.pathname + url.search)) {
      event.respondWith(caches.match(request).then(kept => kept || fetch(request)));
    }
    return;
  }
  // Without a connection: the kept static page if this is one, else the offline page.
  event.respondWith(fetch(request).catch(() => caches.match(request, {ignoreSearch: true}).then(kept => kept || caches.match(OFFLINE))));
});

// Android: what was typed offline goes when the connection comes back, even
// with Crewvia closed. Elsewhere it goes when the screen is next opened.
self.addEventListener('sync', event => {
  if (event.tag === 'crewvia-attendance') {
    event.waitUntil(self.CrewviaSync.flush());
  }
});
