/* ==========================================================================
 * file: mgdb-historic-assemblies.js
 * purpose: Filter and search for Historic Gene Model Migrations
 * ========================================================================== */

(function () {
  'use strict';

  function initHistoricTable() {
    var table = document.getElementById('historic-gene-table');
    if (!table) return;

    var tbody = table.querySelector('tbody');
    var rows = Array.from(tbody.querySelectorAll('tr'));
    var searchInput = document.getElementById('historic-search-input');
    var filterChips = document.querySelectorAll('.historic-chip');
    var rowCountSpan = document.getElementById('historic-row-count');

    var currentFilter = 'all';
    var currentSearch = '';

    function updateTable() {
      var visibleCount = 0;
      var query = currentSearch.toLowerCase().trim();

      rows.forEach(function (row) {
        var type = row.getAttribute('data-type') || '';
        var searchData = (row.getAttribute('data-search') || '').toLowerCase();
        var rowText = row.textContent.toLowerCase();

        var matchesFilter = (currentFilter === 'all') || (type === currentFilter);
        var matchesSearch = !query || searchData.includes(query) || rowText.includes(query);

        if (matchesFilter && matchesSearch) {
          row.style.display = '';
          visibleCount++;
        } else {
          row.style.display = 'none';
        }
      });

      if (rowCountSpan) {
        rowCountSpan.textContent = 'Showing ' + visibleCount + ' of ' + rows.length + ' historical gene models';
      }
    }

    filterChips.forEach(function (chip) {
      chip.addEventListener('click', function () {
        filterChips.forEach(function (c) { c.classList.remove('is-active'); });
        chip.classList.add('is-active');
        currentFilter = chip.getAttribute('data-filter') || 'all';
        updateTable();
      });
    });

    if (searchInput) {
      searchInput.addEventListener('input', function () {
        currentSearch = searchInput.value;
        updateTable();
      });
    }

    updateTable();
  }

  /* The sticky section tabs. `.mgdb-section-tabs` is styled by the shell but
     driven per page, and this page shipped without a spy: the bar highlighted
     whatever the template marked and never changed, silently. MGDB.sectionTabs
     is that behaviour, shared, so this is the only line a page needs. */
  function boot() {
    initHistoricTable();
    if (window.MGDB && MGDB.sectionTabs) { MGDB.sectionTabs(); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
