const CACHE = 'hellom-pos-v2';
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
          if (res.ok) caches.open(CACHE).then((c) => c.put(request, res.clone()));
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
          if (res.ok) caches.open(CACHE).then((c) => c.put(request, res.clone()));
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
