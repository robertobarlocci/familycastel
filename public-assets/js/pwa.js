// Register the service worker relative to the app base (subdirectory-safe:
// the SW is served from the app root, so its scope is the install directory).
(function () {
    'use strict';

    if ('serviceWorker' in navigator) {
        // The layout provides the COMPLETE sw.js URL (subdirectory-safe and
        // correct in ?r= fallback mode, where concatenating onto a routed
        // base would produce a routed — and 404ing — sw.js path).
        var swUrl = document.body.dataset.sw;
        if (swUrl) {
            navigator.serviceWorker.register(swUrl).catch(function () {
                // PWA is progressive enhancement — never break the page over it.
            });
        }
    }
})();
