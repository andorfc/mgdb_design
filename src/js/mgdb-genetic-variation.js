/* ==========================================================================
   /genetic_variation — the Genetic Variation data hub
   --------------------------------------------------------------------------
   Progressive enhancement over a page that is already complete. Both tables
   are rendered server-side, so with this file missing the page still shows
   every dataset and every project, every link still works, and the column
   sorting that mgdb-modern.js wires from `data-sortable` still works. All this
   adds is the search box and the filter chips above each table.

   Bauplan::includeScript() emits into <head>, so this runs while the document
   is still being parsed and nothing in the body exists yet. Everything is
   therefore deferred to DOMContentLoaded.
   ========================================================================== */

(function (window, document) {
  'use strict';

  function byId(id) { return document.getElementById(id); }

  function normalize(str) {
    return (str || '').toLowerCase().replace(/[\s\-_]+/g, ' ').trim();
  }

  /* ── State Management for Variant Builds ────────────────────────────────── */

  var datasetState = {
    query: '',
    resultsFilter: '',
    ref: '',
    het: '',
    indels: '',
    imputed: '',
    pageSize: 25,
    view: 'table',
    sortKey: 'name',
    sortDir: 'asc'
  };

  var originalDatasetRows = [];
  var originalDatasetCards = [];

  /* ── Section Tabs & Scrollspy ───────────────────────────────────────────── */

  function buildTabs() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length) { return; }

    var pairs = [];
    Array.prototype.forEach.call(tabs, function (tab) {
      var href = tab.getAttribute('href') || '';
      if (href.charAt(0) !== '#') { return; }
      var section = document.getElementById(href.slice(1));
      if (section) { pairs.push({ tab: tab, section: section }); }
    });
    if (!pairs.length) { return; }

    var heldUntilScroll = null;
    var heldAtY = 0;

    function mark(section) {
      pairs.forEach(function (pair) {
        var current = pair.section === section;
        pair.tab.classList.toggle('is-current', current);
        if (current) { pair.tab.setAttribute('aria-current', 'true'); }
        else { pair.tab.removeAttribute('aria-current'); }
      });
    }

    function triggerLine() {
      var bar = document.querySelector('.mgdb-section-tabs');
      var barHeight = bar ? bar.getBoundingClientRect().height : 0;
      var margin = parseFloat(window.getComputedStyle(pairs[0].section).scrollMarginTop) || 0;
      return Math.max(barHeight + 8, margin + 4);
    }

    function update() {
      if (heldUntilScroll) {
        if (Math.abs(window.scrollY - heldAtY) < 4) { return; }
        heldUntilScroll = null;
      }
      var line = triggerLine();
      var current = pairs[0];
      pairs.forEach(function (pair) {
        if (pair.section.hasAttribute('hidden')) { return; }
        if (pair.section.getBoundingClientRect().top <= line) { current = pair; }
      });
      if ((window.innerHeight + window.scrollY) >= (document.documentElement.scrollHeight - 2)) {
        current = pairs[pairs.length - 1];
      }
      if (current) { mark(current.section); }
    }

    pairs.forEach(function (pair) {
      pair.tab.addEventListener('click', function () {
        mark(pair.section);
        heldUntilScroll = pair.section;
        heldAtY = window.scrollY;
      });
    });

    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);

    if (window.IntersectionObserver) {
      var observer = new window.IntersectionObserver(function () { update(); },
        { rootMargin: '-20% 0px -60% 0px' });
      pairs.forEach(function (pair) { observer.observe(pair.section); });
    }

    update();
  }

  /* ── Variant Dataset Filtering & Sorting ────────────────────────────────── */

  function parseRowData(row) {
    var search = row.getAttribute('data-search') || '';
    var filter = row.getAttribute('data-filter') || '';
    var nameEl = row.querySelector('.gv-dataset-name');
    var buildEl = row.querySelector('.gv-dataset-build');
    var refCell = row.cells && row.cells[1] ? row.cells[1].textContent.trim() : '';
    var accCell = row.cells && row.cells[2] ? parseInt(row.cells[2].getAttribute('data-value'), 10) || 0 : 0;
    var sitesCell = row.cells && row.cells[3] ? parseInt(row.cells[3].getAttribute('data-value'), 10) || 0 : 0;

    var hasHet = filter.indexOf('het') !== -1 || (row.cells && row.cells[5] && row.cells[5].querySelector('.gv-flag-yes') !== null);
    var hasIndels = filter.indexOf('indels') !== -1 || (row.cells && row.cells[6] && row.cells[6].querySelector('.gv-flag-yes') !== null);
    var isImputed = filter.indexOf('imputed') !== -1 || (row.cells && row.cells[7] && row.cells[7].querySelector('.gv-flag-yes') !== null);

    return {
      el: row,
      search: normalize(search),
      name: nameEl ? nameEl.textContent.trim() : '',
      build: buildEl ? buildEl.textContent.trim() : '',
      ref: refCell,
      accessions: accCell,
      sites: sitesCell,
      hasHet: hasHet,
      hasIndels: hasIndels,
      isImputed: isImputed
    };
  }

  function matchesCriteria(item) {
    // 1. Primary query
    if (datasetState.query) {
      var q = normalize(datasetState.query);
      if (item.search.indexOf(q) === -1) { return false; }
    }

    // 2. Results filter
    if (datasetState.resultsFilter) {
      var rf = normalize(datasetState.resultsFilter);
      if (item.search.indexOf(rf) === -1) { return false; }
    }

    // 3. Advanced options
    if (datasetState.ref) {
      if (datasetState.ref === 'B73 v5' && item.ref !== 'B73 v5') { return false; }
      if (datasetState.ref === 'historical' && item.ref === 'B73 v5') { return false; }
    }

    if (datasetState.het === 'yes' && !item.hasHet) { return false; }

    if (datasetState.indels === 'yes' && !item.hasIndels) { return false; }
    if (datasetState.indels === 'no' && item.hasIndels) { return false; }

    if (datasetState.imputed === 'yes' && !item.isImputed) { return false; }
    if (datasetState.imputed === 'no' && item.isImputed) { return false; }

    return true;
  }

  function sortDatasetItems(items) {
    var key = datasetState.sortKey || 'name';
    var dir = datasetState.sortDir === 'desc' ? -1 : 1;

    return items.slice().sort(function (a, b) {
      if (key === 'accessions') {
        if (a.accessions !== b.accessions) {
          return (a.accessions - b.accessions) * dir;
        }
      } else if (key === 'sites') {
        if (a.sites !== b.sites) {
          return (a.sites - b.sites) * dir;
        }
      } else if (key === 'ref') {
        var cmpRef = a.ref.localeCompare(b.ref);
        if (cmpRef !== 0) { return cmpRef * dir; }
      } else if (key === 'het') {
        if (a.hasHet !== b.hasHet) {
          return (a.hasHet ? 1 : -1) * dir;
        }
      } else if (key === 'indels') {
        if (a.hasIndels !== b.hasIndels) {
          return (a.hasIndels ? 1 : -1) * dir;
        }
      } else if (key === 'imputed') {
        if (a.isImputed !== b.isImputed) {
          return (a.isImputed ? 1 : -1) * dir;
        }
      }
      var nameA = (a.name + ' ' + a.build).toLowerCase();
      var nameB = (b.name + ' ' + b.build).toLowerCase();
      return nameA.localeCompare(nameB, undefined, { numeric: true }) * dir;
    });
  }

  function applyDatasetFiltering() {
    var tbody = byId('gv-dataset-rows');
    var cardsContainer = byId('gv-cards-view');
    var emptyEl = byId('gv-dataset-empty');
    var filterCountEl = byId('gv-filter-count');
    var tableView = byId('gv-table-view');

    if (!originalDatasetRows.length) { return; }

    // Match criteria across all items
    var matched = [];
    originalDatasetRows.forEach(function (item) {
      if (matchesCriteria(item)) {
        matched.push(item);
      }
    });

    // Sort matched items
    var sorted = sortDatasetItems(matched);

    // Apply page size
    var limit = (datasetState.pageSize === 'all' || !datasetState.pageSize)
      ? sorted.length
      : parseInt(datasetState.pageSize, 10);
    var visible = sorted.slice(0, limit);

    // Render Table Rows
    if (tbody) {
      tbody.innerHTML = '';
      visible.forEach(function (item) {
        tbody.appendChild(item.el);
      });
    }

    // Render Cards
    if (cardsContainer && originalDatasetCards.length) {
      cardsContainer.innerHTML = '';
      visible.forEach(function (item) {
        for (var i = 0; i < originalDatasetCards.length; i++) {
          var card = originalDatasetCards[i];
          var titleEl = card.querySelector('.gv-card-title');
          var cardTitle = titleEl ? titleEl.textContent.trim() : '';
          if (cardTitle === item.name) {
            cardsContainer.appendChild(card);
            break;
          }
        }
      });
    }

    // Empty state
    var totalMatched = matched.length;
    var totalAll = originalDatasetRows.length;
    if (emptyEl) {
      emptyEl.hidden = (totalMatched > 0);
    }
    if (tableView) {
      tableView.hidden = (datasetState.view === 'card') || (totalMatched === 0);
    }
    if (cardsContainer) {
      cardsContainer.hidden = (datasetState.view !== 'card') || (totalMatched === 0);
    }

    // Filter count badge
    if (filterCountEl) {
      if (totalMatched === totalAll && !datasetState.query && !datasetState.resultsFilter) {
        filterCountEl.textContent = totalAll + ' builds';
      } else {
        filterCountEl.textContent = totalMatched + ' of ' + totalAll + ' builds';
      }
    }

    updateAdvancedCount();
    updateSortHeaders();
    updateExportLink(matched);
  }

  function updateAdvancedCount() {
    var countEl = byId('gv-dataset-adv-count');
    if (!countEl) { return; }

    var count = 0;
    if (datasetState.ref) { count++; }
    if (datasetState.het) { count++; }
    if (datasetState.indels) { count++; }
    if (datasetState.imputed) { count++; }

    countEl.textContent = count;
    countEl.hidden = (count === 0);
  }

  function updateSortHeaders() {
    var headers = document.querySelectorAll('#gv-table-view thead th[aria-sort]');
    Array.prototype.forEach.call(headers, function (th) {
      var btn = th.querySelector('button[data-sort-key]');
      if (!btn) { return; }
      var key = btn.getAttribute('data-sort-key');
      if (datasetState.sortKey === key) {
        th.setAttribute('aria-sort', datasetState.sortDir === 'desc' ? 'descending' : 'ascending');
      } else {
        th.setAttribute('aria-sort', 'none');
      }
    });
  }

  function updateExportLink(items) {
    var exportBtn = byId('gv-export-tsv');
    if (!exportBtn) { return; }

    var rows = [
      ['Dataset', 'Build', 'Reference', 'Accessions', 'Variant sites', 'Het sites', 'Indels', 'Imputation'].join('\t')
    ];

    items.forEach(function (item) {
      rows.push([
        item.name,
        item.build,
        item.ref,
        item.accessions,
        item.sites,
        item.hasHet ? 'Yes' : 'No',
        item.hasIndels ? 'Yes' : 'No',
        item.isImputed ? 'Yes' : 'No'
      ].join('\t'));
    });

    var blob = new Blob([rows.join('\n')], { type: 'text/tab-separated-values;charset=utf-8;' });
    exportBtn.href = URL.createObjectURL(blob);
  }

  function initDatasetsSection() {
    var tbody = byId('gv-dataset-rows');
    if (tbody) {
      var trs = tbody.querySelectorAll('tr');
      Array.prototype.forEach.call(trs, function (tr) {
        originalDatasetRows.push(parseRowData(tr));
      });
    }

    var cardsContainer = byId('gv-cards-view');
    if (cardsContainer) {
      var articles = cardsContainer.querySelectorAll('.gv-dataset-card');
      Array.prototype.forEach.call(articles, function (art) {
        originalDatasetCards.push(art);
      });
    }

    // Main search input
    var queryInput = byId('gv-dataset-query');
    var queryClear = byId('gv-dataset-query-clear');

    if (queryInput) {
      queryInput.addEventListener('input', function () {
        datasetState.query = queryInput.value.trim();
        if (queryClear) {
          queryClear.hidden = (datasetState.query === '');
        }
        applyDatasetFiltering();
      });
    }

    if (queryClear && queryInput) {
      queryClear.addEventListener('click', function () {
        queryInput.value = '';
        datasetState.query = '';
        queryClear.hidden = true;
        queryInput.focus();
        applyDatasetFiltering();
      });
    }

    // Example buttons
    var exampleBtns = document.querySelectorAll('#gv-datasets .gv-example-btn[data-gv-example]');
    Array.prototype.forEach.call(exampleBtns, function (btn) {
      btn.addEventListener('click', function () {
        var ex = btn.getAttribute('data-gv-example');
        if (queryInput) {
          queryInput.value = ex;
          datasetState.query = ex;
          if (queryClear) { queryClear.hidden = false; }
          applyDatasetFiltering();
        }
      });
    });

    // Advanced search controls
    var refSelect = byId('gv-filter-ref');
    var hetSelect = byId('gv-filter-het');
    var indelsSelect = byId('gv-filter-indels');
    var imputedSelect = byId('gv-filter-imputed');
    var advReset = byId('gv-dataset-adv-reset');
    var advApply = byId('gv-dataset-adv-apply');

    function readAdv() {
      if (refSelect) datasetState.ref = refSelect.value;
      if (hetSelect) datasetState.het = hetSelect.value;
      if (indelsSelect) datasetState.indels = indelsSelect.value;
      if (imputedSelect) datasetState.imputed = imputedSelect.value;
    }

    [refSelect, hetSelect, indelsSelect, imputedSelect].forEach(function (sel) {
      if (sel) {
        sel.addEventListener('change', function () {
          readAdv();
          applyDatasetFiltering();
        });
      }
    });

    if (advApply) {
      advApply.addEventListener('click', function () {
        readAdv();
        applyDatasetFiltering();
      });
    }

    if (advReset) {
      advReset.addEventListener('click', function () {
        if (refSelect) refSelect.value = '';
        if (hetSelect) hetSelect.value = '';
        if (indelsSelect) indelsSelect.value = '';
        if (imputedSelect) imputedSelect.value = '';
        readAdv();
        applyDatasetFiltering();
      });
    }

    // Results filter
    var resultsFilter = byId('gv-results-filter');
    if (resultsFilter) {
      resultsFilter.addEventListener('input', function () {
        datasetState.resultsFilter = resultsFilter.value.trim();
        applyDatasetFiltering();
      });
    }

    // Page size
    var pageSizeSelect = byId('gv-page-size');
    if (pageSizeSelect) {
      pageSizeSelect.addEventListener('change', function () {
        datasetState.pageSize = pageSizeSelect.value;
        applyDatasetFiltering();
      });
    }

    // View toggle
    var viewCardsBtn = byId('gv-view-cards');
    var viewTableBtn = byId('gv-view-table');

    function setView(view) {
      datasetState.view = view;
      var isCards = view === 'card';
      if (viewCardsBtn) {
        viewCardsBtn.classList.toggle('is-active', isCards);
        viewCardsBtn.setAttribute('aria-pressed', isCards ? 'true' : 'false');
      }
      if (viewTableBtn) {
        viewTableBtn.classList.toggle('is-active', !isCards);
        viewTableBtn.setAttribute('aria-pressed', !isCards ? 'true' : 'false');
      }
      applyDatasetFiltering();
    }

    if (viewCardsBtn) {
      viewCardsBtn.addEventListener('click', function () { setView('card'); });
    }
    if (viewTableBtn) {
      viewTableBtn.addEventListener('click', function () { setView('table'); });
    }

    // Column sorting
    var sortBtns = document.querySelectorAll('#gv-table-view thead button[data-sort-key]');
    Array.prototype.forEach.call(sortBtns, function (btn) {
      btn.addEventListener('click', function () {
        var key = btn.getAttribute('data-sort-key');
        if (datasetState.sortKey === key) {
          datasetState.sortDir = (datasetState.sortDir === 'asc') ? 'desc' : 'asc';
        } else {
          datasetState.sortKey = key;
          datasetState.sortDir = (key === 'accessions' || key === 'sites') ? 'desc' : 'asc';
        }
        applyDatasetFiltering();
      });
    });

    applyDatasetFiltering();
  }

  /* ── Resequencing Projects Section ──────────────────────────────────────── */

  function initProjectsSection() {
    var queryInput = byId('gv-project-query');
    var queryClear = byId('gv-project-query-clear');
    var tbody = byId('gv-project-rows');
    var emptyEl = byId('gv-project-empty');
    var countEl = byId('gv-project-count');
    var chips = document.querySelectorAll('#gv-projects .mgdb-filters .mgdb-chip');
    var resetBtn = byId('gv-project-reset');

    if (!tbody) { return; }
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    if (!rows.length) { return; }

    var filter = 'all';
    var query = '';

    function apply() {
      var q = normalize(query);
      var visible = 0;

      rows.forEach(function (row) {
        var search = normalize(row.getAttribute('data-search') || '');
        var rowFilter = row.getAttribute('data-filter') || '';

        var matchesQuery = !q || search.indexOf(q) !== -1;
        var matchesFilter = (filter === 'all') || (rowFilter === filter);

        var show = matchesQuery && matchesFilter;
        row.hidden = !show;
        if (show) { visible++; }
      });

      if (countEl) {
        countEl.textContent = (visible === rows.length)
          ? rows.length + ' projects shown'
          : visible + ' of ' + rows.length + ' projects shown';
      }

      if (emptyEl) {
        emptyEl.hidden = (visible > 0);
      }

      if (resetBtn) {
        resetBtn.hidden = (!q && filter === 'all');
      }

      chips.forEach(function (chip) {
        chip.setAttribute('aria-pressed', chip.getAttribute('data-filter') === filter ? 'true' : 'false');
      });
    }

    if (queryInput) {
      queryInput.addEventListener('input', function () {
        query = queryInput.value.trim();
        if (queryClear) {
          queryClear.hidden = (query === '');
        }
        apply();
      });
    }

    if (queryClear && queryInput) {
      queryClear.addEventListener('click', function () {
        queryInput.value = '';
        query = '';
        queryClear.hidden = true;
        queryInput.focus();
        apply();
      });
    }

    chips.forEach(function (chip) {
      chip.addEventListener('click', function () {
        filter = chip.getAttribute('data-filter') || 'all';
        apply();
      });
    });

    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        if (queryInput) { queryInput.value = ''; }
        query = '';
        filter = 'all';
        if (queryClear) { queryClear.hidden = true; }
        apply();
      });
    }

    apply();
  }

  /* ── Initialization ─────────────────────────────────────────────────────── */

  function init() {
    buildTabs();
    initDatasetsSection();
    initProjectsSection();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}(window, document));
