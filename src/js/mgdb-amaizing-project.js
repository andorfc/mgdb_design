/* ==========================================================================
 * file: mgdb-amaizing-project.js
 * purpose: Interactive filtering, live search, and sorting for AMAIZING Project
 * ========================================================================== */

(function () {
  'use strict';

  function initAmaizingTable() {
    var table = document.getElementById('amaizing-data-table');
    if (!table) return;

    var tbody = table.querySelector('tbody');
    var rows = Array.from(tbody.querySelectorAll('tr'));
    var searchInput = document.getElementById('amaizing-search-input');
    var filterChips = document.querySelectorAll('.amaizing-chip');
    var rowCountSpan = document.getElementById('amaizing-row-count');
    var sortHeaders = table.querySelectorAll('th.is-sortable');

    var currentFilter = 'all';
    var currentSearch = '';
    var currentSort = { col: 'assembly', dir: 'asc' };

    function updateTable() {
      var visibleCount = 0;
      var query = currentSearch.toLowerCase().trim();

      rows.forEach(function (row) {
        var clade = row.getAttribute('data-clade') || '';
        var searchData = (row.getAttribute('data-search') || '').toLowerCase();
        var rowText = row.textContent.toLowerCase();

        var matchesFilter = (currentFilter === 'all') || (clade === currentFilter);
        var matchesSearch = !query || searchData.includes(query) || rowText.includes(query);

        if (matchesFilter && matchesSearch) {
          row.style.display = '';
          visibleCount++;
        } else {
          row.style.display = 'none';
        }
      });

      if (rowCountSpan) {
        rowCountSpan.textContent = 'Showing ' + visibleCount + ' of ' + rows.length + ' assemblies';
      }
    }

    // Filter Chips
    filterChips.forEach(function (chip) {
      chip.addEventListener('click', function () {
        filterChips.forEach(function (c) { c.classList.remove('is-active'); });
        chip.classList.add('is-active');
        currentFilter = chip.getAttribute('data-filter') || 'all';
        updateTable();
      });
    });

    // Search Input
    if (searchInput) {
      searchInput.addEventListener('input', function () {
        currentSearch = searchInput.value;
        updateTable();
      });
    }

    // Column Sorting
    sortHeaders.forEach(function (th) {
      var btn = th.querySelector('button');
      if (!btn) return;

      btn.addEventListener('click', function () {
        var col = th.getAttribute('data-sort');
        var isAsc = (currentSort.col === col && currentSort.dir === 'asc');
        var newDir = isAsc ? 'desc' : 'asc';
        currentSort = { col: col, dir: newDir };

        // Update sort icons & aria-sort
        sortHeaders.forEach(function (h) {
          h.removeAttribute('aria-sort');
          var icon = h.querySelector('.sort-icon');
          if (icon) icon.innerHTML = '&updownarrow;';
        });

        th.setAttribute('aria-sort', newDir === 'asc' ? 'ascending' : 'descending');
        var icon = th.querySelector('.sort-icon');
        if (icon) {
          icon.innerHTML = newDir === 'asc' ? '&uarr;' : '&darr;';
        }

        // Sort rows
        rows.sort(function (a, b) {
          var valA = '';
          var valB = '';

          if (col === 'assembly') {
            valA = (a.querySelector('.amaizing-assembly-link strong') || {}).textContent || '';
            valB = (b.querySelector('.amaizing-assembly-link strong') || {}).textContent || '';
          } else if (col === 'line') {
            valA = (a.querySelector('.amaizing-line-name') || {}).textContent || '';
            valB = (b.querySelector('.amaizing-line-name') || {}).textContent || '';
          } else if (col === 'group') {
            valA = (a.querySelector('.amaizing-badge') || {}).textContent || '';
            valB = (b.querySelector('.amaizing-badge') || {}).textContent || '';
          } else if (col === 'annotation') {
            valA = (a.querySelector('.amaizing-anno-tag') || {}).textContent || '';
            valB = (b.querySelector('.amaizing-anno-tag') || {}).textContent || '';
          }

          valA = valA.trim().toLowerCase();
          valB = valB.trim().toLowerCase();

          if (valA < valB) return newDir === 'asc' ? -1 : 1;
          if (valA > valB) return newDir === 'asc' ? 1 : -1;
          return 0;
        });

        // Re-append sorted rows
        rows.forEach(function (row) {
          tbody.appendChild(row);
        });

        updateTable();
      });
    });

    updateTable();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAmaizingTable);
  } else {
    initAmaizingTable();
  }
})();
