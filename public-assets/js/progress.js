// Fill progress bars from data-fraction via CSSOM — inline style attributes
// are blocked by our strict CSP (style-src 'self'), JS-set styles are not.
(function () {
    'use strict';

    document.querySelectorAll('[data-fraction]').forEach(function (el) {
        var fraction = Math.max(0, Math.min(1, parseFloat(el.dataset.fraction) || 0));
        el.style.width = (fraction * 100).toFixed(1) + '%';
    });
})();
