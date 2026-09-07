/**
 * file: js/mgdb-coordinate-definition.js
 *
 * purpose: Section tab scrollspy for /coordinateDef.
 *
 * The bar is styled by the shared shell whether or not this runs, which is the
 * failure mode worth naming: without a spy the tabs still look right and still
 * scroll, and the active state simply never leaves the first one. Eight
 * sections here, so it is needed.
 */

(function () {
  'use strict';

  function init() {
    if (window.MGDB && window.MGDB.sectionTabs) {
      window.MGDB.sectionTabs();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
