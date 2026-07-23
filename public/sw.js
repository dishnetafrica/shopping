/* CloudBSS service worker — Web Push + a weak-signal-safe cache.
 *
 * Caching goals: make repeat opens fast and keep the app usable when the signal drops (the field team's
 * reality), WITHOUT ever serving stale money data or delaying a deploy.
 *   • Shell (the /v3 document): NETWORK-FIRST with a short timeout. Good signal → always the fresh deploy.
 *     Weak/no signal → the cached shell is served in a few seconds instead of hanging.
 *   • Static assets (icons, fonts, css, js): CACHE-FIRST, revalidated in the background.
 *   • /papi, /api, /ops (all data + every money call): NEVER cached — always straight to the network.
 *   • Only GET is ever touched. POSTs (payments!) are never intercepted.
 */
const VERSION = 'cbss-cache-v1';                 // bump this string on a shell change to purge old caches
const SHELL_CACHE = 'cbss-shell-' + VERSION;
const ASSET_CACHE = 'cbss-assets-' + VERSION;
const SHELL_URL = '/v3';
const NET_TIMEOUT = 4000;                         // ms before the shell falls back to cache on a bad line

self.addEventListener('install', function (e) {
  self.skipWaiting();                             // a new SW takes over promptly (deploys aren't stuck behind an old one)
});

self.addEventListener('activate', function (e) {
  e.waitUntil((async function () {
    const keys = await caches.keys();
    await Promise.all(keys.map(function (k) {
      return (k.indexOf('cbss-') === 0 && k.indexOf(VERSION) === -1) ? caches.delete(k) : null;
    }));
    await self.clients.claim();
  })());
});

function isApi(url) {
  return url.pathname.indexOf('/papi') === 0 || url.pathname.indexOf('/api') === 0 || url.pathname.indexOf('/ops') === 0;
}
function isShell(req, url) {
  return req.mode === 'navigate' || url.pathname === '/v3' || url.pathname.indexOf('/v3') === 0;
}
function isAsset(url) {
  return /\.(?:png|jpe?g|svg|webp|gif|ico|woff2?|ttf|otf|css|js)$/i.test(url.pathname)
    || url.pathname === '/manifest.json' || url.pathname.indexOf('/icon') === 0;
}

function fetchTimeout(req, ms) {
  return new Promise(function (resolve, reject) {
    const t = setTimeout(function () { reject(new Error('timeout')); }, ms);
    fetch(req).then(function (r) { clearTimeout(t); resolve(r); }, function (err) { clearTimeout(t); reject(err); });
  });
}

async function networkFirst(req) {
  const cache = await caches.open(SHELL_CACHE);
  try {
    const fresh = await fetchTimeout(req, NET_TIMEOUT);
    if (fresh && fresh.ok) { try { await cache.put(SHELL_URL, fresh.clone()); } catch (e) {} }
    return fresh;
  } catch (e) {
    const cached = (await cache.match(req)) || (await cache.match(SHELL_URL));
    if (cached) return cached;
    throw e;
  }
}

async function cacheFirst(req) {
  const cache = await caches.open(ASSET_CACHE);
  const cached = await cache.match(req);
  if (cached) {
    fetch(req).then(function (r) { if (r && r.ok) { try { cache.put(req, r.clone()); } catch (e) {} } }).catch(function () {});
    return cached;
  }
  const fresh = await fetch(req);
  if (fresh && fresh.ok) { try { await cache.put(req, fresh.clone()); } catch (e) {} }
  return fresh;
}

self.addEventListener('fetch', function (e) {
  const req = e.request;
  if (req.method !== 'GET') return;                      // never intercept POST/PUT (payments, saves)
  let url;
  try { url = new URL(req.url); } catch (err) { return; }
  if (url.origin !== self.location.origin) return;        // only same-origin
  if (isApi(url)) return;                                 // data + money: always live, never cached
  if (isShell(req, url)) { e.respondWith(networkFirst(req)); return; }
  if (isAsset(url)) { e.respondWith(cacheFirst(req)); return; }
  // everything else: default network behaviour
});

/* ===== Web Push (unchanged) ===== */
self.addEventListener('push', function (e) {
  var d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) {}
  e.waitUntil(self.registration.showNotification(d.title || 'CloudBSS', {
    body: d.body || 'New activity',
    icon: '/icon-192.png',
    badge: '/icon-192.png',
    tag: 'cloudbss',
    data: { url: d.url || '/v3#/chats' }
  }));
});
self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var url = (e.notification.data && e.notification.data.url) || '/v3#/chats';
  e.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      if (list[i].url.indexOf('/v3') >= 0 && 'focus' in list[i]) { list[i].navigate(url); return list[i].focus(); }
    }
    return clients.openWindow(url);
  }));
});
