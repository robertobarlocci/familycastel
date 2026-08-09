// Register the service worker relative to the app base (subdirectory-safe:
// the SW is served from the app root, so its scope is the install directory).
(function () {
    'use strict';

    if ('serviceWorker' in navigator) {
        // A page-relative 'sw.js' would resolve wrongly on nested routes —
        // the layout provides the app base explicitly.
        var base = document.body.dataset.base || '/';
        navigator.serviceWorker.register(base + 'sw.js').catch(function () {
            // PWA is progressive enhancement — never break the page over it.
        });
    }
})();
