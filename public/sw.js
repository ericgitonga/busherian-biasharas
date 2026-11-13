/* Busherian Biasharas — Privacy-Safe Progressive Web App Service Worker (#287, #289)
 *
 * Security & Kenya DPA 2019 Invariants:
 * 1. Strict GET-only, same-origin allowlist: NEVER intercepts cross-origin requests (e.g. OSM tiles),
 *    POST/PUT/DELETE requests, /admin/*, /owner/*, /submit.php, /takedown.php, /uploads/*, or write/tracking APIs.
 * 2. Anonymous-only caching: Pre-caches shell & directory snapshot strictly with { credentials: 'omit' },
 *    and only stores runtime HTML/JSON responses carrying 'X-BBiz-Public-Cacheable: 1' (emitted exclusively
 *    for unauthenticated visitors), guaranteeing owner_email_hints / edit links never enter Cache Storage.
 * 3. Network-First with Offline Fallback: Live online traffic always reaches PHP/MySQL directly so Kenya DPA s.40
 *    takedowns/quarantines and live EAT hours take effect immediately; cached snapshots serve only when offline.
 */
'use strict';

const CACHE_VERSION = 'bbiz-pwa-v0.27.0';
const CACHE_PREFIX = 'bbiz-pwa-';

function getBasePath() {
  try {
    const scopeUrl = new URL(self.registration.scope);
    return scopeUrl.pathname.replace(/\/$/, '');
  } catch (_) {
    return '';
  }
}

const STATIC_ASSET_PATHS = [
  '/site.webmanifest',
  '/manifest.webmanifest',
  '/assets/css/app.css',
  '/assets/js/consent-metrics.js',
  '/assets/js/directory.js',
  '/assets/vendor/leaflet/leaflet.css',
  '/assets/vendor/leaflet/leaflet.js',
  '/favicon.svg',
  '/favicon-32x32.png',
  '/favicon-192x192.png',
  '/apple-touch-icon.png',
  '/assets/img/bb-icon-512.png',
  '/assets/img/bb-icon-maskable-512.png',
];

const PUBLIC_DYNAMIC_PATHS = [
  '/',
  '/?source=pwa',
  '/charter.php',
  '/privacy.php',
  '/api/businesses.php?page=1&per_page=100',
];

function isStaticAllowlisted(relPath) {
  if (
    relPath === '/site.webmanifest' ||
    relPath === '/manifest.webmanifest' ||
    relPath === '/favicon.svg' ||
    relPath === '/favicon.ico' ||
    relPath === '/favicon-32x32.png' ||
    relPath === '/favicon-192x192.png' ||
    relPath === '/apple-touch-icon.png'
  ) {
    return true;
  }
  return relPath.startsWith('/assets/');
}

function isDynamicAllowlisted(relPath) {
  return (
    relPath === '' ||
    relPath === '/' ||
    relPath === '/index.php' ||
    relPath === '/charter.php' ||
    relPath === '/privacy.php' ||
    relPath === '/api/businesses.php'
  );
}

function isForbiddenPath(relPath) {
  if (
    relPath === '/admin' ||
    relPath.startsWith('/admin/') ||
    relPath === '/owner' ||
    relPath.startsWith('/owner/') ||
    relPath === '/submit.php' ||
    relPath === '/takedown.php' ||
    relPath.startsWith('/uploads/')
  ) {
    return true;
  }
  if (relPath.startsWith('/api/') && relPath !== '/api/businesses.php') {
    return true;
  }
  return false;
}

self.addEventListener('install', (event) => {
  const base = getBasePath();
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_VERSION);
    const allTargets = [
      ...STATIC_ASSET_PATHS.map((p) => ({ url: base + p, requirePublicHeader: false })),
      ...PUBLIC_DYNAMIC_PATHS.map((p) => ({ url: base + p, requirePublicHeader: true })),
    ];
    await Promise.allSettled(
      allTargets.map(async (item) => {
        const res = await fetch(item.url, { credentials: 'omit', cache: 'no-cache' });
        if (!res || res.status !== 200) return;
        if (item.requirePublicHeader && res.headers.get('X-BBiz-Public-Cacheable') !== '1') {
          return;
        }
        await cache.put(item.url, res);
      })
    );
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(
      keys
        .filter((k) => k.startsWith(CACHE_PREFIX) && k !== CACHE_VERSION)
        .map((k) => caches.delete(k))
    );
  })());
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (!req || req.method !== 'GET') return;

  let parsedUrl;
  try {
    parsedUrl = new URL(req.url);
  } catch (_) {
    return;
  }

  // Strictly same-origin only (never touch third-party tile servers or external domains)
  if (parsedUrl.origin !== self.location.origin) return;

  const base = getBasePath();
  if (base && !parsedUrl.pathname.startsWith(base)) return;

  const relPath = base ? (parsedUrl.pathname.slice(base.length) || '/') : parsedUrl.pathname;
  if (isForbiddenPath(relPath)) return;

  const isStatic = isStaticAllowlisted(relPath);
  const isDynamic = isDynamicAllowlisted(relPath);
  if (!isStatic && !isDynamic) return;

  event.respondWith((async () => {
    const cache = await caches.open(CACHE_VERSION);
    try {
      const netRes = await fetch(req);
      if (netRes && netRes.status === 200) {
        const canStore = isStatic || netRes.headers.get('X-BBiz-Public-Cacheable') === '1';
        if (canStore) {
          cache.put(req, netRes.clone()).catch(() => {});
        }
      }
      return netRes;
    } catch (networkErr) {
      const exactCached = await cache.match(req);
      if (exactCached) {
        return exactCached;
      }

      if (relPath === '' || relPath === '/' || relPath === '/index.php') {
        const shellFallback = (await cache.match((base || '') + '/')) || (await cache.match((base || '') + '/?source=pwa'));
        if (shellFallback) {
          return shellFallback;
        }
      }

      if (relPath === '/api/businesses.php') {
        const snapshotRes = await cache.match((base || '') + '/api/businesses.php?page=1&per_page=100');
        if (snapshotRes) {
          const bodyText = await snapshotRes.text();
          const headers = new Headers(snapshotRes.headers);
          headers.set('Content-Type', 'application/json; charset=UTF-8');
          headers.set('X-BBiz-Offline-Snapshot', '1');
          return new Response(bodyText, {
            status: 200,
            statusText: 'OK (Offline Snapshot)',
            headers,
          });
        }
      }

      throw networkErr;
    }
  })());
});
