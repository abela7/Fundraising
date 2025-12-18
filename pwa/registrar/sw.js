/**
 * Registrar PWA Service Worker
 */

const CACHE_NAME = 'registrar-pwa-v1';
const STATIC_ASSETS = [
  '/pwa/registrar/',
  '/pwa/registrar/index.html',
  '/pwa/registrar/login.html',
  '/pwa/registrar/assets/app.css',
  '/pwa/registrar/assets/app.js',
  '/pwa/shared/styles.css',
  '/pwa/shared/api-client.js',
  '/pwa/shared/auth.js',
  '/pwa/shared/install-tracker.js',
  '/pwa/shared/ui-helpers.js',
  '/assets/favicon.svg',
];

// Install
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(STATIC_ASSETS))
      .then(() => self.skipWaiting())
  );
});

// Activate
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((names) => Promise.all(
        names.filter((n) => n !== CACHE_NAME).map((n) => caches.delete(n))
      ))
      .then(() => self.clients.claim())
  );
});

// Fetch
self.addEventListener('fetch', (event) => {
  const { request } = event;
  
  if (request.method !== 'GET') return;
  if (!request.url.startsWith(location.origin)) return;
  
  if (request.url.includes('/api/')) {
    event.respondWith(networkFirst(request));
  } else {
    event.respondWith(cacheFirst(request));
  }
});

async function cacheFirst(request) {
  const cached = await caches.match(request);
  if (cached) return cached;
  
  try {
    const response = await fetch(request);
    if (response.ok) {
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    if (request.mode === 'navigate') {
      return caches.match('/pwa/registrar/');
    }
    throw error;
  }
}

async function networkFirst(request) {
  try {
    const response = await fetch(request);
    return response;
  } catch (error) {
    const cached = await caches.match(request);
    if (cached) return cached;
    
    return new Response(JSON.stringify({
      success: false,
      error: { message: 'You are offline', code: 'OFFLINE' }
    }), {
      status: 503,
      headers: { 'Content-Type': 'application/json' }
    });
  }
}

// Push notifications
self.addEventListener('push', (event) => {
  if (!event.data) return;
  
  try {
    const data = event.data.json();
    event.waitUntil(
      self.registration.showNotification(data.title || 'Registrar Portal', {
        body: data.body,
        icon: '/pwa/registrar/assets/icon-192.png',
        tag: data.tag || 'registrar-notification',
        data: data.data || {},
      })
    );
  } catch (error) {
    console.error('Push error:', error);
  }
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil(clients.openWindow('/pwa/registrar/'));
});

