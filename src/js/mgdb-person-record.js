/* mgdb-person-record.js
 *
 * /person?id={id}. The record is rendered server-side, so the only behaviour
 * this page needs is the shared sticky section-tab scrollspy. The page is fully
 * usable without it -- the tabs are plain in-page anchors.
 */
(function () {
  'use strict';
  function init() {
    if (window.MGDB && MGDB.sectionTabs && document.querySelector('.mgdb-section-tabs')) {
      MGDB.sectionTabs();
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
