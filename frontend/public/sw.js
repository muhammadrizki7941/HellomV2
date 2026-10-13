const CACHE = 'hellom-pos-v6';
const SHELL = '/';
const STATIC_EXT = /\.(js|css|png|jpg|jpeg|webp|gif|svg|ico|woff2?|ttf|eot)(\?.*)?$/;

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE)
      .then((c) => c.add(SHELL))
      .catch(() => {})
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const { request } = e;
  const url = new URL(request.url);

  if (url.origin !== self.location.origin) return;
  if (request.method !== 'GET') return;

  // Never intercept API calls
  if (url.pathname.startsWith('/api/')) return;

  // Static assets — cache first, refresh in background
  if (STATIC_EXT.test(url.pathname)) {
    e.respondWith(
      caches.match(request).then((cached) => {
        const fromNetwork = fetch(request).then((res) => {
          // Clone synchronously, before the body is handed to the page — otherwise
          // res.clone() inside the async cache.put throws "body is already used".
          if (res.ok) {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(request, copy));
          }
          return res;
        });
        return cached ?? fromNetwork;
      })
    );
    return;
  }

  // SPA navigation — network first, fall back to app shell
  if (request.mode === 'navigate') {
    e.respondWith(
      fetch(request)
        .then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(request, copy));
          }
          return res;
        })
        .catch(async () => {
          const cached = await caches.match(request);
          if (cached) return cached;
          const shell = await caches.match(SHELL);
          return shell ?? new Response('Offline — buka kembali saat terhubung ke internet.', {
            status: 503,
            headers: { 'Content-Type': 'text/plain; charset=utf-8' },
          });
        })
    );
    return;
  }
});

// ─── Web push (notifications while the dashboard is closed) ───
// Payload from the server job SendWebPush: { title, body, url, tag }.
self.addEventListener('push', (e) => {
  let data = {};
  try {
    data = e.data ? e.data.json() : {};
  } catch {
    data = { body: e.data ? e.data.text() : '' };
  }
  const title = data.title || 'Hellom';
  e.waitUntil(
    self.registration.showNotification(title, {
      body: data.body || '',
      icon: '/assets/hellom.png',
      badge: '/assets/hellom.png',
      tag: data.tag || undefined,
      renotify: Boolean(data.tag),
      data: { url: data.url || '/' },
    })
  );
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const target = new URL((e.notification.data && e.notification.data.url) || '/', self.location.origin);
  // Only open our own pages.
  const url = target.origin === self.location.origin ? target.href : self.location.origin + '/';
  e.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      for (const client of windows) {
        if (client.url.startsWith(self.location.origin) && 'focus' in client) {
          return client.focus().then((focused) => (focused && 'navigate' in focused ? focused.navigate(url) : null));
        }
      }
      return self.clients.openWindow ? self.clients.openWindow(url) : null;
    })
  );
});
