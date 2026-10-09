// Mobile foundation only: do not cache employee records, documents or authenticated HTML.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));
// All requests stay online. Offline writes and background push require a separate design.
