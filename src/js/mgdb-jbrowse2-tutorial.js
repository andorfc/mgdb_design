/* ==========================================================================
 * file: mgdb-jbrowse2-tutorial.js
 * purpose: Navigation enhancements for JBrowse 2 Tutorial
 * ========================================================================== */

(function () {
  'use strict';

  function initTutorialNav() {
    // Smooth scroll for hash links within the page
    var hashLinks = document.querySelectorAll('.jb2-hero-link, .mgdb-section-tabs a');
    hashLinks.forEach(function (link) {
      link.addEventListener('click', function (e) {
        var href = link.getAttribute('href');
        if (href && href.startsWith('#')) {
          var target = document.querySelector(href);
          if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            history.pushState(null, null, href);
          }
        }
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTutorialNav);
  } else {
    initTutorialNav();
  }
})();
