/**
 * Service Worker for Donor PWA
 */

const CACHE_NAME = 'donor-pwa-v1';

const STATIC_ASSETS = [
  '/donor/',
  '/donor/assets/donor.css',
  '/donor/assets/donor.js',
  '/assets/favicon.svg',
  '/assets/theme.css'
];

self.addEventListener('install', (event) => {
  console.log('[SW] Installing donor service worker...');
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(STATIC_ASSETS).catch(() => {}))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  console.log('[SW] Activating donor service worker...');
  event.waitUntil(
    caches.keys().then((names) => 
      Promise.all(names.filter(n => n !== CACHE_NAME).map(n => caches.delete(n)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;
  
  const url = new URL(event.request.url);
  if (url.pathname.includes('/api/') || url.pathname.endsWith('.php')) return;

  event.respondWith(
    fetch(event.request)
      .then((response) => {
        if (response.status === 200) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
        }
        return response;
      })
      .catch(() => caches.match(event.request))
  );
});

