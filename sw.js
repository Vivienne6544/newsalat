/*
 * newsalat sw.js: service worker.
 *  - push:  shows the alert sent by core.php
 *  - click: opens the app or the story
 *  - offline: keeps the page and the last news list
 */

const CACHE = 'newsalat-v1';
const PAGES = ['./', 'index.html', 'manifest.json'];

/* ---------- install / update ---------- */
self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(PAGES)));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

/* ---------- offline: network first, saved copy as backup ---------- */
self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  const isPage = url.origin === location.origin && !url.pathname.endsWith('api.php');
  const isFeed = url.pathname.endsWith('api.php') && url.searchParams.get('action') === 'articles';
  if (!isPage && !isFeed) return;   // cron, subscribe etc. always go straight to the network

  e.respondWith(
    fetch(req)
      .then(res => {
        const copy = res.clone();
        caches.open(CACHE).then(c => c.put(req, copy));
        return res;
      })
      .catch(() => caches.match(req))
  );
});

/* ---------- push alert ---------- */
self.addEventListener('push', e => {
  let data = {title: 'newsalat', body: 'New story', url: './'};
  try { data = Object.assign(data, e.data.json()); } catch (err) {}

  e.waitUntil(
    self.registration.showNotification(data.title, {
      body: data.body,
      tag: 'newsalat',          // a new alert replaces the old one instead of stacking
      data: {url: data.url}
    })
  );
});

/* ---------- tap on the alert ---------- */
self.addEventListener('notificationclick', e => {
  e.notification.close();
  const target = new URL(e.notification.data.url || './', self.registration.scope).href;

  e.waitUntil(
    clients.matchAll({type: 'window', includeUncontrolled: true}).then(list => {
      for (const c of list) {
        if (c.url.startsWith(self.registration.scope) && 'focus' in c && target.startsWith(self.registration.scope)) {
          return c.focus();   // app already open: just bring it forward
        }
      }
      return clients.openWindow(target);
    })
  );
});