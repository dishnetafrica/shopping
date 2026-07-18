/* CloudBSS service worker — Web Push only (no caching: the app stays live-served). */
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
