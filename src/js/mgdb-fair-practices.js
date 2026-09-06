/* /FAIRpractices — section tab scrollspy.
 *
 * `.mgdb-section-tabs` is styled by the hub shell but driven per page, and this
 * page shipped with no page script at all: its old `.fair-jump-nav` linked to
 * the sections and never marked the one you were in. MGDB.sectionTabs is that
 * behaviour, shared, so this is the only line the page needs.
 *
 * Bauplan emits page scripts into <head>, so nothing here touches the DOM before
 * DOMContentLoaded.
 */
(function (window, document) {
  'use strict';

  function init() {
    if (window.MGDB && MGDB.sectionTabs) { MGDB.sectionTabs(); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
