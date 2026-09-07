/**
 * file: js/mgdb-curation-issues.js
 *
 * purpose: /curation/assemblyIssues and /curation/geneModelIssues — section tab
 *          scrollspy.
 *
 * The pages are server-rendered and complete without this file; what is lost is
 * the active tab moving as you scroll.
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
