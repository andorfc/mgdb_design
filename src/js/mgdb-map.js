/**
 * Map Data Hub — Client Controller (/data_center/map)
 */

(function () {
  'use strict';

  var API_URL = '/search/map/map_search_api.php';

  var state = {
    term: '',
    locus: '',
    linkage: '0',
    source: '0',
    panel: '0',
    has_loci: 0,
    sort: 'relevance',
    view: 'table',
    page: 1,
    pageSize: 25,
    filter: '',
    searched: false,
    total: 0,
    loading: false
  };

  var debounceTimer = null;

  function byId(id) {
    return document.getElementById(id);
  }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  /* ── Sticky Section Tabs & Scrollspy ────────────────────────────────────── */

  /* Sticky section tabs, driven by scroll, IntersectionObserver and resize
     together: no single trigger fires everywhere, and the results section
     appears and disappears under the bar as searches run. The previous version
     used IntersectionObserver alone. */
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

    var results = byId('map-results-section');
    if (results && window.MutationObserver) {
      new window.MutationObserver(update).observe(results, {
        childList: true, subtree: true, attributes: true, attributeFilter: ['hidden']
      });
    }

    update();
  }

  /* ── Query Execution ────────────────────────────────────────────────────── */

  function updateClearBtn() {
    var clearBtn = byId('map-query-clear');
    var queryInput = byId('map-query');
    if (clearBtn && queryInput) {
      clearBtn.hidden = (queryInput.value.length === 0);
    }
  }

  function updateViewButtons() {
    var viewBtns = document.querySelectorAll('.map-view-btn');
    viewBtns.forEach(function (btn) {
      var isTarget = btn.getAttribute('data-view') === state.view;
      btn.classList.toggle('is-active', isTarget);
      btn.setAttribute('aria-pressed', isTarget ? 'true' : 'false');
    });
    var resultsEl = byId('map-results');
    if (resultsEl) {
      resultsEl.className = 'map-results-container map-view-' + state.view;
    }
  }

  function updateExportLinks() {
    var qs = new URLSearchParams();
    if (state.term) qs.set('term', state.term);
    if (state.locus) qs.set('locus', state.locus);
    if (state.linkage && state.linkage !== '0') qs.set('linkage', state.linkage);
    if (state.source && state.source !== '0') qs.set('source', state.source);
    if (state.panel && state.panel !== '0') qs.set('panel', state.panel);
    if (state.has_loci) qs.set('has_loci', state.has_loci);
    if (state.sort) qs.set('sort', state.sort);

    qs.set('format', 'tsv');
    var exportTsv = byId('map-export-tsv');
    if (exportTsv) exportTsv.href = API_URL + '?' + qs.toString();

    qs.set('format', 'csv');
    var exportCsv = byId('map-export-csv');
    if (exportCsv) exportCsv.href = API_URL + '?' + qs.toString();
  }

  function fetchMaps(scrollToResults) {
    if (state.loading) return;
    state.loading = true;
    updateExportLinks();

    var section = byId('map-results-section');
    if (section) { section.hidden = false; }
    state.searched = true;

    var statusEl = byId('map-results-status');
    if (statusEl) {
      statusEl.textContent = 'Searching curated maps…';
    }

    var qs = new URLSearchParams();
    if (state.term) qs.set('term', state.term);
    if (state.locus) qs.set('locus', state.locus);
    if (state.linkage && state.linkage !== '0') qs.set('linkage', state.linkage);
    if (state.source && state.source !== '0') qs.set('source', state.source);
    if (state.panel && state.panel !== '0') qs.set('panel', state.panel);
    if (state.has_loci) qs.set('has_loci', state.has_loci);
    if (state.sort) qs.set('sort', state.sort);
    qs.set('page', state.pageSize === 'all' ? 1 : state.page);
    /* The endpoint caps a page at 100, so "All results" means as many as it
       returns at once; updateStatus says so rather than truncating quietly. */
    qs.set('page_size', state.pageSize === 'all' ? 100 : state.pageSize);

    fetch(API_URL + '?' + qs.toString())
      .then(function (res) { return res.json(); })
      .then(function (data) {
        state.loading = false;
        if (!data || !data.ok) {
          showError('Failed to fetch maps.');
          return;
        }
        state.total = data.summary.total;
        window._lastMapResults = data.results || [];
        window._lastMapSummary = data.summary;
        renderResults(data.results);
        applyResultsFilter();
        renderPagination(data.summary);
        updateStatus(data.summary);

        if (scrollToResults) {
          var target = byId('map-results-section');
          if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      })
      .catch(function (err) {
        state.loading = false;
        console.error('Map search error', err);
        showError('Network error while searching maps.');
      });
  }

  function updateStatus(summary) {
    var statusEl = byId('map-results-status');
    if (!statusEl) return;
    if (summary.total === 0) {
      statusEl.textContent = 'No matching maps found.';
      return;
    }

    /* The table filter narrows the page in the browser, so once it is on the
       server's total no longer describes what is on screen. */
    if (state.filter) {
      var items = visibleItems();
      statusEl.textContent = items.shown === 0
        ? 'Nothing on this page matches the filter “' + state.filter + '”. '
          + summary.total.toLocaleString() + ' maps matched the search.'
        : 'Showing ' + items.shown.toLocaleString() + ' of the ' + items.total.toLocaleString()
          + ' maps on this page matching “' + state.filter + '”, out of '
          + summary.total.toLocaleString() + ' matched by the search.';
      return;
    }

    if (state.pageSize === 'all') {
      statusEl.textContent = summary.total > summary.page_size
        ? 'Showing the first ' + summary.page_size.toLocaleString() + ' of ' + summary.total.toLocaleString()
          + ' maps, which is as many as the search returns at once. (' + summary.elapsed_ms + ' ms)'
        : 'Showing all ' + summary.total.toLocaleString() + ' matching maps. (' + summary.elapsed_ms + ' ms)';
      return;
    }

    var start = (summary.page - 1) * summary.page_size + 1;
    var end = Math.min(summary.total, summary.page * summary.page_size);
    statusEl.textContent = 'Showing ' + start.toLocaleString() + '–' + end.toLocaleString() + ' of ' + summary.total.toLocaleString() + ' maps. (' + summary.elapsed_ms + ' ms)';
  }

  /* The filter narrows the page already rendered, in either view: the card
     grid and the table both hold one element per map. */
  function visibleItems() {
    var container = byId('map-results');
    if (!container) { return { shown: 0, total: 0 }; }
    var items = container.querySelectorAll('.map-card-item, tbody tr');
    var shown = 0;
    Array.prototype.forEach.call(items, function (item) { if (!item.hidden) { shown++; } });
    return { shown: shown, total: items.length };
  }

  function applyResultsFilter() {
    var container = byId('map-results');
    if (!container) { return; }

    var items = container.querySelectorAll('.map-card-item, tbody tr');
    var terms = state.filter.toLowerCase().split(/\s+/).filter(Boolean);

    Array.prototype.forEach.call(items, function (item) {
      if (!terms.length) { item.hidden = false; return; }
      var hay = (item.textContent || '').toLowerCase();
      var match = true;
      for (var i = 0; i < terms.length; i++) {
        if (hay.indexOf(terms[i]) === -1) { match = false; break; }
      }
      item.hidden = !match;
    });
  }

  function showError(msg) {
    var resultsEl = byId('map-results');
    if (resultsEl) {
      resultsEl.innerHTML = '<div class="mgdb-message mgdb-message-error"><div><strong>Error</strong><span>' + escapeHtml(msg) + '</span></div></div>';
    }
    var emptyEl = byId('map-empty');
    if (emptyEl) emptyEl.hidden = true;
    var paginationEl = byId('map-pagination');
    if (paginationEl) paginationEl.innerHTML = '';
  }

  function renderResults(results) {
    var resultsEl = byId('map-results');
    var emptyEl = byId('map-empty');
    var paginationEl = byId('map-pagination');

    if (!results || results.length === 0) {
      if (resultsEl) resultsEl.innerHTML = '';
      if (emptyEl) emptyEl.hidden = false;
      if (paginationEl) paginationEl.innerHTML = '';
      return;
    }

    if (emptyEl) emptyEl.hidden = true;

    if (state.view === 'table') {
      renderTableView(results);
    } else {
      renderCardView(results);
    }
  }

  function renderTableView(results) {
    var resultsEl = byId('map-results');
    if (!resultsEl) return;

    var html = '<div class="map-table-wrap">' +
      '<table class="map-results-table">' +
      '<thead><tr>' +
      '  <th>Map Name</th>' +
      '  <th>Linkage Group</th>' +
      '  <th>Units &amp; Span</th>' +
      '  <th>Mapped Loci</th>' +
      '  <th>Source / Author</th>' +
      '  <th>Actions</th>' +
      '</tr></thead><tbody>';

    results.forEach(function (r) {
      var spanStr = '—';
      if (r.min_coord !== null && r.max_coord !== null) {
        spanStr = r.min_coord.toFixed(1) + ' &ndash; ' + r.max_coord.toFixed(1) + ' ' + escapeHtml(r.coordinate_type);
      } else if (r.coordinate_type) {
        spanStr = escapeHtml(r.coordinate_type);
      }

      var memoSnippet = '';
      if (r.memo) {
        memoSnippet = '<p>' + escapeHtml(r.memo.length > 120 ? r.memo.substring(0, 117) + '…' : r.memo) + '</p>';
      }

      html += '<tr>' +
        '  <td class="map-name-cell">' +
        '    <strong><a href="' + escapeHtml(r.html) + '">' + escapeHtml(r.name) + '</a></strong>' +
        memoSnippet +
        '  </td>' +
        '  <td><span class="map-chr-pill">Chr ' + escapeHtml(r.linkage_group) + '</span></td>' +
        '  <td>' + spanStr + '</td>' +
        '  <td><span class="map-loci-badge">' + r.locus_count.toLocaleString() + ' loci</span></td>' +
        '  <td>' + (r.author_name ? escapeHtml(r.author_name) : '<span style="color:var(--mgdb-muted);">—</span>') + '</td>' +
        '  <td>' +
        '    <div class="map-row-actions">' +
        '      <a href="' + escapeHtml(r.html) + '">View Map &rarr;</a>' +
        '      <a href="/compare_maps?map1=' + r.id + '">Compare &nearr;</a>' +
        '    </div>' +
        '  </td>' +
        '</tr>';
    });

    html += '</tbody></table></div>';
    resultsEl.innerHTML = html;
  }

  function renderCardView(results) {
    var resultsEl = byId('map-results');
    if (!resultsEl) return;

    var html = '<div class="map-card-grid">';

    results.forEach(function (r) {
      var spanStr = (r.min_coord !== null && r.max_coord !== null)
        ? r.min_coord.toFixed(1) + '–' + r.max_coord.toFixed(1) + ' ' + escapeHtml(r.coordinate_type)
        : escapeHtml(r.coordinate_type || 'cM');

      html += '<article class="map-card-item">' +
        '  <div>' +
        '    <div class="map-card-meta">' +
        '      <span class="map-chr-pill">Chr ' + escapeHtml(r.linkage_group) + '</span>' +
        '      <span class="map-loci-badge">' + r.locus_count.toLocaleString() + ' loci</span>' +
        '    </div>' +
        '    <h3><a href="' + escapeHtml(r.html) + '">' + escapeHtml(r.name) + '</a></h3>' +
        '    <p>' + (r.memo ? escapeHtml(r.memo.length > 150 ? r.memo.substring(0, 147) + '…' : r.memo) : 'Curated chromosome map spanning ' + spanStr + '.') + '</p>' +
        '  </div>' +
        '  <div class="map-card-footer">' +
        '    <span style="font-size:var(--mgdb-text-xs);color:var(--mgdb-muted);">' + (r.author_name ? escapeHtml(r.author_name) : spanStr) + '</span>' +
        '    <a class="mgdb-button mgdb-button-quiet" href="' + escapeHtml(r.html) + '">View Map &rarr;</a>' +
        '  </div>' +
        '</article>';
    });

    html += '</div>';
    resultsEl.innerHTML = html;
  }

  function renderPagination(summary) {
    var paginationEl = byId('map-pagination');
    if (!paginationEl) return;

    if (state.pageSize === 'all' || summary.page_count <= 1) {
      paginationEl.innerHTML = '';
      return;
    }

    var totalPages = summary.page_count;
    var curPage = summary.page;
    var html = '';

    html += '<button class="map-page-btn" type="button" data-page="' + (curPage - 1) + '" ' + (curPage === 1 ? 'disabled' : '') + '>&larr; Prev</button>';

    var pages = [];
    pages.push(1);
    if (curPage > 3) pages.push('...');
    for (var p = Math.max(2, curPage - 1); p <= Math.min(totalPages - 1, curPage + 1); p++) {
      pages.push(p);
    }
    if (curPage < totalPages - 2) pages.push('...');
    if (totalPages > 1) pages.push(totalPages);

    pages.forEach(function (p) {
      if (p === '...') {
        html += '<span class="map-page-ellipsis">&hellip;</span>';
      } else {
        html += '<button class="map-page-btn ' + (p === curPage ? 'is-active' : '') + '" type="button" data-page="' + p + '">' + p + '</button>';
      }
    });

    html += '<button class="map-page-btn" type="button" data-page="' + (curPage + 1) + '" ' + (curPage === totalPages ? 'disabled' : '') + '>Next &rarr;</button>';

    paginationEl.innerHTML = html;

    paginationEl.querySelectorAll('button[data-page]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var page = parseInt(this.getAttribute('data-page'), 10);
        if (page && page !== state.page && page >= 1 && page <= totalPages) {
          state.page = page;
          fetchMaps(true);
        }
      });
    });
  }

  /* ── Plotly Horizontal Bar Chart: Top 10 Maps Combined Markers ──────────── */

  function initChart() {
    var chrChart = byId('map-chr-chart');
    if (!chrChart || typeof Plotly === 'undefined') return;

    var rawLabels = chrChart.getAttribute('data-labels');
    var rawValues = chrChart.getAttribute('data-values');
    if (!rawLabels || !rawValues) return;

    try {
      var labels = JSON.parse(rawLabels);
      var values = JSON.parse(rawValues);

      var data = [{
        type: 'bar',
        orientation: 'h',
        x: values,
        y: labels,
        marker: {
          color: '#235c37',
          line: { color: '#163d24', width: 1 }
        },
        text: values.map(function (v) { return v.toLocaleString() + ' markers'; }),
        textposition: 'auto',
        hovertemplate: '<b>%{y}</b><br>Total Mapped Markers: %{x:,}<extra></extra>'
      }];

      var layout = {
        /* Plotly needs to be told the height. With `responsive: true` and no
           height in the layout it autosized to 0 -- the container's 340px comes
           from CSS rather than from content, and nothing here filled it, so the
           plot-container computed to 0px and the figure drew nothing. The
           fallback text sitting on top of it was what made the panel look
           populated; with that removed the figure was simply empty.
           Plotly.Plots.resize() does not recover it, but an explicit height
           does. */
        height: chrChart.clientHeight || 340,
        margin: { t: 20, r: 30, b: 50, l: 200 },
        xaxis: {
          title: 'Total Mapped Markers (Chromosomes 1–10 combined)',
          gridcolor: '#e5e7eb',
          zeroline: false
        },
        yaxis: {
          automargin: true
        },
        paper_bgcolor: 'transparent',
        plot_bgcolor: 'transparent',
        font: { family: 'inherit', color: '#111827' }
      };

      var config = { responsive: true, displayModeBar: false };

      /* Plotly appends; it does not replace. The fallback div is meant to be
         "shown until Plotly renders, and left in place if it never loads", but
         nothing ever took it away, so "Loading top map marker breakdown..."
         stayed on top of the finished chart. It also filled the container's
         340px, which left Plotly's own plot-container measuring 0 tall.

         Cleared here rather than earlier: everything that can fail -- a missing
         Plotly, absent data attributes, a JSON parse error -- has already
         returned or thrown by this point, so the fallback still survives every
         case it exists for. */
      chrChart.innerHTML = '';

      Plotly.newPlot(chrChart, data, layout, config);
    } catch (e) {
      console.error('Error rendering top map marker chart', e);
    }
  }

  /* ── Initialization ─────────────────────────────────────────────────────── */

  function init() {
    // Read URL search params
    var params = new URLSearchParams(window.location.search);
    if (params.has('term')) state.term = params.get('term');
    if (params.has('locus')) state.locus = params.get('locus');
    if (params.has('linkage')) state.linkage = params.get('linkage');
    if (params.has('source')) state.source = params.get('source');
    if (params.has('panel')) state.panel = params.get('panel');
    if (params.has('has_loci')) state.has_loci = parseInt(params.get('has_loci'), 10) || 0;
    if (params.has('sort')) state.sort = params.get('sort');
    if (params.has('view')) state.view = params.get('view') === 'card' ? 'card' : 'table';
    if (params.has('page')) state.page = Math.max(1, parseInt(params.get('page'), 10) || 1);

    var queryInput = byId('map-query');
    var locusInput = byId('map-locus-filter');
    var linkageSelect = byId('map-linkage');
    var sourceSelect = byId('map-source-filter');
    var panelSelect = byId('map-panel-filter');
    var hasLociCheckbox = byId('map-has-loci');
    var sortSelect = byId('map-sort');
    var formEl = byId('map-search-form');
    var clearBtn = byId('map-query-clear');
    var resetBtn = byId('map-empty-reset');
    var advResetBtn = byId('map-adv-reset-btn');
    var advAccordion = byId('map-adv-accordion');

    if (queryInput) queryInput.value = state.term;
    if (locusInput) locusInput.value = state.locus;
    if (linkageSelect) linkageSelect.value = state.linkage;
    if (sourceSelect) sourceSelect.value = state.source;
    if (panelSelect) panelSelect.value = state.panel;
    if (hasLociCheckbox) hasLociCheckbox.checked = (state.has_loci === 1);
    if (sortSelect) sortSelect.value = state.sort;

    if ((state.locus || state.source !== '0' || state.panel !== '0') && advAccordion) {
      advAccordion.open = true;
    }

    updateClearBtn();
    updateViewButtons();

    // Form submit
    if (formEl) {
      formEl.addEventListener('submit', function (e) {
        e.preventDefault();
        state.term = queryInput ? queryInput.value.trim() : '';
        state.locus = locusInput ? locusInput.value.trim() : '';
        state.linkage = linkageSelect ? linkageSelect.value : '0';
        state.source = sourceSelect ? sourceSelect.value : '0';
        state.panel = panelSelect ? panelSelect.value : '0';
        state.has_loci = hasLociCheckbox && hasLociCheckbox.checked ? 1 : 0;
        state.page = 1;
        fetchMaps(true);
      });
    }

    // Live query typing with debounce
    if (queryInput) {
      queryInput.addEventListener('input', function () {
        updateClearBtn();
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
          // Before the first search, typing must not open the results section.
          if (!state.searched) { return; }
          state.term = queryInput.value.trim();
          state.page = 1;
          fetchMaps(false);
        }, 300);
      });
    }

    // Clear query button
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        if (queryInput) {
          queryInput.value = '';
          queryInput.focus();
        }
        updateClearBtn();
        state.term = '';
        state.page = 1;
        fetchMaps(false);
      });
    }

    // Dropdown filters
    if (linkageSelect) {
      linkageSelect.addEventListener('change', function () {
        state.linkage = this.value;
        state.page = 1;
        fetchMaps(true);
      });
    }

    if (hasLociCheckbox) {
      hasLociCheckbox.addEventListener('change', function () {
        state.has_loci = this.checked ? 1 : 0;
        state.page = 1;
        fetchMaps(true);
      });
    }

    if (sortSelect) {
      sortSelect.addEventListener('change', function () {
        state.sort = this.value;
        state.page = 1;
        fetchMaps(true);
      });
    }

    // Advanced search reset button
    if (advResetBtn) {
      advResetBtn.addEventListener('click', function () {
        if (locusInput) locusInput.value = '';
        if (sourceSelect) sourceSelect.value = '0';
        if (panelSelect) panelSelect.value = '0';
        state.locus = '';
        state.source = '0';
        state.panel = '0';
        state.page = 1;
        fetchMaps(true);
      });
    }

    // Reset button in empty state
    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        state.term = '';
        state.locus = '';
        state.linkage = '0';
        state.source = '0';
        state.panel = '0';
        state.has_loci = 0;
        state.sort = 'relevance';
        state.page = 1;
        if (queryInput) queryInput.value = '';
        if (locusInput) locusInput.value = '';
        if (linkageSelect) linkageSelect.value = '0';
        if (sourceSelect) sourceSelect.value = '0';
        if (panelSelect) panelSelect.value = '0';
        if (hasLociCheckbox) hasLociCheckbox.checked = false;
        if (sortSelect) sortSelect.value = 'relevance';
        updateClearBtn();
        fetchMaps(true);
      });
    }

    // Results per page
    var pageSizeSelect = byId('map-page-size');
    if (pageSizeSelect) {
      pageSizeSelect.addEventListener('change', function () {
        state.pageSize = this.value === 'all' ? 'all' : parseInt(this.value, 10) || 25;
        state.page = 1;
        if (state.searched) { fetchMaps(false); }
      });
    }

    // Filter within the rendered page
    var resultsFilter = byId('map-results-filter');
    if (resultsFilter) {
      resultsFilter.addEventListener('input', function () {
        state.filter = this.value.trim();
        applyResultsFilter();
        if (window._lastMapSummary) { updateStatus(window._lastMapSummary); }
      });
    }

    // View toggle buttons
    var viewBtns = document.querySelectorAll('.map-view-btn');
    viewBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var view = this.getAttribute('data-view');
        if (view && view !== state.view) {
          state.view = view;
          updateViewButtons();
          renderResults(window._lastMapResults || []);
          applyResultsFilter();
        }
      });
    });

    // Example search buttons
    var exampleBtns = document.querySelectorAll('[data-map-example]');
    exampleBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var term = this.getAttribute('data-map-example') || '';
        if (queryInput) queryInput.value = term;
        state.term = term;
        state.page = 1;
        updateClearBtn();
        fetchMaps(true);
      });
    });

    buildTabs();
    initChart();

    /* The page used to run a search on load, which put 25 maps and a 1.1 s
       request in front of a reader who had not asked for either. It runs now
       only when the URL carries a query -- a linked search, or an example
       followed from elsewhere. */
    if (state.term || state.locus || state.linkage !== '0' || state.source !== '0'
        || state.panel !== '0' || state.has_loci === 1) {
      fetchMaps(false);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
