/* ==========================================================================
   Stock Data Hub (/data_center/stock)
   Client-side search, faceted filtering, Plotly chart, pagination, and view switching.
   ========================================================================== */

(function () {
  'use strict';

  var API_URL = '/search/stock/stock_search_api.php';
  /* The endpoint caps page_size at 100, so "All results" asks for that. */
  var MAX_PAGE = 100;

  var state = {
    term: '',
    type: 0,
    available: 0,
    linkage: 0,
    phenotype: 0,
    karyotype: 0,
    f_mgsc: '',
    f_bank: '',
    f_expvp: '',
    source: 'mgdb',
    view: 'table',
    sort: 'relevance',
    page: 1,
    pageSize: 25,
    filter: '',
    searched: false,
    lastData: null,
    totalPages: 1,
    totalRecords: 0,
    grinTotal: 0,
    loading: false
  };

  var debounceTimer = null;

  function byId(id) {
    return document.getElementById(id);
  }

  function escapeHtml(str) {
    if (!str) return '';
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
     appears and disappears under the bar as searches run. */
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

    var results = byId('stock-results-section');
    if (results && window.MutationObserver) {
      new window.MutationObserver(update).observe(results, {
        childList: true, subtree: true, attributes: true, attributeFilter: ['hidden']
      });
    }

    update();
  }

  /* ── Query Execution ────────────────────────────────────────────────────── */

  function fetchResults(scrollToResults) {
    if (state.loading) return;
    state.loading = true;

    var statusEl = byId('stock-results-status');
    if (statusEl) {
      statusEl.textContent = 'Searching stock records…';
    }

    /* The endpoint reads `mode`, and gates every advanced filter behind an
       `f_<name>` flag as well as its value. This was sending `source=grin`
       for the GRIN toggle -- which the endpoint does not read, so the toggle
       returned MaizeGDB stocks -- and the five filter values with no `mode`
       and no flags, so a filter-only search answered "no-term" and found
       nothing. Both are the contract in search/stock/stock_search_lib.php. */
    var params = new URLSearchParams();

    var advanced = [
      ['type', state.type],
      ['available', state.available],
      ['linkage', state.linkage],
      ['phenotype', state.phenotype],
      ['karyotype', state.karyotype]
    ].filter(function (pair) { return pair[1]; });
    var flags = [
      ['f_mgsc', state.f_mgsc],
      ['f_bank', state.f_bank],
      ['f_expvp', state.f_expvp]
    ].filter(function (pair) { return pair[1]; });

    if (state.source === 'grin') {
      params.set('mode', 'grin');
      params.set('term', state.term);
    } else if (state.term === '' && (advanced.length || flags.length)) {
      // No term, but something to filter on: that is the advanced search.
      params.set('mode', 'advanced');
      advanced.forEach(function (pair) {
        params.set('f_' + pair[0], '1');
        params.set(pair[0], pair[1]);
      });
      flags.forEach(function (pair) { params.set(pair[0], '1'); });
    } else {
      params.set('mode', 'simple');
      params.set('term', state.term);
    }

    params.set('sort', state.sort);
    params.set('page', state.page);
    params.set('page_size', state.pageSize === 'all' ? MAX_PAGE : state.pageSize);

    fetch(API_URL + '?' + params.toString())
      .then(function (response) {
        if (!response.ok) throw new Error('Network error');
        return response.json();
      })
      .then(function (data) {
        state.loading = false;
        if (!data) {
          renderError();
          return;
        }

        /* The results section is hidden until there is something to show. */
        var section = byId('stock-results-section');
        if (section) { section.hidden = false; }
        state.searched = true;

        /* summary.total, not data.total: the endpoint has never returned a
           top-level total, so this read undefined and every search reported
           "of 0 stocks" with a single page of pagination -- no way to reach
           result 26 of 7,841. */
        var summary = data.summary || {};
        state.totalRecords = summary.total || 0;
        state.grinTotal = data.grin_total || 0;
        var size = summary.page_size || (state.pageSize === 'all' ? MAX_PAGE : state.pageSize);
        state.totalPages = summary.page_count || (Math.ceil(state.totalRecords / size) || 1);

        updateSourceBadges();
        renderResults(data);
        renderPagination();
        updateExportLinks();
        syncUrlParams();

        if (scrollToResults) {
          var resultsSec = byId('stock-results-section');
          if (resultsSec) {
            resultsSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }
      })
      .catch(function (error) {
        state.loading = false;
        renderError();
      });
  }

  function updateSourceBadges() {
    var sourceBox = byId('stock-sources');
    var mgdbBtn = byId('stock-source-mgdb');
    var grinBtn = byId('stock-source-grin');

    if (sourceBox) {
      sourceBox.hidden = !(state.totalRecords > 0 || state.grinTotal > 0 || state.term !== '');
    }

    if (mgdbBtn) {
      var mgdbCount = state.source === 'mgdb' ? state.totalRecords : '';
      mgdbBtn.textContent = 'MaizeGDB stocks' + (mgdbCount !== '' ? ' (' + Number(mgdbCount).toLocaleString() + ')' : '');
      var isMgdb = state.source === 'mgdb';
      mgdbBtn.classList.toggle('is-active', isMgdb);
      mgdbBtn.setAttribute('aria-pressed', isMgdb ? 'true' : 'false');
    }

    if (grinBtn) {
      var grinCount = state.source === 'grin' ? state.totalRecords : state.grinTotal;
      grinBtn.textContent = 'GRIN accessions' + (grinCount > 0 ? ' (' + Number(grinCount).toLocaleString() + ')' : '');
      var isGrin = state.source === 'grin';
      grinBtn.classList.toggle('is-active', isGrin);
      grinBtn.setAttribute('aria-pressed', isGrin ? 'true' : 'false');
    }
  }

  /* ── Rendering ──────────────────────────────────────────────────────────── */

  function renderResults(data) {
    var container = byId('stock-results');
    var emptyEl = byId('stock-empty');
    var statusEl = byId('stock-results-status');
    var rows = data.results || [];

    if (!rows.length) {
      if (container) container.innerHTML = '';
      if (emptyEl) emptyEl.hidden = false;
      if (statusEl) {
        statusEl.textContent = 'No stocks matched your query.';
      }
      return;
    }

    if (emptyEl) emptyEl.hidden = true;

    var size = state.pageSize === 'all' ? MAX_PAGE : state.pageSize;
    var start = (state.page - 1) * size + 1;
    var end = Math.min(start + rows.length - 1, state.totalRecords);
    var noun = state.source === 'grin' ? 'GRIN accessions' : 'stocks';
    if (statusEl) {
      if (state.pageSize === 'all' && state.totalRecords > rows.length) {
        /* "All results" is capped by the endpoint, and saying so is better
           than a count that quietly stops short. */
        statusEl.textContent = 'Showing the first ' + rows.length.toLocaleString() + ' of '
          + state.totalRecords.toLocaleString() + ' ' + noun
          + ', which is as many as the search returns at once.';
      } else {
        statusEl.textContent = 'Showing ' + start.toLocaleString() + '–' + end.toLocaleString()
          + ' of ' + state.totalRecords.toLocaleString() + ' ' + noun + '.';
      }
    }

    if (state.view === 'card') {
      container.className = 'stock-results-container stock-view-card';
      container.innerHTML = rows.map(renderCard).join('');
    } else {
      container.className = 'stock-results-container stock-view-table';
      container.innerHTML = renderTable(rows);
    }

    state.lastData = data;
    /* Re-applied last so paging and the card/table toggle do not silently drop
       a filter the box still shows. */
    applyResultsFilter();
  }

  /* Narrows the page already rendered, in both views. The search pages server
     side, so this filters what is on screen and the status line says so. */
  function applyResultsFilter() {
    var container = byId('stock-results');
    if (!container) { return; }

    var items = container.querySelectorAll('.stock-result-card, tbody tr');
    var terms = state.filter.toLowerCase().split(/\s+/).filter(Boolean);
    var shown = 0;

    Array.prototype.forEach.call(items, function (item) {
      var match = true;
      if (terms.length) {
        var hay = (item.textContent || '').toLowerCase();
        for (var i = 0; i < terms.length; i++) {
          if (hay.indexOf(terms[i]) === -1) { match = false; break; }
        }
      }
      item.hidden = !match;
      if (match) { shown++; }
    });

    if (terms.length) {
      var statusEl = byId('stock-results-status');
      var noun = state.source === 'grin' ? 'GRIN accessions' : 'stocks';
      if (statusEl) {
        statusEl.textContent = shown === 0
          ? 'Nothing on this page matches the filter “' + state.filter + '”. '
            + state.totalRecords.toLocaleString() + ' ' + noun + ' matched the search.'
          : 'Showing ' + shown.toLocaleString() + ' of the ' + items.length.toLocaleString()
            + ' ' + noun + ' on this page matching “' + state.filter + '”, out of '
            + state.totalRecords.toLocaleString() + ' matched by the search.';
      }
    }
  }

  function renderCard(row) {
    var typeBadge = row.type ? '<span class="mgdb-pill mgdb-pill-ok">' + escapeHtml(row.type) + '</span>' : '';
    var providerBadge = row.provider ? '<span class="mgdb-pill mgdb-pill-info">' + escapeHtml(row.provider) + '</span>' : '';
    var statusBadge = row.status && row.status !== 'available'
      ? '<span class="mgdb-pill mgdb-pill-warn">' + escapeHtml(row.status) + '</span>'
      : '';

    /* A GRIN row carries grin_id, not id -- the endpoint's GRIN shape is
       name/accession/grin_id/improvement/genus/origin. This read row.id and
       produced ?id=undefined, which was invisible while the GRIN toggle was
       sending a parameter the endpoint did not read and so never returned a
       GRIN row at all. */
    var linkUrl = state.source === 'grin'
      ? 'https://npgsweb.ars-grin.gov/gringlobal/accessiondetail.aspx?id=' + encodeURIComponent(row.grin_id || '')
      : '/data_center/stock?id=' + encodeURIComponent(row.id || row.name);

    var metaItems = [];
    if (row.type) metaItems.push('<dt>Type</dt><dd>' + escapeHtml(row.type) + '</dd>');
    if (row.provider) metaItems.push('<dt>Provider</dt><dd>' + escapeHtml(row.provider) + '</dd>');
    if (row.linkage_group) metaItems.push('<dt>Focus Linkage</dt><dd>' + escapeHtml(row.linkage_group) + '</dd>');
    if (row.synonyms && row.synonyms.length) metaItems.push('<dt>Synonyms</dt><dd>' + escapeHtml(row.synonyms.join(', ')) + '</dd>');

    var metaHtml = metaItems.length ? '<dl class="stock-card-meta">' + metaItems.join('') + '</dl>' : '';

    var descHtml = '';
    if (row.comments && row.comments.length) {
      var firstComment = row.comments[0];
      descHtml = '<p class="stock-card-desc"><strong>' + escapeHtml(firstComment.label || 'Description') + ':</strong> ' + firstComment.text + '</p>';
    }

    var orderLink = '';
    if (row.provider && row.provider.indexOf('Stock Center') !== -1) {
      orderLink = '<a href="https://maizecoopsc.org/" target="_blank" rel="noopener">Order seed &nearr;</a>';
    }

    return '<article class="stock-card" data-stock-id="' + escapeHtml(row.id || row.grin_id || '') + '">' +
      '<div>' +
        '<div class="stock-card-header">' +
          '<h3><a href="' + linkUrl + '">' + escapeHtml(row.name) + '</a></h3>' +
          '<div style="display:flex;gap:4px;flex-wrap:wrap;">' + typeBadge + providerBadge + statusBadge + '</div>' +
        '</div>' +
        metaHtml +
        descHtml +
      '</div>' +
      '<div class="stock-card-actions">' +
        '<a href="' + linkUrl + '">Stock record &rarr;</a>' +
        orderLink +
      '</div>' +
    '</article>';
  }

  function renderTable(rows) {
    var thead = '<thead><tr>' +
      '<th>Stock Identifier</th>' +
      '<th>Type</th>' +
      '<th>Available From</th>' +
      '<th>Focus Linkage</th>' +
      '<th>Synonyms &amp; Description</th>' +
      '<th>Actions</th>' +
    '</tr></thead>';

    var tbody = rows.map(function (row) {
      var linkUrl = state.source === 'grin'
        ? 'https://npgsweb.ars-grin.gov/gringlobal/accessiondetail.aspx?id=' + encodeURIComponent(row.grin_id || '')
        : '/data_center/stock?id=' + encodeURIComponent(row.id || row.name);

      var nameCell = '<strong><a href="' + linkUrl + '">' + escapeHtml(row.name) + '</a></strong>';
      var typeCell = row.type ? '<span class="mgdb-pill mgdb-pill-ok">' + escapeHtml(row.type) + '</span>' : '<span class="mgdb-muted">—</span>';
      var provCell = row.provider ? escapeHtml(row.provider) : '<span class="mgdb-muted">—</span>';
      var linkCell = row.linkage_group ? escapeHtml(row.linkage_group) : '<span class="mgdb-muted">—</span>';

      var descText = '';
      if (row.synonyms && row.synonyms.length) {
        descText += '<em>Synonyms: ' + escapeHtml(row.synonyms.join(', ')) + '</em>. ';
      }
      if (row.comments && row.comments.length) {
        descText += row.comments[0].text;
      }
      var descCell = descText ? '<small>' + descText + '</small>' : '<span class="mgdb-muted">—</span>';

      var actionCell = '<a href="' + linkUrl + '">Record &rarr;</a>';
      if (row.provider && row.provider.indexOf('Stock Center') !== -1) {
        actionCell += ' · <a href="https://maizecoopsc.org/" target="_blank" rel="noopener">Order &nearr;</a>';
      }

      return '<tr>' +
        '<td>' + nameCell + '</td>' +
        '<td>' + typeCell + '</td>' +
        '<td>' + provCell + '</td>' +
        '<td>' + linkCell + '</td>' +
        '<td>' + descCell + '</td>' +
        '<td>' + actionCell + '</td>' +
      '</tr>';
    }).join('');

    return '<table class="stock-table">' + thead + '<tbody>' + tbody + '</tbody></table>';
  }

  function renderPagination() {
    var nav = byId('stock-pagination');
    if (!nav) return;

    if (state.totalPages <= 1) {
      nav.innerHTML = '';
      nav.hidden = true;
      return;
    }

    nav.hidden = false;
    var html = '';
    html += '<button type="button" data-page="' + (state.page - 1) + '" ' + (state.page <= 1 ? 'disabled' : '') + '>&lsaquo; Prev</button>';

    var maxPagesToShow = 7;
    var startPage = Math.max(1, state.page - 3);
    var endPage = Math.min(state.totalPages, startPage + maxPagesToShow - 1);
    if (endPage - startPage < maxPagesToShow - 1) {
      startPage = Math.max(1, endPage - maxPagesToShow + 1);
    }

    if (startPage > 1) {
      html += '<button type="button" data-page="1">1</button>';
      if (startPage > 2) html += '<span class="mgdb-muted" style="padding:0 4px">…</span>';
    }

    for (var p = startPage; p <= endPage; p++) {
      var isCurrent = p === state.page;
      html += '<button type="button" data-page="' + p + '" ' + (isCurrent ? 'aria-current="page"' : '') + '>' + p + '</button>';
    }

    if (endPage < state.totalPages) {
      if (endPage < state.totalPages - 1) html += '<span class="mgdb-muted" style="padding:0 4px">…</span>';
      html += '<button type="button" data-page="' + state.totalPages + '">' + state.totalPages + '</button>';
    }

    html += '<button type="button" data-page="' + (state.page + 1) + '" ' + (state.page >= state.totalPages ? 'disabled' : '') + '>Next &rsaquo;</button>';
    nav.innerHTML = html;

    Array.prototype.forEach.call(nav.querySelectorAll('button[data-page]'), function (btn) {
      btn.addEventListener('click', function () {
        var targetPage = parseInt(btn.getAttribute('data-page'), 10);
        if (targetPage && targetPage >= 1 && targetPage <= state.totalPages && targetPage !== state.page) {
          state.page = targetPage;
          fetchResults(true);
        }
      });
    });
  }

  function renderError() {
    var container = byId('stock-results');
    var statusEl = byId('stock-results-status');
    if (container) container.innerHTML = '';
    if (statusEl) {
      statusEl.textContent = 'An error occurred while fetching stock records. Please try again.';
    }
  }

  function updateExportLinks() {
    var tsvBtn = byId('stock-export-tsv');
    var csvBtn = byId('stock-export-csv');

    var params = new URLSearchParams({
      term: state.term,
      type: state.type,
      available: state.available,
      linkage: state.linkage,
      phenotype: state.phenotype,
      karyotype: state.karyotype,
      f_mgsc: state.f_mgsc,
      f_bank: state.f_bank,
      f_expvp: state.f_expvp,
      source: state.source
    });

    if (tsvBtn) {
      params.set('format', 'tsv');
      tsvBtn.href = API_URL + '?' + params.toString();
    }
    if (csvBtn) {
      params.set('format', 'csv');
      csvBtn.href = API_URL + '?' + params.toString();
    }
  }

  function syncUrlParams() {
    if (!window.history || !window.history.replaceState) return;
    var params = new URLSearchParams();
    if (state.term) params.set('q', state.term);
    if (state.type) params.set('type', state.type);
    if (state.available) params.set('available', state.available);
    if (state.linkage) params.set('linkage', state.linkage);
    if (state.phenotype) params.set('phenotype', state.phenotype);
    if (state.karyotype) params.set('karyotype', state.karyotype);
    if (state.f_mgsc) params.set('f_mgsc', state.f_mgsc);
    if (state.f_bank) params.set('f_bank', state.f_bank);
    if (state.f_expvp) params.set('f_expvp', state.f_expvp);
    if (state.source !== 'mgdb') params.set('source', state.source);
    if (state.sort !== 'relevance') params.set('sort', state.sort);
    if (state.view !== 'table') params.set('view', state.view);
    if (state.page > 1) params.set('page', state.page);

    var queryString = params.toString();
    var newUrl = window.location.pathname + (queryString ? '?' + queryString : '');
    window.history.replaceState({}, '', newUrl);
  }

  function readUrlParams() {
    var params = new URLSearchParams(window.location.search);
    if (params.has('q')) state.term = params.get('q');
    else if (params.has('term')) state.term = params.get('term');
    if (params.has('type')) state.type = parseInt(params.get('type'), 10) || 0;
    if (params.has('available')) state.available = parseInt(params.get('available'), 10) || 0;
    if (params.has('linkage')) state.linkage = parseInt(params.get('linkage'), 10) || 0;
    if (params.has('phenotype')) state.phenotype = parseInt(params.get('phenotype'), 10) || 0;
    if (params.has('karyotype')) state.karyotype = parseInt(params.get('karyotype'), 10) || 0;
    if (params.has('f_mgsc')) state.f_mgsc = params.get('f_mgsc');
    if (params.has('f_bank')) state.f_bank = params.get('f_bank');
    if (params.has('f_expvp')) state.f_expvp = params.get('f_expvp');
    if (params.has('source')) state.source = params.get('source');
    if (params.has('sort')) state.sort = params.get('sort');
    if (params.has('view')) state.view = params.get('view') === 'card' ? 'card' : 'table';
    if (params.has('page')) state.page = parseInt(params.get('page'), 10) || 1;

    var queryInput = byId('stock-query');
    if (queryInput && state.term) queryInput.value = state.term;

    var typeSelect = byId('stock-type');
    if (typeSelect && state.type) typeSelect.value = state.type;

    var availSelect = byId('stock-available');
    if (availSelect && state.available) availSelect.value = state.available;

    var linkSelect = byId('stock-linkage');
    if (linkSelect && state.linkage) linkSelect.value = state.linkage;

    var phenoSelect = byId('stock-phenotype');
    if (phenoSelect && state.phenotype) phenoSelect.value = state.phenotype;

    var karyoSelect = byId('stock-karyotype');
    if (karyoSelect && state.karyotype) karyoSelect.value = state.karyotype;

    var mgscCheck = byId('stock-f-mgsc');
    if (mgscCheck) mgscCheck.checked = state.f_mgsc === '1' || state.f_mgsc === 'true';

    var bankCheck = byId('stock-f-bank');
    if (bankCheck) bankCheck.checked = state.f_bank === '1' || state.f_bank === 'true';

    var expvpCheck = byId('stock-f-expvp');
    if (expvpCheck) expvpCheck.checked = state.f_expvp === '1' || state.f_expvp === 'true';

    var sortSelect = byId('stock-sort');
    if (sortSelect && state.sort) sortSelect.value = state.sort;

    updateViewButtons();
  }

  function updateViewButtons() {
    var buttons = document.querySelectorAll('.stock-view-btn');
    Array.prototype.forEach.call(buttons, function (btn) {
      var isCurrent = btn.getAttribute('data-view') === state.view;
      btn.classList.toggle('is-active', isCurrent);
      btn.setAttribute('aria-pressed', isCurrent ? 'true' : 'false');
    });
  }

  /* ── Plotly Category Breakdown Chart ────────────────────────────────────── */

  function renderChart() {
    var el = byId('stock-type-chart');
    if (!el) return;

    var rawLabels = el.getAttribute('data-labels') || '';
    var rawValues = el.getAttribute('data-values') || '';
    if (!rawLabels || !rawValues) return;

    var labels = rawLabels.split('|');
    var values = rawValues.split(',').map(function (v) { return parseInt(v, 10) || 0; });

    // Populate fallback rows
    var tbody = byId('stock-type-rows');
    if (tbody) {
      tbody.innerHTML = labels.map(function (l, idx) {
        return '<tr><td>' + escapeHtml(l) + '</td><td class="mgdb-numeric">' + Number(values[idx]).toLocaleString() + '</td></tr>';
      }).join('');
    }

    if (!window.MGDB || !window.MGDB.chart) { return; }

    var ordered = labels.map(function (l, i) { return { label: l, value: values[i] }; }).reverse();
    var height = Math.max(320, ordered.length * 40 + 110);
    el.style.height = height + 'px';

    /* Margins are sized from the figure, not fixed. A 200px label gutter is
       most of a phone's width: left as a constant it squeezed the plot to
       about 35px and clipped the axis title off the side of the box. Below the
       breakpoint the gutter shrinks, the axis title comes off -- the figure
       caption already says what is being counted -- and the ticks go to SI
       shorthand, which stays horizontal where full thousands do not. */
    var NARROW = 560;
    /* Stock type names run long -- "Sequence-indexed insertion" is 26
       characters -- and a 104px gutter cannot hold one. automargin would grow
       the gutter instead, which on a phone leaves no plot at all, so the tick
       text is shortened and the full name stays in the hover and in the values
       table under the figure. */
    var fullLabels = ordered.map(function (r) { return r.label; });
    var shortLabels = fullLabels.map(function (l) {
      return l.length > 16 ? l.slice(0, 15).replace(/[\s-]+$/, '') + '…' : l;
    });
    function metrics() {
      var width = el.getBoundingClientRect().width;
      var narrow = width > 0 && width < NARROW;
      return {
        narrow: narrow,
        margin: narrow ? { l: 104, r: 16, t: 8, b: 44 } : { l: 200, r: 72, t: 8, b: 44 },
        title: narrow ? '' : 'Current stock records',
        tickformat: narrow ? '~s' : ',d',
        nticks: narrow ? 3 : 0,
        labels: narrow ? shortLabels : fullLabels
      };
    }
    var m = metrics();

    window.MGDB.chart({
      target: el,
      traces: [{
        type: 'bar',
        orientation: 'h',
        y: m.labels,
        x: ordered.map(function (r) { return r.value; }),
        customdata: fullLabels,
        marker: { color: '#235c37' },
        /* A non-breaking space: SVG collapses a plain leading one, leaving the
           label flush against the end of its bar. */
        text: ordered.map(function (r) { return '\u00A0' + Number(r.value).toLocaleString(); }),
        textposition: m.narrow ? 'none' : 'outside',
        cliponaxis: false,
        hovertemplate: '%{customdata}<br>%{x:,} stocks<extra></extra>'
      }],
      layout: {
        height: height,
        showlegend: false,
        margin: m.margin,
        xaxis: { title: m.title, zeroline: false, tickformat: m.tickformat, nticks: m.nticks },
        yaxis: { automargin: true }
      }
    });

    /* MGDB.chart re-runs Plotly.Plots.resize on a window resize, which rescales
       the figure but keeps the margins it was drawn with. Crossing the
       breakpoint has to relayout. */
    if (window.Plotly && window.Plotly.relayout) {
      var lastNarrow = m.narrow;
      var timer = null;
      window.addEventListener('resize', function () {
        if (timer) { window.clearTimeout(timer); }
        timer = window.setTimeout(function () {
          var next = metrics();
          if (next.narrow === lastNarrow) { return; }
          lastNarrow = next.narrow;
          window.Plotly.relayout(el, {
            margin: next.margin,
            'xaxis.title': next.title,
            'xaxis.tickformat': next.tickformat,
            'xaxis.nticks': next.nticks
          });
          window.Plotly.restyle(el, {
            textposition: next.narrow ? 'none' : 'outside',
            y: [next.labels]
          });
        }, 180);
      });
    }
  }

  /* ── Initialization ────────────────────────────────────────────────────── */

  function initialize() {
    buildTabs();
    readUrlParams();
    renderChart();

    var form = byId('stock-search-form');
    var queryInput = byId('stock-query');
    var clearBtn = byId('stock-query-clear');
    var resetBtn = byId('stock-empty-reset');
    var typeSelect = byId('stock-type');
    var availSelect = byId('stock-available');
    var linkSelect = byId('stock-linkage');
    var phenoSelect = byId('stock-phenotype');
    var karyoSelect = byId('stock-karyotype');
    var mgscCheck = byId('stock-f-mgsc');
    var bankCheck = byId('stock-f-bank');
    var expvpCheck = byId('stock-f-expvp');
    var sortSelect = byId('stock-sort');
    var pageSizeSelect = byId('stock-page-size');
    var resultsFilter = byId('stock-results-filter');
    var advReset = byId('stock-adv-reset');

    if (pageSizeSelect) {
      pageSizeSelect.addEventListener('change', function () {
        state.pageSize = this.value === 'all' ? 'all' : parseInt(this.value, 10) || 25;
        state.page = 1;
        if (state.searched) { fetchResults(false); }
      });
    }

    if (resultsFilter) {
      resultsFilter.addEventListener('input', function () {
        state.filter = this.value.trim();
        if (state.filter === '' && state.lastData) {
          /* Re-render rather than un-hiding: the status line has to go back to
             what the search said, not what the filter said. */
          renderResults(state.lastData);
          return;
        }
        applyResultsFilter();
      });
    }

    if (advReset) {
      advReset.addEventListener('click', function () {
        ['stock-type', 'stock-available', 'stock-linkage', 'stock-phenotype', 'stock-karyotype']
          .forEach(function (id) { var el = byId(id); if (el) { el.value = '0'; } });
        ['stock-f-mgsc', 'stock-f-bank', 'stock-f-expvp']
          .forEach(function (id) { var el = byId(id); if (el) { el.checked = false; } });
        state.type = 0; state.available = 0; state.linkage = 0;
        state.phenotype = 0; state.karyotype = 0;
        state.f_mgsc = ''; state.f_bank = ''; state.f_expvp = '';
        state.page = 1;
        if (state.searched) { fetchResults(false); }
      });
    }

    var mgdbSourceBtn = byId('stock-source-mgdb');
    var grinSourceBtn = byId('stock-source-grin');

    if (queryInput) {
      queryInput.addEventListener('input', function () {
        state.term = queryInput.value.trim();
        if (clearBtn) clearBtn.hidden = !state.term;
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
          state.page = 1;
          fetchResults(false);
        }, 300);
      });
      if (clearBtn) clearBtn.hidden = !queryInput.value;
    }

    if (clearBtn && queryInput) {
      clearBtn.addEventListener('click', function () {
        queryInput.value = '';
        state.term = '';
        clearBtn.hidden = true;
        state.page = 1;
        fetchResults(false);
        queryInput.focus();
      });
    }

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        /* Every field is read from the DOM here. The advanced controls also
           update state on 'change', but that event never fires for a value the
           browser restores itself (autofill, bfcache), and a filter shown in
           the form must never be missing from the query it describes. */
        if (queryInput) { state.term = queryInput.value.trim(); }
        state.type = typeSelect ? (parseInt(typeSelect.value, 10) || 0) : 0;
        state.available = availSelect ? (parseInt(availSelect.value, 10) || 0) : 0;
        state.linkage = linkSelect ? (parseInt(linkSelect.value, 10) || 0) : 0;
        state.phenotype = phenoSelect ? (parseInt(phenoSelect.value, 10) || 0) : 0;
        state.karyotype = karyoSelect ? (parseInt(karyoSelect.value, 10) || 0) : 0;
        state.f_mgsc = mgscCheck && mgscCheck.checked ? '1' : '';
        state.f_bank = bankCheck && bankCheck.checked ? '1' : '';
        state.f_expvp = expvpCheck && expvpCheck.checked ? '1' : '';
        state.page = 1;
        fetchResults(true);
      });
    }

    if (typeSelect) {
      typeSelect.addEventListener('change', function () {
        state.type = parseInt(typeSelect.value, 10) || 0;
        state.page = 1;
        fetchResults(false);
      });
    }

    if (availSelect) {
      availSelect.addEventListener('change', function () {
        state.available = parseInt(availSelect.value, 10) || 0;
        state.page = 1;
        fetchResults(false);
      });
    }

    if (linkSelect) {
      linkSelect.addEventListener('change', function () {
        state.linkage = parseInt(linkSelect.value, 10) || 0;
        state.page = 1;
        fetchResults(false);
      });
    }

    if (phenoSelect) {
      phenoSelect.addEventListener('change', function () {
        state.phenotype = parseInt(phenoSelect.value, 10) || 0;
        state.page = 1;
        fetchResults(false);
      });
    }

    if (karyoSelect) {
      karyoSelect.addEventListener('change', function () {
        state.karyotype = parseInt(karyoSelect.value, 10) || 0;
        state.page = 1;
        fetchResults(false);
      });
    }

    if (mgscCheck) {
      mgscCheck.addEventListener('change', function () {
        state.f_mgsc = mgscCheck.checked ? '1' : '';
        state.page = 1;
        fetchResults(false);
      });
    }

    if (bankCheck) {
      bankCheck.addEventListener('change', function () {
        state.f_bank = bankCheck.checked ? '1' : '';
        state.page = 1;
        fetchResults(false);
      });
    }

    if (expvpCheck) {
      expvpCheck.addEventListener('change', function () {
        state.f_expvp = expvpCheck.checked ? '1' : '';
        state.page = 1;
        fetchResults(false);
      });
    }

    if (sortSelect) {
      sortSelect.addEventListener('change', function () {
        state.sort = sortSelect.value;
        state.page = 1;
        fetchResults(false);
      });
    }

    if (mgdbSourceBtn) {
      mgdbSourceBtn.addEventListener('click', function () {
        if (state.source !== 'mgdb') {
          state.source = 'mgdb';
          state.page = 1;
          fetchResults(false);
        }
      });
    }

    if (grinSourceBtn) {
      grinSourceBtn.addEventListener('click', function () {
        if (state.source !== 'grin') {
          state.source = 'grin';
          state.page = 1;
          fetchResults(false);
        }
      });
    }

    Array.prototype.forEach.call(document.querySelectorAll('.stock-view-btn'), function (btn) {
      btn.addEventListener('click', function () {
        var view = btn.getAttribute('data-view');
        if (view && (view === 'card' || view === 'table')) {
          state.view = view;
          updateViewButtons();
          fetchResults(false);
        }
      });
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-stock-example]'), function (btn) {
      btn.addEventListener('click', function () {
        var ex = btn.getAttribute('data-stock-example');
        if (ex && queryInput) {
          queryInput.value = ex;
          state.term = ex;
          if (clearBtn) clearBtn.hidden = false;
          state.page = 1;
          fetchResults(true);
        }
      });
    });

    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        if (queryInput) queryInput.value = '';
        if (typeSelect) typeSelect.value = '0';
        if (availSelect) availSelect.value = '0';
        if (linkSelect) linkSelect.value = '0';
        if (phenoSelect) phenoSelect.value = '0';
        if (karyoSelect) karyoSelect.value = '0';
        if (mgscCheck) mgscCheck.checked = false;
        if (bankCheck) bankCheck.checked = false;
        if (expvpCheck) expvpCheck.checked = false;
        if (clearBtn) clearBtn.hidden = true;

        state.term = '';
        state.type = 0;
        state.available = 0;
        state.linkage = 0;
        state.phenotype = 0;
        state.karyotype = 0;
        state.f_mgsc = '';
        state.f_bank = '';
        state.f_expvp = '';
        state.source = 'mgdb';
        state.page = 1;
        fetchResults(false);
      });
    }

    /* Only search on load when the address bar carries a query to restore.
       This used to run unconditionally, which meant the results section --
       hidden by default on every other hub -- was open and populated before
       the reader had asked for anything. */
    if (state.term || state.type || state.available || state.linkage
        || state.phenotype || state.karyotype
        || state.f_mgsc || state.f_bank || state.f_expvp) {
      fetchResults(false);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }
}());
