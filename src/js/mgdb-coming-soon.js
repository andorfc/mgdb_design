/* ==========================================================================
   MaizeGDB Coming Soon (/coming_soon)
   --------------------------------------------------------------------------
   Every row is rendered server-side from data/coming_soon.json, so the
   inventory is complete and readable before JavaScript executes.

   Filters:
   1. Search query input ('cs-query')
   2. Category dropdown ('cs-category')
   3. Status chips ('cs-status')
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  function init() {
    var body = document.getElementById('cs-inventory-body');
    if (!body) { return; }

    var categorySelect = document.getElementById('cs-category');
    var countEl = document.getElementById('cs-count');

    /* Sync category parameter from URL on load */
    if (categorySelect && window.URLSearchParams) {
      var fromUrl = new window.URLSearchParams(window.location.search).get('category');
      if (fromUrl) { categorySelect.value = fromUrl; }
    }

    var api = MGDB.filterList({
      items: body.querySelectorAll('tr'),
      input: document.getElementById('cs-query'),
      chips: document.querySelectorAll('.mgdb-filters .mgdb-chip'),
      count: countEl,
      empty: document.getElementById('cs-empty'),
      reset: document.getElementById('cs-reset'),
      noun: 'items',
      urlKeys: { query: 'q', filter: 'status' },
      filterOn: function (row, value) {
        if (value !== 'all' && row.getAttribute('data-status') !== value) { return false; }
        if (!categorySelect || categorySelect.value === 'all') { return true; }
        return row.getAttribute('data-category') === categorySelect.value;
      },
      onChange: function (visible, total) {
        if (!countEl) { return; }
        var noun = function (n) { return n === 1 ? 'item' : 'items'; };
        countEl.textContent = (visible === total)
          ? total + ' ' + noun(total) + ' shown'
          : visible + ' of ' + total + ' ' + noun(total) + ' shown';
      }
    });

    if (!api) { return; }

    function syncCategoryUrl() {
      if (!categorySelect || !window.URLSearchParams || !window.history || !window.history.replaceState) { return; }
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

    var reset = document.getElementById('cs-reset');
    if (reset && categorySelect) {
      reset.addEventListener('click', function () {
        categorySelect.value = 'all';
        api.refresh();
        syncCategoryUrl();
      });
    }

    var emptyReset = document.getElementById('cs-empty-reset');
    if (emptyReset && reset) {
      emptyReset.addEventListener('click', function () { reset.click(); });
    }

    if (categorySelect && categorySelect.value !== 'all') { api.refresh(); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
