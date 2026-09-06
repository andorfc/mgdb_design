/* ==========================================================================
   The three community resource project pages
   --------------------------------------------------------------------------
     /projects/cytogenetic_map
     /projects/dooner_du_acds
     /projects/fowler_insertion_validation

   All three want the sticky section-tab bar to track the section in view.
   MGDB.sectionTabs() is that behaviour and it is opt-in rather than automatic,
   so a page that ships the bar without calling it gets one that scrolls
   correctly and then names the wrong section for the rest of the visit.

   Only the Fowler page has a table to filter, so everything below the tab bar
   is behind a lookup that finds nothing on the other two.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  function init() {
    var page = document.querySelector('.mgdb-resource-page');
    if (!page) { return; }

    /* `watch` is what makes the spy re-measure when the section heights change.
       Filtering the Fowler table hides rows, so every section under it moves;
       the other two pages have no such element and the option is ignored. */
    MGDB.sectionTabs({ watch: '#fw-rows' });

    var rows = document.getElementById('fw-rows');
    if (!rows) { return; }

    var countEl = document.getElementById('fw-count');

    MGDB.filterList({
      items: rows.querySelectorAll('tr'),
      input: document.getElementById('fw-query'),
      chips: document.querySelectorAll('.fw-controls .mgdb-chip'),
      count: countEl,
      empty: document.getElementById('fw-empty'),
      reset: document.getElementById('fw-reset'),
      noun:  'lines',
      urlKeys: { query: 'q', filter: 'status' },
      /* One status per line, so this is an equality test. */
      filterOn: function (el, value) {
        return value === 'all' || el.getAttribute('data-filter') === value;
      },
      /* The shared count writes "<n> lines shown", which reads oddly when the
         filter leaves exactly one. Same fix as /projects. */
      onChange: function (visible, total) {
        if (!countEl) { return; }
        var noun = function (n) { return n === 1 ? 'line' : 'lines'; };
        countEl.textContent = (visible === total)
          ? total + ' ' + noun(total) + ' shown'
          : visible + ' of ' + total + ' ' + noun(total) + ' shown';
      }
    });

    /* Sorting is the shared helper's, over the same rows the filter hides. The
       transmission column sorts on data-value rather than on its text,
       because the text is a percentage with a dagger on it and "Not
       determined" where there is no measurement. */
    MGDB.sortTable(document.getElementById('fw-table'));

    var emptyReset = document.getElementById('fw-empty-reset');
    var reset = document.getElementById('fw-reset');
    if (emptyReset && reset) {
      emptyReset.addEventListener('click', function () { reset.click(); });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
