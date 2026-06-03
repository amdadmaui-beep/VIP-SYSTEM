const CACHE_NAME = 'vip-rider-v2';
const OFFLINE_URL = '/VIP-system/capstone/offline.html';
const MAX_CACHE_ENTRIES = 50;
const CORE_ASSETS = [
  '/VIP-system/capstone/manifest.webmanifest',
  '/VIP-system/capstone/manifest_inventory_staff.webmanifest',
  '/VIP-system/capstone/offline.html',
  '/VIP-system/capstone/assets/images/pwa-icon-192.png',
  '/VIP-system/capstone/assets/images/pwa-icon-512.png',
  '/VIP-system/capstone/assets/images/vip_logo.jpg'
];

// Skip caching authenticated or API pages
function isSkippableUrl(url) {
  const skipPatterns = [
    '/api/', '/pages/', '/includes/', '/realtime/', '/backups/', '/reports/',
    '/login.php', '/logout.php'
  ];
  return skipPatterns.some(p => url.includes(p));
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(CORE_ASSETS)).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

// LRU eviction when cache exceeds limit
async function trimCache(cache, maxEntries) {
  const keys = await cache.keys();
  if (keys.length > maxEntries) {
    const toDelete = keys.slice(0, keys.length - maxEntries);
    await Promise.all(toDelete.map((req) => cache.delete(req)));
  }
}

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;
  const url = event.request.url;

  // Don't cache authenticated/API pages
  if (isSkippableUrl(url)) {
    event.respondWith(
      fetch(event.request).catch(() => {
        if (event.request.mode === 'navigate') {
          return caches.match(OFFLINE_URL);
        }
        return new Response('Offline', { status: 503 });
      })
    );
    return;
  }

  event.respondWith(
    fetch(event.request)
      .then((response) => {
        const copy = response.clone();
        caches.open(CACHE_NAME).then((cache) => {
          cache.put(event.request, copy);
          trimCache(cache, MAX_CACHE_ENTRIES);
        }).catch(() => {});
        return response;
      })
      .catch(() => {
        if (event.request.mode === 'navigate' || (event.request.headers.get('accept') || '').includes('text/html')) {
          return caches.match(OFFLINE_URL);
        }
        return caches.match(event.request);
      })
  );
});
