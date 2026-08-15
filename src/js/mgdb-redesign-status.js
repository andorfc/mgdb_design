/* ==========================================================================
   Redesign status (/redesign_status)
   --------------------------------------------------------------------------
   Every row is rendered server-side from data/redesign_status.json, so the
   inventory is complete and readable before this runs. This only hides and
   shows rows that are already in the table.

   Two filters act at once: the chips choose an interface state and the select
   chooses a category. MGDB.filterList carries one filter value, so the chips
   drive it and the select is read inside filterOn and refreshed by hand.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  function init() {
    var body = document.getElementById('status-inventory-body');
    if (!body) { return; }

    var categorySelect = document.getElementById('status-category');
    var countEl = document.getElementById('status-count');

    /* The category is part of the shareable state, so it is read from the URL
       before the first filter pass rather than after it. */
    if (categorySelect && window.URLSearchParams) {
      var fromUrl = new window.URLSearchParams(window.location.search).get('category');
      if (fromUrl) { categorySelect.value = fromUrl; }
    }

    var api = MGDB.filterList({
      items: body.querySelectorAll('tr'),
      input: document.getElementById('status-query'),
      chips: document.querySelectorAll('.mgdb-filters .mgdb-chip'),
      count: countEl,
      empty: document.getElementById('status-empty'),
      reset: document.getElementById('status-reset'),
      noun: 'URLs',
      urlKeys: { query: 'q', filter: 'interface' },
      filterOn: function (row, value) {
        if (value !== 'all' && row.getAttribute('data-status') !== value) { return false; }
        if (!categorySelect || categorySelect.value === 'all') { return true; }
        return row.getAttribute('data-category') === categorySelect.value;
      },
      /* "1 URLs shown" reads badly, and the shared count has no singular. */
      onChange: function (visible, total) {
        if (!countEl) { return; }
        var noun = function (n) { return n === 1 ? 'URL' : 'URLs'; };
        countEl.textContent = (visible === total)
          ? total + ' ' + noun(total) + ' shown'
          : visible + ' of ' + total + ' ' + noun(total) + ' shown';
      }
    });

    if (!api) { return; }

    /* filterList only syncs the keys it owns, so the category is written to the
       address bar here, and removed when it is back to every category rather
       than left behind as an empty parameter. */
    function syncCategoryUrl() {
      if (!categorySelect) { return; }
      if (!window.URLSearchParams || !window.history || !window.history.replaceState) { return; }
      var params = new window.URLSearchParams(window.location.search);
      if (categorySelect.value === 'all') {
        params.delete('category');
      } else {
        params.set('category', categorySelect.value);
      }
      var query = params.toString();
      window.history.replaceState({}, '', window.location.pathname + (query ? '?' + query : ''));
    }

    if (categorySelect) {
      categorySelect.addEventListener('change', function () {
        api.refresh();
        syncCategoryUrl();
      });
    }

    var reset = document.getElementById('status-reset');
    if (reset && categorySelect) {
      /* Registered after filterList's own reset listener, so this runs second
         and its URL write is the one that survives. */
      reset.addEventListener('click', function () {
        categorySelect.value = 'all';
        api.refresh();
        syncCategoryUrl();
      });
    }

    var emptyReset = document.getElementById('status-empty-reset');
    if (emptyReset && reset) {
      emptyReset.addEventListener('click', function () { reset.click(); });
    }

    /* A category chosen from the URL has to be applied once at load; the first
       filter pass ran before the listener above existed. */
    if (categorySelect && categorySelect.value !== 'all') { api.refresh(); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
