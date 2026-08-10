// Render the QR login link into #qr-canvas using the vendored
// qrcode-generator library (MIT, Kazuhiko Arase). CSP-safe external file.
(function () {
    'use strict';

    var el = document.getElementById('qr-canvas');
    if (!el || typeof qrcode !== 'function') {
        return;
    }
    var qr = qrcode(0, 'M');
    qr.addData(el.dataset.url);
    qr.make();
    el.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 4, scalable: true });
})();
