/**
 * Donor PWA Service Worker
 * 
 * Handles caching, offline support, and background sync.
 */

const CACHE_NAME = 'donor-pwa-v1';
const STATIC_ASSETS = [
  '/pwa/donor/',
  '/pwa/donor/index.html',
  '/pwa/donor/login.html',
  '/pwa/donor/assets/app.css',
  '/pwa/donor/assets/app.js',
  '/pwa/shared/styles.css',
  '/pwa/shared/api-client.js',
  '/pwa/shared/auth.js',
  '/pwa/shared/install-tracker.js',
  '/pwa/shared/ui-helpers.js',
  '/assets/favicon.svg',
];

// API endpoints to cache with network-first strategy
const API_CACHE_PATTERNS = [
  '/api/v1/donor/summary',
  '/api/v1/donor/profile',
];

/**
 * Install event - cache static assets
 */
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => {
        console.log('[SW] Caching static assets');
        return cache.addAll(STATIC_ASSETS);
      })
      .then(() => self.skipWaiting())
  );
});

/**
 * Activate event - clean up old caches
 */
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((cacheNames) => {
        return Promise.all(
          cacheNames
            .filter((name) => name !== CACHE_NAME)
            .map((name) => {
              console.log('[SW] Deleting old cache:', name);
              return caches.delete(name);
            })
        );
      })
      .then(() => self.clients.claim())
  );
});

/**
 * Fetch event - serve from cache or network
 */
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip non-GET requests
  if (request.method !== 'GET') {
    return;
  }

  // Skip cross-origin requests
  if (url.origin !== location.origin) {
    return;
  }

  // API requests - network first, cache fallback
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(networkFirstStrategy(request));
    return;
  }

  // Static assets - cache first, network fallback
  event.respondWith(cacheFirstStrategy(request));
});

/**
 * Cache-first strategy
 */
async function cacheFirstStrategy(request) {
  const cachedResponse = await caches.match(request);
  
  if (cachedResponse) {
    // Return cached response and update cache in background
    fetchAndCache(request);
    return cachedResponse;
  }

  return fetchAndCache(request);
}

/**
 * Network-first strategy
 */
async function networkFirstStrategy(request) {
  try {
    const networkResponse = await fetch(request);
    
    // Cache successful GET responses for certain API endpoints
    if (networkResponse.ok && shouldCacheApiResponse(request.url)) {
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, networkResponse.clone());
    }
    
    return networkResponse;
  } catch (error) {
    // Network failed, try cache
    const cachedResponse = await caches.match(request);
    
    if (cachedResponse) {
      return cachedResponse;
    }

    // Return offline response for API
    return new Response(
      JSON.stringify({
        success: false,
        error: {
          message: 'You are offline. Please check your connection.',
          code: 'OFFLINE',
        },
      }),
      {
        status: 503,
        headers: { 'Content-Type': 'application/json' },
      }
    );
  }
}

/**
 * Fetch and cache response
 */
async function fetchAndCache(request) {
  try {
    const networkResponse = await fetch(request);
    
    if (networkResponse.ok) {
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, networkResponse.clone());
    }
    
    return networkResponse;
  } catch (error) {
    // Network failed, return offline page for navigation requests
    if (request.mode === 'navigate') {
      const offlineResponse = await caches.match('/pwa/donor/');
      if (offlineResponse) {
        return offlineResponse;
      }
    }
    
    throw error;
  }
}

/**
 * Check if API response should be cached
 */
function shouldCacheApiResponse(url) {
  return API_CACHE_PATTERNS.some((pattern) => url.includes(pattern));
}

/**
 * Push notification handler
 */
self.addEventListener('push', (event) => {
  if (!event.data) {
    return;
  }

  try {
    const data = event.data.json();
    
    const options = {
      body: data.body || 'You have a new notification',
      icon: '/pwa/donor/assets/icon-192.png',
      badge: '/pwa/donor/assets/badge-72.png',
      tag: data.tag || 'donor-notification',
      data: data.data || {},
      actions: data.actions || [],
      vibrate: [200, 100, 200],
    };

    event.waitUntil(
      self.registration.showNotification(data.title || 'Donor Portal', options)
    );
  } catch (error) {
    console.error('[SW] Push notification error:', error);
  }
});

/**
 * Notification click handler
 */
self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const urlToOpen = event.notification.data?.url || '/pwa/donor/';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then((clientList) => {
        // Check if app is already open
        for (const client of clientList) {
          if (client.url.includes('/pwa/donor/') && 'focus' in client) {
            client.navigate(urlToOpen);
            return client.focus();
          }
        }
        // Open new window
        return clients.openWindow(urlToOpen);
      })
  );
});

/**
 * Background sync handler (for offline payments)
 */
self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-payments') {
    event.waitUntil(syncPendingPayments());
  }
});

/**
 * Sync pending offline payments
 */
async function syncPendingPayments() {
  // This would sync any payments made while offline
  // Implementation depends on IndexedDB storage of pending payments
  console.log('[SW] Syncing pending payments...');
}

