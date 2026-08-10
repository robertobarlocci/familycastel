// Family Castel service worker — static shell ONLY (plan §12).
// Authenticated HTML/JSON is NEVER cached: navigations go to the network and
// fall back to the public offline page. Served from the app root so the scope
// covers subdirectory installs automatically.
'use strict';

// Cache name is scoped per installation directory — multiple Family Castel
// installs (or other apps) on one origin must never collide, and activation
// must only ever clean up THIS app's own old caches.
const SCOPE_KEY = new URL(self.registration.scope).pathname;
const CACHE = 'fc-shell-v1:' + SCOPE_KEY;
const SHELL = [
    'offline.html',
    'public-assets/css/fonts.css',
    'public-assets/css/app.css',
    'public-assets/css/kid.css',
    'public-assets/css/install.css',
    'public-assets/js/progress.js',
    'public-assets/js/sounds.js',
    'public-assets/js/celebrate.js',
    'public-assets/js/confirm.js',
    'public-assets/js/kid-login.js',
    'public-assets/js/pwa.js',
    'public-assets/vendor/confetti.js',
    'public-assets/icons/icon.svg',
    'public-assets/icons/icon-192.png',
    'public-assets/icons/icon-512.png',
    'public-assets/fonts/fredoka-600.woff2',
    'public-assets/fonts/fredoka-700.woff2',
    'public-assets/fonts/nunito-400.woff2',
    'public-assets/fonts/nunito-700.woff2',
    'public-assets/fonts/nunito-800.woff2',
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE).then(function (cache) {
            return cache.addAll(SHELL);
        }).then(function () {
            return self.skipWaiting();
        })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.filter(function (key) {
                return key.indexOf('fc-shell-') === 0 && key.endsWith(':' + SCOPE_KEY) && key !== CACHE;
            }).map(function (key) {
                return caches.delete(key);
            }));
        }).then(function () {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function (event) {
    const request = event.request;
    if (request.method !== 'GET') {
        return; // never touch mutations
    }

    const url = new URL(request.url);
    const isStatic = url.pathname.includes('/public-assets/');

    if (isStatic) {
        // cache-first for the versioned shell
        event.respondWith(
            caches.match(request).then(function (cached) {
                return cached || fetch(request);
            })
        );
        return;
    }

    if (request.mode === 'navigate') {
        // network only, static offline fallback — authenticated HTML is never cached
        event.respondWith(
            fetch(request).catch(function () {
                return caches.match('offline.html');
            })
        );
    }
    // everything else (JSON etc.): default network behavior, uncached
});
