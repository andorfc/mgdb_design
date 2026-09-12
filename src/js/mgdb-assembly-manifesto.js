/* Maize genome assemblies and annotations (/assembly_manifesto).
 *
 * The page is prose in seven sections with a sticky tab bar, so the only
 * behaviour it needs is the shared scrollspy. The bar never wraps -- see
 * css/mgdb-assembly-manifesto.css -- so one scroll-margin-top is right at every
 * width, and MGDB.sectionTabs() reads that value back from computed style.
 */
(function () {
  'use strict';

  function start() {
    if (window.MGDB && typeof window.MGDB.sectionTabs === 'function') {
      window.MGDB.sectionTabs();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
}());
