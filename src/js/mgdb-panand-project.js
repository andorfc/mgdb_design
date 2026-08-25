/* ==========================================================================
   Pan-Andropogoneae Genomes Project — /PanAnd_project
   Interactive table filtering, multi-column sorting, and scrollspy
   ========================================================================== */

(function () {
  'use strict';

  var currentFilter = 'all';
  var currentSearchQuery = '';
  var currentSortCol = 'assembly';
  var currentSortDir = 'asc';

  function init() {
    var table = document.getElementById('panand-data-table');
    if (!table) return;

    var tbody = table.querySelector('tbody');
    var rows = Array.from(tbody.querySelectorAll('tr'));
    var searchInput = document.getElementById('panand-search-input');
    var chipButtons = document.querySelectorAll('[data-filter]');
    var rowCountEl = document.getElementById('panand-row-count');
    var sortHeaders = table.querySelectorAll('th.is-sortable');

    // 1. FILTERING FUNCTION
    function applyFilters() {
      var visibleCount = 0;
      var q = currentSearchQuery.toLowerCase().trim();

      rows.forEach(function (row) {
        var clade = row.getAttribute('data-clade') || '';
        var round = row.getAttribute('data-round') || '';
        var assembly = (row.getAttribute('data-assembly') || '').toLowerCase();
        var species = (row.getAttribute('data-species') || '').toLowerCase();
        var annot = (row.getAttribute('data-annot') || '').toLowerCase();
        var textContent = row.textContent.toLowerCase();

        var matchesFilter = (
          currentFilter === 'all' ||
          clade === currentFilter ||
          round === currentFilter
        );

        var matchesSearch = (
          !q ||
          assembly.includes(q) ||
          species.includes(q) ||
          annot.includes(q) ||
          textContent.includes(q)
        );

        if (matchesFilter && matchesSearch) {
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

        if (column === 'assembly') {
          valA = a.getAttribute('data-assembly') || '';
          valB = b.getAttribute('data-assembly') || '';
        } else if (column === 'species') {
          valA = a.getAttribute('data-species') || '';
          valB = b.getAttribute('data-species') || '';
        } else if (column === 'clade') {
          valA = a.querySelector('.panand-badge') ? a.querySelector('.panand-badge').textContent.trim() : '';
          valB = b.querySelector('.panand-badge') ? b.querySelector('.panand-badge').textContent.trim() : '';
        } else if (column === 'annotation') {
          valA = a.getAttribute('data-annot') || '';
          valB = b.getAttribute('data-annot') || '';
        } else if (column === 'round') {
          valA = a.getAttribute('data-round') || '';
          valB = b.getAttribute('data-round') || '';
        }

        return direction === 'asc'
          ? valA.localeCompare(valB, undefined, { numeric: true, sensitivity: 'base' })
          : valB.localeCompare(valA, undefined, { numeric: true, sensitivity: 'base' });
      });

      rows.forEach(function (r) {
        tbody.appendChild(r);
      });
    }

    // 3. EVENT LISTENERS: FILTER CHIPS
    chipButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        chipButtons.forEach(function (b) { b.classList.remove('is-active'); });
        btn.classList.add('is-active');
        currentFilter = btn.getAttribute('data-filter');
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
