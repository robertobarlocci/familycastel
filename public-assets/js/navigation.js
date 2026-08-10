(function () {
    'use strict';

    var header = document.querySelector('[data-parent-header]');
    if (!header) {
        return;
    }

    var toggle = header.querySelector('.topbar-menu-toggle');
    var menu = header.querySelector('#parent-menu');
    if (!toggle || !menu) {
        return;
    }

    var openLabel = toggle.getAttribute('aria-label') || 'Open menu';
    var closeLabel = toggle.getAttribute('data-close-label') || 'Close menu';

    function setOpen(open, restoreFocus) {
        header.classList.toggle('menu-open', open);
        document.body.classList.toggle('parent-menu-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? closeLabel : openLabel);
        if (!open && restoreFocus) {
            toggle.focus();
        }
    }

    toggle.hidden = false;
    header.classList.add('menu-ready');

    toggle.addEventListener('click', function () {
        setOpen(toggle.getAttribute('aria-expanded') !== 'true', false);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            setOpen(false, true);
        }
    });

    document.addEventListener('click', function (event) {
        if (toggle.getAttribute('aria-expanded') === 'true' && !header.contains(event.target)) {
            setOpen(false, false);
        }
    });

    menu.addEventListener('click', function (event) {
        if (event.target.closest('a')) {
            setOpen(false, false);
        }
    });

    var desktop = window.matchMedia('(min-width: 861px)');
    desktop.addEventListener('change', function (event) {
        if (event.matches) {
            setOpen(false, false);
        }
    });
}());
