var CACHE_NAME = 'vip-rider-v5';
var API_CACHE = 'vip-api-v2';
var OFFLINE_URL = '/VIP-system/capstone/offline.html';
var MAX_CACHE_ENTRIES = 100;
var CORE_ASSETS = [
  '/VIP-system/capstone/manifest.webmanifest',
  '/VIP-system/capstone/manifest_inventory_staff.webmanifest',
  '/VIP-system/capstone/offline.html',
  '/VIP-system/capstone/assets/images/pwa-icon-192.png',
  '/VIP-system/capstone/assets/images/pwa-icon-512.png',
  '/VIP-system/capstone/assets/images/vip_logo.jpg'
];

var API_PREFIX = '/VIP-system/capstone/api/';

function isApiGetRequest(url) {
  return url.indexOf(API_PREFIX) !== -1 && url.indexOf('action=get') !== -1;
}

function isStateChangingApi(url, method) {
  return url.indexOf(API_PREFIX) !== -1 && method !== 'GET';
}

function isSkippableUrl(url) {
  var patterns = ['/includes/', '/realtime/', '/backups/', '/reports/', '/login.php', '/logout.php'];
  for (var i = 0; i < patterns.length; i++) {
    if (url.indexOf(patterns[i]) !== -1) return true;
  }
  return false;
}

function cloneResponse(response) {
  return new Response(response.body, {
    status: response.status,
    statusText: response.statusText,
    headers: response.headers
  });
}

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) { return cache.addAll(CORE_ASSETS); }).catch(function () {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) { return k !== CACHE_NAME && k !== API_CACHE; }).map(function (k) { return caches.delete(k); }));
    })
  );
  self.clients.claim();
});

function trimCache(cache, maxEntries) {
  cache.keys().then(function (keys) {
    if (keys.length > maxEntries) {
      var toDelete = keys.slice(0, keys.length - maxEntries);
      Promise.all(toDelete.map(function (req) { return cache.delete(req); }));
    }
  }).catch(function () {});
}

// Returns true for static binary assets that rarely change (images, fonts, icons).
// These are safe for cache-first. Everything else (HTML/PHP, CSS, JS) uses network-first.
function isStaticAsset(url) {
  return /\.(png|jpg|jpeg|gif|webp|svg|ico|woff2?|ttf|eot)(\?|$)/.test(url);
}

self.addEventListener('fetch', function (event) {
  var url = event.request.url;
  var method = event.request.method;

  // Always go to network for state-changing API calls
  if (isStateChangingApi(url, method)) {
    event.respondWith(
      fetch(event.request).catch(function () {
        return new Response(JSON.stringify({ success: false, error: 'You are offline. This action will be queued when connection returns.', offline_queue: true }), {
          status: 503,
          headers: { 'Content-Type': 'application/json' }
        });
      })
    );
    return;
  }

  // Network-first for API GET requests (cache as offline fallback only)
  if (isApiGetRequest(url)) {
    event.respondWith(
      fetch(event.request).then(function (response) {
        var copy = response.clone();
        caches.open(API_CACHE).then(function (cache) {
          cache.put(event.request, copy);
          trimCache(cache, MAX_CACHE_ENTRIES);
        }).catch(function () {});
        return response;
      }).catch(function () {
        return caches.match(event.request).then(function (cached) {
          if (cached) return cloneResponse(cached);
          return new Response(JSON.stringify({ success: false, error: 'No cached data available offline.', offline: true }), {
            status: 503,
            headers: { 'Content-Type': 'application/json' }
          });
        });
      })
    );
    return;
  }

  // Skip caching for login/logout/includes/realtime endpoints
  if (isSkippableUrl(url)) {
    event.respondWith(
      fetch(event.request).catch(function () {
        if (event.request.mode === 'navigate') return caches.match(OFFLINE_URL);
        return new Response('Offline', { status: 503 });
      })
    );
    return;
  }

  // STATIC ASSETS (images, fonts): cache-first — they rarely change
  if (isStaticAsset(url)) {
    event.respondWith(
      caches.match(event.request).then(function (cached) {
        if (cached) return cached;
        return fetch(event.request).then(function (response) {
          var copy = response.clone();
          caches.open(CACHE_NAME).then(function (cache) {
            cache.put(event.request, copy);
            trimCache(cache, MAX_CACHE_ENTRIES);
          }).catch(function () {});
          return response;
        }).catch(function () {
          return new Response('Offline', { status: 503 });
        });
      })
    );
    return;
  }

  // HTML/PHP pages, CSS, JS: NETWORK-FIRST — always fetch fresh, cache as fallback.
  // This is the critical fix: normal browser refresh now always gets the latest code.
  event.respondWith(
    fetch(event.request).then(function (response) {
      // Only cache successful responses
      if (response && response.status === 200) {
        var copy = response.clone();
        caches.open(CACHE_NAME).then(function (cache) {
          cache.put(event.request, copy);
          trimCache(cache, MAX_CACHE_ENTRIES);
        }).catch(function () {});
      }
      return response;
    }).catch(function () {
      // Network failed — serve stale cache or offline page
      return caches.match(event.request).then(function (cached) {
        if (cached) return cached;
        if (event.request.mode === 'navigate') return caches.match(OFFLINE_URL);
        return new Response('Offline', { status: 503 });
      });
    })
  );
});
