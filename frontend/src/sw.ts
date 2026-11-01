/// <reference lib="webworker" />

import { clientsClaim } from 'workbox-core';
import { createHandlerBoundToURL, precacheAndRoute } from 'workbox-precaching';
import { NavigationRoute, registerRoute } from 'workbox-routing';

type PrecacheManifestEntry = { url: string; revision: string | null };

declare let self: ServiceWorkerGlobalScope & { __WB_MANIFEST: Array<PrecacheManifestEntry> };

precacheAndRoute(self.__WB_MANIFEST);
clientsClaim();

registerRoute(
  new NavigationRoute(createHandlerBoundToURL('/index.html'), {
    allowlist: [/^\/pos(?:\/|$)/],
  }),
);

self.addEventListener('activate', (event) => {
  event.waitUntil(caches.delete('hellom-pos-v6'));
});

self.addEventListener('message', (event) => {
  if (event.data?.type === 'SKIP_WAITING') event.waitUntil(self.skipWaiting());
});

self.addEventListener('push', (event) => {
  let data: { title?: string; body?: string; url?: string; tag?: string } = {};
  try {
    data = event.data?.json() ?? {};
  } catch {
    data = { body: event.data?.text() ?? '' };
  }

  const options: NotificationOptions & { renotify?: boolean } = {
    body: data.body || '',
    icon: '/assets/pwa-192.png',
    badge: '/assets/pwa-192.png',
    tag: data.tag || undefined,
    renotify: Boolean(data.tag),
    data: { url: data.url || '/' },
  };
  event.waitUntil(self.registration.showNotification(data.title || 'Hellom', options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL(event.notification.data?.url || '/', self.location.origin);
  const url = target.origin === self.location.origin ? target.href : `${self.location.origin}/`;

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      for (const client of windows) {
        if (client.url.startsWith(self.location.origin) && 'focus' in client) {
          return client.focus().then((focused) =>
            focused && 'navigate' in focused ? focused.navigate(url) : null,
          );
        }
      }
      return self.clients.openWindow ? self.clients.openWindow(url) : null;
    }),
  );
});
