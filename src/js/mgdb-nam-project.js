/* ==========================================================================
   NAM Parent Genome Assembly Project — /NAM_project
   Interactive table filtering, multi-column sorting, and scrollspy
   ========================================================================== */

(function () {
  'use strict';

  var currentSubpopFilter = 'all';
  var currentSearchQuery = '';
  var currentSortCol = 'line';
  var currentSortDir = 'asc';

  function init() {
    var table = document.getElementById('nam-data-table');
    if (!table) return;

    var tbody = table.querySelector('tbody');
    var rows = Array.from(tbody.querySelectorAll('tr'));
    var searchInput = document.getElementById('nam-search-input');
    var chipButtons = document.querySelectorAll('[data-subpop-filter]');
    var rowCountEl = document.getElementById('nam-row-count');
    var sortHeaders = table.querySelectorAll('th.is-sortable');

    // 1. FILTERING FUNCTION
    function applyFilters() {
      var visibleCount = 0;
      var q = currentSearchQuery.toLowerCase().trim();

      rows.forEach(function (row) {
        var subpop = row.getAttribute('data-subpop') || '';
        var line = (row.getAttribute('data-line') || '').toLowerCase();
        var grin = (row.getAttribute('data-grin') || '').toLowerCase();
        var stock = (row.getAttribute('data-stock') || '').toLowerCase();
        var ncbi = (row.getAttribute('data-ncbi') || '').toLowerCase();
        var textContent = row.textContent.toLowerCase();

        var matchesSubpop = (currentSubpopFilter === 'all' || subpop === currentSubpopFilter);
        var matchesSearch = (!q || line.includes(q) || textContent.includes(q) || grin.includes(q) || stock.includes(q) || ncbi.includes(q));

        if (matchesSubpop && matchesSearch) {
          row.style.display = '';
          visibleCount++;
        } else {
          row.style.display = 'none';
        }
      });

      if (rowCountEl) {
        rowCountEl.textContent = 'Showing ' + visibleCount + ' of ' + rows.length + ' assemblies';
      }
    }

    // 2. SORTING FUNCTION
    function sortTable(column, direction) {
      rows.sort(function (a, b) {
        var valA = '';
        var valB = '';

        if (column === 'line') {
          valA = a.getAttribute('data-line') || '';
          valB = b.getAttribute('data-line') || '';
          // Keep B73 at the top if sorting ascending by line
          if (valA === 'B73' && direction === 'asc') return -1;
          if (valB === 'B73' && direction === 'asc') return 1;
        } else if (column === 'subpop') {
          valA = a.querySelector('.nam-badge') ? a.querySelector('.nam-badge').textContent.trim() : '';
          valB = b.querySelector('.nam-badge') ? b.querySelector('.nam-badge').textContent.trim() : '';
        } else if (column === 'grin') {
          valA = parseInt(a.getAttribute('data-grin'), 10) || 0;
          valB = parseInt(b.getAttribute('data-grin'), 10) || 0;
          return direction === 'asc' ? valA - valB : valB - valA;
        } else if (column === 'stock') {
          valA = parseInt(a.getAttribute('data-stock'), 10) || 0;
          valB = parseInt(b.getAttribute('data-stock'), 10) || 0;
          return direction === 'asc' ? valA - valB : valB - valA;
        } else if (column === 'ncbi') {
          valA = a.getAttribute('data-ncbi') || '';
          valB = b.getAttribute('data-ncbi') || '';
        }

        return direction === 'asc'
          ? valA.localeCompare(valB, undefined, { numeric: true, sensitivity: 'base' })
          : valB.localeCompare(valA, undefined, { numeric: true, sensitivity: 'base' });
      });

      rows.forEach(function (r) {
        tbody.appendChild(r);
      });
    }

    // 3. EVENT LISTENERS: SUBPOPULATION FILTER CHIPS
    chipButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        chipButtons.forEach(function (b) { b.classList.remove('is-active'); });
        btn.classList.add('is-active');
        currentSubpopFilter = btn.getAttribute('data-subpop-filter');
        applyFilters();
      });
    });

    // 4. EVENT LISTENERS: SEARCH INPUT
    if (searchInput) {
      searchInput.addEventListener('input', function (e) {
        currentSearchQuery = e.target.value;
        applyFilters();
      });
    }

    // 5. EVENT LISTENERS: SORT HEADERS
    sortHeaders.forEach(function (th) {
      th.addEventListener('click', function () {
        var col = th.getAttribute('data-sort');
        if (currentSortCol === col) {
          currentSortDir = (currentSortDir === 'asc') ? 'desc' : 'asc';
        } else {
          currentSortCol = col;
          currentSortDir = 'asc';
        }

        sortHeaders.forEach(function (h) {
          h.removeAttribute('aria-sort');
          var icon = h.querySelector('.sort-icon');
          if (icon) icon.innerHTML = '&updownarrow;';
        });

        th.setAttribute('aria-sort', currentSortDir === 'asc' ? 'ascending' : 'descending');
        var sortIcon = th.querySelector('.sort-icon');
        if (sortIcon) {
          sortIcon.innerHTML = currentSortDir === 'asc' ? '&uarr;' : '&darr;';
        }

        sortTable(currentSortCol, currentSortDir);
      });
    });

    initScrollSpy();
  }

  // 6. STICKY TABS SCROLLSPY
  function initScrollSpy() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length || !('IntersectionObserver' in window)) return;

    var sectionIds = [];
    tabs.forEach(function (tab) {
      var href = tab.getAttribute('href');
      if (href && href.charAt(0) === '#') {
        sectionIds.push(href.substring(1));
      }
    });

    var sections = sectionIds.map(function (id) {
      return document.getElementById(id);
    }).filter(Boolean);

    if (!sections.length) return;

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          var id = entry.target.id;
          tabs.forEach(function (tab) {
            if (tab.getAttribute('href') === '#' + id) {
              tab.classList.add('is-current');
              tab.setAttribute('aria-current', 'true');
            } else {
              tab.classList.remove('is-current');
              tab.removeAttribute('aria-current');
            }
          });
        }
      });
    }, {
      rootMargin: '-10% 0px -75% 0px',
      threshold: 0
    });

    sections.forEach(function (sec) {
      observer.observe(sec);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
