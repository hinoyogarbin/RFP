(() => {
    const toggle = document.querySelector('.nav-toggle');
    const topbar = document.querySelector('.topbar');

    if (!toggle || !topbar) {
        return;
    }

    document.body.classList.add('nav-toggle-ready');

    const setMenuOpen = (isOpen) => {
        topbar.classList.toggle('is-open', isOpen);
        toggle.setAttribute('aria-expanded', String(isOpen));
        toggle.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');
    };

    toggle.addEventListener('click', () => {
        setMenuOpen(toggle.getAttribute('aria-expanded') !== 'true');
    });

    topbar.querySelectorAll('.topbar-item').forEach((link) => {
        link.addEventListener('click', () => setMenuOpen(false));
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            setMenuOpen(false);
            toggle.focus();
        }
    });
})();
