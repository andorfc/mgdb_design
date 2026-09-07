/**
 * file: js/mgdb-genome-issue.js
 *
 * purpose: /curation/GenomeIssue — section tab scrollspy.
 *
 * The form's own behaviour is js/GenomeIssue.js, which is unchanged and loaded
 * before this. This file adds only the active-tab tracking the shared shell
 * expects; without it the bar still works and simply never leaves the first tab.
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
