// Bundled site: supplement the bundled navigation without replacing its click handler.
document.addEventListener('DOMContentLoaded', function () {
    var header = document.getElementById('site-header');
    var button = header && header.querySelector('.nav-toggle');
    var nav = document.getElementById('global-nav');
    if (!header || !button || !nav) return;

    var mobile = window.matchMedia('(max-width: 767px)');
    header.classList.add('header--interactive');

    function close() {
        nav.classList.remove('is-open');
        button.setAttribute('aria-expanded', 'false');
    }

    header.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && mobile.matches && button.getAttribute('aria-expanded') === 'true') {
            close();
            button.focus();
        }
    });
    nav.addEventListener('click', function (event) {
        if (mobile.matches && event.target instanceof Element && event.target.closest('a')) close();
    });
    header.addEventListener('focusout', function (event) {
        if (mobile.matches && !header.contains(event.relatedTarget)) close();
    });
    document.addEventListener('click', function (event) {
        if (mobile.matches && !header.contains(event.target)) close();
    });
    mobile.addEventListener('change', close);
});
