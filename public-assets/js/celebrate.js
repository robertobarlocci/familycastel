// Celebration effects (confetti on fresh achievements/level-ups). Fires only
// when the page carries a [data-celebrate] element — context, not every visit
// (no animation fatigue) — and respects prefers-reduced-motion.
(function () {
    'use strict';

    var trigger = document.querySelector('[data-celebrate]');
    if (!trigger || typeof confetti !== 'function') {
        return;
    }

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
})();
