/* ==========================================================================
 * file: mgdb-fish-karyotypes.js
 * purpose: Tab switcher for FISH Karyotypes Gallery
 * ========================================================================== */

(function () {
  'use strict';

  function initFishGallery() {
    var tabs = document.querySelectorAll('.fish-tab-btn');
    if (!tabs.length) return;

    var panel14 = document.getElementById('view-14inbreds');
    var panelB73 = document.getElementById('view-b73mo17');

    tabs.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var view = btn.getAttribute('data-view');

        tabs.forEach(function (b) {
          b.classList.remove('is-active');
          b.setAttribute('aria-selected', 'false');
        });

        btn.classList.add('is-active');
        btn.setAttribute('aria-selected', 'true');

        if (view === '14inbreds') {
          if (panel14) panel14.style.display = 'block';
          if (panelB73) panelB73.style.display = 'none';
        } else {
          if (panel14) panel14.style.display = 'none';
          if (panelB73) panelB73.style.display = 'block';
        }
      });
    });

    // Support URL hash #b73mo17
    if (window.location.hash === '#b73mo17' || window.location.pathname.includes('B73Mo17FISH')) {
      var b73Tab = document.getElementById('tab-b73mo17');
      if (b73Tab) b73Tab.click();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFishGallery);
  } else {
    initFishGallery();
  }
})();
