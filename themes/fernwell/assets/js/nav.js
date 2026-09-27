/**
 * © 2026 Kenneth Raymond. All rights reserved.
 * Part of the Fernwell WordPress theme.
 * Do not copy, modify, or redistribute without permission.
 *
 * File: Mobile Nav Toggle
 * Purpose: Disclosure behavior for the hamburger nav below 880px.
 *
 * Location:
 *   /wp-content/themes/fernwell/assets/js/nav.js
 */
(function () {
  var toggle = document.querySelector('.nav-toggle');
  var nav = document.getElementById('site-nav');
  if (!toggle || !nav) return;

  function isOpen() {
    return nav.classList.contains('open');
  }

  function close() {
    nav.classList.remove('open');
    toggle.setAttribute('aria-expanded', 'false');
  }

  toggle.addEventListener('click', function () {
    var open = nav.classList.toggle('open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  nav.addEventListener('click', function (e) {
    if (e.target.closest('a')) close();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isOpen()) {
      close();
      toggle.focus();
    }
  });

  document.addEventListener('click', function (e) {
    if (isOpen() && !e.target.closest('.nav-toggle') && !e.target.closest('#site-nav')) close();
  });
})();
