/**
 * file: js/mgdb-stock-decryption.js
 *
 * purpose: /stock_decryption — section tab scrollspy.
 *
 * Everything else on the page is CSS and anchors. The four regions on the
 * photograph are links to the paragraphs that explain them, and the paragraph
 * that was asked for marks itself with `:target` — no script decides any of
 * that, so it works with this file absent. What is lost without it is the
 * active tab moving as you scroll.
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
