/* ==========================================================================
   Linkage group index — /data_center/lg
   --------------------------------------------------------------------------
   The whole collection is 158 rows, so it is rendered server-side and this
   file only filters what is already there. No request, no search endpoint.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  function init() {
    if (MGDB.sectionTabs) { MGDB.sectionTabs(); }

    var table = document.getElementById('lg-index-table');
    if (!table) { return; }

    var rows = table.querySelectorAll('tbody tr');
    if (!rows.length) { return; }

    if (MGDB.filterList) {
      MGDB.filterList({
        items: rows,
        input: document.getElementById('lg-index-filter'),
        chips: document.querySelectorAll('#lg-index-chips .mgdb-chip'),
        count: document.getElementById('lg-index-count'),
        empty: document.getElementById('lg-index-empty'),
        noun: 'linkage groups',
        // The rows carry data-type; the default filterOn reads data-filter.
        filterOn: function (el, value) {
          return value === 'all' || el.getAttribute('data-type') === value;
        },
        urlKeys: { query: 'filter', filter: 'type' }
      });
    }

    if (MGDB.sortTable) { MGDB.sortTable(table); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
