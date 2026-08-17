// Celebration effects for the child's castle.
//
// Two triggers, deliberately separate:
//   [data-celebrate]        a fresh ACHIEVEMENT unlock. Also drives the fanfare
//                           in sounds.js — do not reuse it for anything else.
//   [data-celebrate-events] Coins or XP gained since the last visit. Confetti
//                           only, no sound.
// The dark rain cloud (.rain-fx, negative events) is pure CSS; this file only
// guarantees it goes away.
//
// Fires on context, not on every visit (no animation fatigue), and respects
// prefers-reduced-motion.
(function () {
    'use strict';

    // Both must stay just past the 2.6s .rain-fx scene in kid.css.
    var RAIN_TEARDOWN_MS = 3000;
    var CONFETTI_AFTER_RAIN_MS = 2800;

    var rain = document.querySelector('.rain-fx');

    // Two independent teardowns, because each alone has a failure mode: the CSS
    // scene ends invisible, and this removes the node outright. A browser or
    // extension that suppresses animations would otherwise strand a dark cloud
    // on screen (harmless to touch — pointer-events: none — but wrong), while
    // JS alone would depend on a script that might not run at all.
    if (rain) {
        window.setTimeout(function () {
            if (rain.parentNode) {
                rain.parentNode.removeChild(rain);
            }
        }, RAIN_TEARDOWN_MS);
    }

    var trigger = document.querySelector('[data-celebrate], [data-celebrate-events]');
    if (!trigger || typeof confetti !== 'function') {
        return;
    }

    function fire() {
        confetti({
            particleCount: 90,
            spread: 70,
            origin: { x: 0.3, y: 0.6 },
            disableForReducedMotion: true,
            colors: ['#f6b93b', '#58cc02', '#1cb0f6', '#ff7ab2']
        });
        confetti({
            particleCount: 90,
            angle: 120,
            spread: 70,
            origin: { x: 0.7, y: 0.6 },
            disableForReducedMotion: true,
            colors: ['#f6b93b', '#58cc02', '#1cb0f6', '#ff7ab2']
        });
    }

    // Both can be true at once — a parent awarded AND deducted between two
    // visits. Confetti bursting through a rain cloud reads as neither, so they
    // are sequenced: the consequence first, then the reward, leaving the child
    // looking at the celebratory screen. With no rain the delay is 0, so the
    // achievement path behaves exactly as it always has.
    if (rain) {
        window.setTimeout(fire, CONFETTI_AFTER_RAIN_MS);
    } else {
        fire();
    }
})();
