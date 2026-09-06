/* ==========================================================================
   Gene symbols (/data_center/gene-symbols)

   The table is rendered server side and complete before this runs; the filter
   only hides and shows rows. MGDB.filterList debounces its input by 200ms, so
   a test that types and reads the count immediately will see the old number.
   ========================================================================== */

(function (window, document) {
  'use strict';

  function init() {
    var MGDB = window.MGDB || {};
    var table = document.getElementById('gs-table');

    if (MGDB.sortTable && table) { MGDB.sortTable(table); }

    if (!MGDB.filterList) { return; }

    MGDB.filterList({
      items: document.querySelectorAll('#gs-rows .gs-row'),
      input: document.getElementById('gs-filter'),
      count: document.getElementById('gs-count'),
      empty: document.getElementById('gs-empty'),
      noun: 'entries',
      /* data-search holds symbol and meaning together, so "kinase" finds the
         rows whose meaning names one rather than only symbols spelled that way. */
      matchOn: function (el) { return el.getAttribute('data-search') || el.textContent || ''; },
      urlKeys: { query: 'q' }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
