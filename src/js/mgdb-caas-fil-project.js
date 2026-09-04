/* ==========================================================================
 * file: mgdb-caas-fil-project.js
 * purpose: Interactive filtering, live search, and sorting for CAAS-FIL Project
 * ========================================================================== */

(function () {
  'use strict';

  function initCaasFilTable() {
    var table = document.getElementById('caas-data-table');
    if (!table) return;

    var tbody = table.querySelector('tbody');
    var rows = Array.from(tbody.querySelectorAll('tr'));
    var searchInput = document.getElementById('caas-search-input');
    var filterChips = document.querySelectorAll('.caas-chip');
    var rowCountSpan = document.getElementById('caas-row-count');
    var sortHeaders = table.querySelectorAll('th.is-sortable');

    var currentFilter = 'all';
    var currentSearch = '';
    var currentSort = { col: 'line', dir: 'asc' };

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

          if (col === 'line') {
            valA = (a.querySelector('.caas-line-cell strong') || {}).textContent || '';
            valB = (b.querySelector('.caas-line-cell strong') || {}).textContent || '';
          } else if (col === 'group') {
            valA = (a.querySelector('.caas-badge') || {}).textContent || '';
            valB = (b.querySelector('.caas-badge') || {}).textContent || '';
          } else if (col === 'assembly') {
            valA = (a.querySelector('.caas-assembly-link strong') || {}).textContent || '';
            valB = (b.querySelector('.caas-assembly-link strong') || {}).textContent || '';
          } else if (col === 'annotation') {
            valA = (a.querySelector('.caas-anno-tag') || {}).textContent || '';
            valB = (b.querySelector('.caas-anno-tag') || {}).textContent || '';
          } else if (col === 'genbank') {
            valA = (a.querySelector('.caas-xref-link code') || {}).textContent || '';
            valB = (b.querySelector('.caas-xref-link code') || {}).textContent || '';
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

  /* The sticky section tabs. `.mgdb-section-tabs` is styled by the shell but
     driven per page, and this page shipped without a spy: the bar highlighted
     whatever the template marked and never changed, silently. MGDB.sectionTabs
     is that behaviour, shared, so this is the only line a page needs. */
  function boot() {
    initCaasFilTable();
    if (window.MGDB && MGDB.sectionTabs) { MGDB.sectionTabs(); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
