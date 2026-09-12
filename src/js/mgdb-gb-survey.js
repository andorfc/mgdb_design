/**
 * file: js/mgdb-gb-survey.js
 *
 * purpose: Section tab scrollspy for /genome_browser_survey.
 *
 * MGDB.sectionTabs() is opt-in. Without it the bar looks right and scrolls, and
 * the active state never leaves the first tab.
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
