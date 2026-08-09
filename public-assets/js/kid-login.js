// Kid login picker: tapping a profile either submits directly (no PIN set)
// or reveals that profile's PIN pad. External file — CSP forbids inline JS.
(function () {
    'use strict';

    document.querySelectorAll('.kid-avatar').forEach(function (button) {
        button.addEventListener('click', function () {
            var form = button.closest('form');
            if (!form) {
                return;
            }
            if (button.dataset.haspin === '0') {
                form.submit();
                return;
            }
            document.querySelectorAll('.kid-pin.open').forEach(function (panel) {
                if (!form.contains(panel)) {
                    panel.classList.remove('open');
                }
            });
            var pin = form.querySelector('.kid-pin');
            pin.classList.toggle('open');
            var input = pin.querySelector('input');
            if (pin.classList.contains('open') && input) {
                input.focus();
            }
        });
    });
})();
