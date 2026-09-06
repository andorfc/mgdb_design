/**
 * file: js/mgdb-working-group.js
 *
 * purpose: Section tab scrollspy for /working_group.
 *
 * MGDB.sectionTabs() is opt-in, and this page had never opted in: its tab bar
 * scrolled correctly and its active state never left "Membership", on all four
 * of its original sections. Adding the Steering Committee made a fifth, which
 * is what made the stuck highlight worth chasing -- a reader arriving from the
 * retired /steering_committee lands on that section with the first tab lit.
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
