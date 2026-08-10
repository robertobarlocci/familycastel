// Family Castel service worker — static shell ONLY (plan §12).
// Authenticated HTML/JSON is NEVER cached: navigations go to the network and
// fall back to the public offline page. Served from the app root so the scope
// covers subdirectory installs automatically.
'use strict';

// Cache name is scoped per installation directory — multiple Family Castel
// installs (or other apps) on one origin must never collide, and activation
// must only ever clean up THIS app's own old caches.
const SCOPE_KEY = new URL(self.registration.scope).pathname;

// The version token comes from THIS worker's own script URL: the layout
// registers sw.js?v=<app version>. It is the same token the pages put on their
// asset URLs, which is what makes the precache reachable at all — caches.match()
// compares the FULL url, query included.
//
// A legacy registration (script url '/sw.js', no query) — including the
// browser's own periodic re-fetch of it — must NOT invent a token: precaching
// under a key no page ever requests would be worse than not precaching. Such a
// worker runs UNVERSIONED: static shell only, every CSS/JS request goes to the
// network. Correct, just not offline-capable, until a page re-registers the
// versioned URL.
const RAW = new URL(self.location.href).searchParams.get('v') || '';
const VERSION = RAW.replace(/[^A-Za-z0-9._-]/g, '');
const CACHE = 'fc-shell-' + (VERSION === '' ? 'unversioned' : VERSION) + ':' + SCOPE_KEY;

// Assets whose bytes change from release to release, and whose every requester
// can carry the token: the CSS/JS the views emit with asset(), plus offline.html,
// which only this worker ever requests.
const VERSIONED_PATHS = [
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
];

function versioned(path) {
    return VERSION === '' ? path : path + '?v=' + VERSION;
}

const OFFLINE_URL = versioned('offline.html');

// Requested by something that cannot carry a token: the fonts come from inside
// fonts.css (url('../fonts/…')) and the icons from the webmanifest. Versioning
// these would make their precache entries permanently unreachable.
const STATIC_SHELL = [
    'public-assets/icons/icon.svg',
    'public-assets/icons/icon-192.png',
    'public-assets/icons/icon-512.png',
    'public-assets/fonts/fredoka-600.woff2',
    'public-assets/fonts/fredoka-700.woff2',
    'public-assets/fonts/nunito-400.woff2',
    'public-assets/fonts/nunito-700.woff2',
    'public-assets/fonts/nunito-800.woff2',
];

const SHELL = (VERSION === '' ? [OFFLINE_URL] : VERSIONED_PATHS.map(versioned))
    .concat(STATIC_SHELL);

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
        // Cache-first for the versioned shell. A request carrying a token this
        // worker does not have (i.e. the app was updated under an old worker)
        // simply misses and goes to the network — which is exactly what makes a
        // released CSS change reach the user without waiting for a SW update.
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
                return caches.match(OFFLINE_URL);
            })
        );
    }
    // everything else (JSON etc.): default network behavior, uncached
});
