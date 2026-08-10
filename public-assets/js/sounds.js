// Family Castel game sounds — synthesized with WebAudio (zero assets).
// Autoplay-policy compliant: the AudioContext is created lazily inside a user
// gesture. Active only when <body data-sound="1"> (parent/child setting).
(function () {
    'use strict';

    if (document.body.dataset.sound !== '1') {
        return;
    }
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var ctx = null;

    function ensureContext() {
        if (!ctx) {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) {
                return null;
            }
            ctx = new AC();
        }
        if (ctx.state === 'suspended') {
            ctx.resume();
        }
        return ctx;
    }

    function tone(frequency, start, duration, type, volume) {
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = type || 'sine';
        osc.frequency.value = frequency;
        gain.gain.setValueAtTime(volume || 0.12, ctx.currentTime + start);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + start + duration);
        osc.connect(gain).connect(ctx.destination);
        osc.start(ctx.currentTime + start);
        osc.stop(ctx.currentTime + start + duration + 0.02);
    }

    function coinChime() {
        if (!ensureContext()) { return; }
        tone(988, 0, 0.09, 'triangle', 0.10);   // B5
        tone(1319, 0.07, 0.16, 'triangle', 0.10); // E6
    }

    function fanfare() {
        if (!ensureContext()) { return; }
        [523, 659, 784, 1047].forEach(function (freq, i) { // C5 E5 G5 C6
            tone(freq, i * 0.12, 0.22, 'triangle', 0.11);
        });
    }

    // Primary action buttons chirp on tap (inside the gesture — always allowed).
    document.addEventListener('click', function (event) {
        if (event.target.closest('.btn-primary, .template-btn')) {
            coinChime();
        }
    });

    // A visible celebration plays the fanfare on the first interaction.
    if (!reduced && document.querySelector('[data-celebrate]')) {
        var once = function () {
            fanfare();
            document.removeEventListener('pointerdown', once);
        };
        document.addEventListener('pointerdown', once);
    }
})();
