/* Phenotype Data Hub JavaScript
   Handles search input, trait & body part filtering, card/table view switching,
   sticky section tabs with scrollspy, pagination, clipboard actions, and export URL synchronization. */

(function () {
  'use strict';

  var API_URL = '/search/phenotype/phenotype_search_api.php';
  var STORAGE_VIEW_KEY = 'mgdb-phenotype-view';

  /* The API caps page_size at 100, so "All results" asks for that. */
  var MAX_PAGE = 100;

  /* trait and part are id *lists*, not ids: the controller merges terms
     recorded under one name, so "embryo" is the two-id value "11087,983212".
     They stay strings the whole way through. */
  var state = {
    term: '',
    trait: '',
    part: '',
    page: 1,
    pageSize: 25,
    filter: '',
    searched: false,
    sort: 'relevance',
    view: 'table',
    currentData: null,
    loading: false
  };

  function byId(id) { return document.getElementById(id); }

  function readJson(id) {
    var el = byId(id);
    if (!el) { return null; }
    try { return JSON.parse(el.textContent || 'null'); }
    catch (error) { return null; }
  }

  function esc(str) {
    if (!str && str !== 0) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function number(n) {
    return Number(n || 0).toLocaleString();
  }

  /* ── Section Tabs & Scrollspy ───────────────────────────────────────────── */

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

    var results = byId('pheno-results-section');
    if (results && window.MutationObserver) {
      new window.MutationObserver(update).observe(results, {
        childList: true, subtree: true, attributes: true, attributeFilter: ['hidden']
      });
    }

    update();
  }

  /* ── URL Parameter Sync ─────────────────────────────────────────────────── */

  function readUrlParams() {
    var params = new URLSearchParams(window.location.search);
    if (params.has('q') || params.has('term')) {
      state.term = params.get('q') || params.get('term') || '';
    }
    /* Digits and commas only: the value is pasted straight back into the
       select and the query string. */
    if (params.has('trait')) {
      state.trait = (params.get('trait') || '').replace(/[^0-9,]/g, '');
    }
    if (params.has('part')) {
      state.part = (params.get('part') || '').replace(/[^0-9,]/g, '');
    }
    if (params.has('page')) {
      state.page = parseInt(params.get('page'), 10) || 1;
    }
    if (params.has('view')) {
      var v = params.get('view');
      if (v === 'card' || v === 'table') state.view = v;
    }
  }

  function updateUrlParams() {
    var params = new URLSearchParams();
    if (state.term) params.set('q', state.term);
    if (state.trait) params.set('trait', state.trait);
    if (state.part) params.set('part', state.part);
    if (state.page > 1) params.set('page', state.page);
    if (state.view && state.view !== 'table') params.set('view', state.view);

    var queryString = params.toString();
    var newUrl = window.location.pathname + (queryString ? '?' + queryString : '');
    window.history.replaceState({}, '', newUrl);
  }

  /* ── Export Links Sync ──────────────────────────────────────────────────── */

  function updateExportLinks() {
    var params = new URLSearchParams();
    if (state.term) params.set('term', state.term);
    if (state.trait) params.set('trait', state.trait);
    if (state.part) params.set('part', state.part);

    var tsvLink = byId('pheno-export-tsv');
    var csvLink = byId('pheno-export-csv');

    if (tsvLink) {
      var tsvParams = new URLSearchParams(params.toString());
      tsvParams.set('format', 'tsv');
      tsvLink.href = API_URL + '?' + tsvParams.toString();
    }

    if (csvLink) {
      var csvParams = new URLSearchParams(params.toString());
      csvParams.set('format', 'csv');
      csvLink.href = API_URL + '?' + csvParams.toString();
    }
  }

  /* ── Search Fetcher ─────────────────────────────────────────────────────── */

  function executeSearch(scrollToResults) {
    if (state.loading) return;
    state.loading = true;

    /* The results section is hidden until there is something to show. */
    var section = byId('pheno-results-section');
    if (section) { section.hidden = false; }
    state.searched = true;

    var status = byId('pheno-results-status');
    var container = byId('pheno-results');
    var empty = byId('pheno-empty');

    if (status) {
      status.textContent = 'Searching phenotypes…';
    }

    var params = new URLSearchParams();
    if (state.term) params.set('term', state.term);
    if (state.trait) params.set('trait', state.trait);
    if (state.part) params.set('part', state.part);
    params.set('page', state.page);
    params.set('page_size', state.pageSize === 'all' ? MAX_PAGE : state.pageSize);
    params.set('sort', state.sort);

    updateUrlParams();
    updateExportLinks();

    fetch(API_URL + '?' + params.toString())
      .then(function (res) { return res.json(); })
      .then(function (data) {
        state.loading = false;
        state.currentData = data;

        if (!data || !data.ok) {
          if (status) status.textContent = 'Search failed. Please try again.';
          return;
        }

        renderResults(data);
        renderPagination(data.summary.page, data.summary.page_count);

        if (scrollToResults && container) {
          var target = byId('pheno-results-section');
          if (target && typeof target.scrollIntoView === 'function') {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }
      })
      .catch(function (err) {
        state.loading = false;
        if (status) status.textContent = 'An error occurred while fetching phenotypes.';
      });
  }

  /* ── Render Results (Card or Table) ─────────────────────────────────────── */

  function renderResults(data) {
    var container = byId('pheno-results');
    var empty = byId('pheno-empty');
    var status = byId('pheno-results-status');
    var summary = data.summary;

    if (!summary.total || summary.total === 0) {
      if (container) container.innerHTML = '';
      if (empty) empty.hidden = false;
      if (status) {
        var qText = data.query.term ? ' for “' + esc(data.query.term) + '”' : '';
        status.textContent = 'No phenotypes matched your query' + qText + '.';
      }
      return;
    }

    if (empty) empty.hidden = true;

    var start = (summary.page - 1) * summary.page_size + 1;
    var end = Math.min(summary.total, summary.page * summary.page_size);
    var queryText = data.query.term ? ' for “' + esc(data.query.term) + '”' : '';
    var timing = ' (' + number(summary.elapsed_ms) + ' ms)';

    if (status) {
      if (state.pageSize === 'all' && summary.total > summary.page_size) {
        /* "All results" is capped by the endpoint, and saying so is better
           than a count that quietly stops short. */
        status.textContent = 'Showing the first ' + number(summary.page_size) + ' of '
          + number(summary.total) + ' phenotypes' + queryText
          + ', which is as many as the search returns at once.' + timing;
      } else {
        status.textContent = 'Showing ' + number(start) + '–' + number(end) + ' of '
          + number(summary.total) + ' phenotypes' + queryText + '.' + timing;
      }
    }

    if (!container) return;

    if (state.view === 'table') {
      renderTableView(container, data.results);
    } else {
      renderCardView(container, data.results);
    }

    initCopyButtons();
    /* Re-applied last so paging and the card/table toggle do not silently
       drop a filter the box still shows. */
    applyResultsFilter();
  }

  /* Narrows the page already rendered, in both the card and table view. The
     search pages server side, so this filters what is on screen and the status
     line says so. */
  function applyResultsFilter() {
    var container = byId('pheno-results');
    if (!container) { return; }

    var items = container.querySelectorAll('.pheno-result-card, tbody tr');
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
      var status = byId('pheno-results-status');
      var total = state.currentData && state.currentData.summary ? state.currentData.summary.total : 0;
      if (status) {
        status.textContent = shown === 0
          ? 'Nothing on this page matches the filter “' + state.filter + '”. '
            + number(total) + ' phenotypes matched the search.'
          : 'Showing ' + number(shown) + ' of the ' + number(items.length)
            + ' phenotypes on this page matching “' + state.filter + '”, out of '
            + number(total) + ' matched by the search.';
      }
    }
  }

  function initResultControls() {
    var sizeSelect = byId('pheno-page-size');
    if (sizeSelect) {
      sizeSelect.addEventListener('change', function () {
        state.pageSize = this.value === 'all' ? 'all' : parseInt(this.value, 10) || 25;
        state.page = 1;
        if (state.searched) { executeSearch(false); }
      });
    }

    var filterInput = byId('pheno-results-filter');
    if (filterInput) {
      filterInput.addEventListener('input', function () {
        state.filter = this.value.trim();
        if (state.filter === '' && state.currentData) {
          renderResults(state.currentData);
        }
        applyResultsFilter();
      });
    }

    var advReset = byId('pheno-adv-reset');
    if (advReset) {
      advReset.addEventListener('click', function () {
        var traitSelect = byId('pheno-trait');
        var partSelect = byId('pheno-part');
        if (traitSelect) { traitSelect.value = '0'; }
        if (partSelect) { partSelect.value = '0'; }
        state.trait = '';
        state.part = '';
        state.page = 1;
        if (state.searched) { executeSearch(false); }
      });
    }
  }

  function renderCardView(container, results) {
    container.className = 'pheno-results-container pheno-view-card';
    container.innerHTML = results.map(function (row) {
      var name = row.name || 'Untitled phenotype';
      var recordUrl = '/data_center/phenotype?id=' + encodeURIComponent(row.id);

      var traitBadge = row.trait_name
        ? '<span class="pheno-trait-badge">' + esc(row.trait_name) + '</span>'
        : '';
      var stockBadge = row.stock_count > 0
        ? '<span class="pheno-stock-badge">' + number(row.stock_count) + ' mutant stock' + (row.stock_count === 1 ? '' : 's') + '</span>'
        : '';

      var partsHtml = row.body_parts
        ? '<p><strong>Body Parts:</strong> ' + esc(row.body_parts) + '</p>'
        : '';
      var synHtml = row.synonyms
        ? '<p><strong>Synonyms:</strong> ' + esc(row.synonyms) + '</p>'
        : '';
      var commentsHtml = (row.memos || row.comments)
        ? '<div class="pheno-card-comments">' + esc(row.memos || row.comments) + '</div>'
        : '';

      return '<article class="pheno-result-card" data-pheno-id="' + row.id + '">'
        + '  <div>'
        + '    <div class="pheno-card-meta">' + traitBadge + stockBadge + '</div>'
        + '    <h3><a href="' + recordUrl + '">' + esc(name) + '</a></h3>'
        + '    <div class="pheno-card-details">' + partsHtml + synHtml + '</div>'
        +      commentsHtml
        + '  </div>'
        + '  <div class="pheno-card-links">'
        + '    <a href="' + recordUrl + '">View Record &rarr;</a>'
        + '    <button class="pheno-copy-btn" type="button" data-copy-value="' + esc(name) + '">Copy Name</button>'
        + '    <button class="pheno-copy-btn" type="button" data-copy-value="' + esc(row.id) + '">Copy ID</button>'
        + '  </div>'
        + '</article>';
    }).join('');
  }

  function renderTableView(container, results) {
    container.className = 'pheno-results-container pheno-view-table';
    var rows = results.map(function (row) {
      var name = row.name || 'Untitled phenotype';
      var recordUrl = '/data_center/phenotype?id=' + encodeURIComponent(row.id);
      var traitDisplay = row.trait_name ? '<span class="pheno-trait-badge">' + esc(row.trait_name) + '</span>' : '—';
      var partsDisplay = row.body_parts ? esc(row.body_parts) : '—';
      var synDisplay = row.synonyms ? esc(row.synonyms) : '—';
      var stocksDisplay = row.stock_count > 0 ? '<a href="/data_center/stock?phenotype=' + encodeURIComponent(row.id) + '">' + number(row.stock_count) + '</a>' : '0';

      return '<tr>'
        + '  <td><strong><a href="' + recordUrl + '">' + esc(name) + '</a></strong></td>'
        + '  <td>' + traitDisplay + '</td>'
        + '  <td>' + partsDisplay + '</td>'
        + '  <td>' + synDisplay + '</td>'
        + '  <td>' + stocksDisplay + '</td>'
        + '  <td><button class="pheno-copy-btn" type="button" data-copy-value="' + esc(name) + '">Copy</button></td>'
        + '</tr>';
    }).join('');

    container.innerHTML = '<table class="pheno-table">'
      + '  <thead>'
      + '    <tr>'
      + '      <th>Phenotype Name</th>'
      + '      <th>Trait Category</th>'
      + '      <th>Body Parts Affected</th>'
      + '      <th>Synonyms</th>'
      + '      <th>Stocks</th>'
      + '      <th>Action</th>'
      + '    </tr>'
      + '  </thead>'
      + '  <tbody>' + rows + '</tbody>'
      + '</table>';
  }

  /* ── Pagination ─────────────────────────────────────────────────────────── */

  function renderPagination(page, pageCount) {
    var nav = byId('pheno-pagination');
    if (!nav) return;

    if (pageCount <= 1) {
      nav.innerHTML = '';
      return;
    }

    var pages = [];
    var start = Math.max(1, page - 2);
    var end = Math.min(pageCount, page + 2);

    pages.push('<button type="button" data-page="' + Math.max(1, page - 1) + '"' + (page === 1 ? ' disabled' : '') + '>Previous</button>');
    if (start > 1) pages.push('<button type="button" data-page="1">1</button>');
    if (start > 2) pages.push('<button type="button" disabled>&hellip;</button>');

    for (var i = start; i <= end; i += 1) {
      pages.push('<button type="button" data-page="' + i + '" class="' + (i === page ? 'is-active' : '') + '" aria-current="' + (i === page ? 'page' : 'false') + '">' + i + '</button>');
    }

    if (end < pageCount - 1) pages.push('<button type="button" disabled>&hellip;</button>');
    if (end < pageCount) pages.push('<button type="button" data-page="' + pageCount + '">' + pageCount + '</button>');
    pages.push('<button type="button" data-page="' + Math.min(pageCount, page + 1) + '"' + (page === pageCount ? ' disabled' : '') + '>Next</button>');

    nav.innerHTML = pages.join('');

    Array.prototype.forEach.call(nav.querySelectorAll('[data-page]'), function (btn) {
      btn.addEventListener('click', function () {
        var targetPage = parseInt(btn.getAttribute('data-page'), 10);
        if (targetPage && targetPage !== state.page) {
          state.page = targetPage;
          executeSearch(true);
        }
      });
    });
  }

  /* ── Clipboard Copy Helper ──────────────────────────────────────────────── */

  function initCopyButtons() {
    Array.prototype.forEach.call(document.querySelectorAll('.pheno-copy-btn'), function (btn) {
      btn.addEventListener('click', function () {
        var val = btn.getAttribute('data-copy-value');
        if (!val) return;
        var original = btn.textContent;
        function finish(ok) {
          btn.textContent = ok ? 'Copied!' : 'Press Cmd+C';
          window.setTimeout(function () { btn.textContent = original; }, 1600);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(val).then(function () { finish(true); }).catch(function () { finish(false); });
        } else {
          finish(false);
        }
      });
    });
  }

  /* ── Form Controls & View Switcher ──────────────────────────────────────── */

  function initForm() {
    var form = byId('pheno-search-form');
    var input = byId('pheno-query');
    var clearBtn = byId('pheno-query-clear');
    var traitSelect = byId('pheno-trait');
    var partSelect = byId('pheno-part');

    if (input) {
      input.value = state.term;
      if (clearBtn) clearBtn.hidden = !state.term;

      input.addEventListener('input', function () {
        if (clearBtn) clearBtn.hidden = !input.value;
      });
    }

    if (traitSelect && state.trait) { traitSelect.value = state.trait; }
    if (partSelect && state.part) { partSelect.value = state.part; }

    if (clearBtn && input) {
      clearBtn.addEventListener('click', function () {
        input.value = '';
        clearBtn.hidden = true;
        state.term = '';
        state.page = 1;
        executeSearch(false);
      });
    }

    if (form && input) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        /* Every field is read from the DOM here. The advanced selects also
           update state on 'change', but that event never fires for a value the
           browser restores itself (autofill, bfcache), and a filter shown in
           the form must never be missing from the query it describes. */
        state.term = input.value.trim();
        state.trait = traitSelect && traitSelect.value !== '0' ? traitSelect.value : '';
        state.part = partSelect && partSelect.value !== '0' ? partSelect.value : '';
        state.page = 1;
        executeSearch(true);
      });
    }

    if (traitSelect) {
      traitSelect.addEventListener('change', function () {
        state.trait = traitSelect.value !== '0' ? traitSelect.value : '';
        state.page = 1;
        executeSearch(true);
      });
    }

    if (partSelect) {
      partSelect.addEventListener('change', function () {
        state.part = partSelect.value !== '0' ? partSelect.value : '';
        state.page = 1;
        executeSearch(true);
      });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-pheno-example]'), function (btn) {
      btn.addEventListener('click', function () {
        var ex = btn.getAttribute('data-pheno-example');
        if (input) {
          input.value = ex;
          if (clearBtn) clearBtn.hidden = false;
        }
        state.term = ex;
        state.page = 1;
        executeSearch(true);
      });
    });

    var emptyReset = byId('pheno-empty-reset');
    if (emptyReset) {
      emptyReset.addEventListener('click', function () {
        if (input) { input.value = ''; if (clearBtn) clearBtn.hidden = true; }
        if (traitSelect) traitSelect.value = '0';
        if (partSelect) partSelect.value = '0';
        state.term = '';
        state.trait = '';
        state.part = '';
        state.page = 1;
        executeSearch(false);
      });
    }
  }

  function initViewToggle() {
    var buttons = document.querySelectorAll('.pheno-view-btn[data-view]');
    if (!buttons.length) return;

    var savedView = 'table';
    try { savedView = localStorage.getItem(STORAGE_VIEW_KEY) || 'table'; } catch (e) {}
    if (state.view) savedView = state.view;

    function applyView(view) {
      state.view = view;
      Array.prototype.forEach.call(buttons, function (btn) {
        btn.setAttribute('aria-pressed', btn.getAttribute('data-view') === view ? 'true' : 'false');
      });
      try { localStorage.setItem(STORAGE_VIEW_KEY, view); } catch (e) {}
      if (state.currentData) {
        renderResults(state.currentData);
      }
      updateUrlParams();
    }

    Array.prototype.forEach.call(buttons, function (btn) {
      btn.addEventListener('click', function () {
        applyView(btn.getAttribute('data-view'));
      });
    });

    applyView(savedView);
  }

  /* ── Bootstrap ──────────────────────────────────────────────────────────── */


  /* ── Phenotypes by plant structure figure ───────────────────────────────── */

  /* The census is rendered server side into #pheno-chart-data by the same
     GROUP BY that fills the plant-structure filter, so this draws without a
     request of its own. */
  function initFigure() {
    var data = readJson('pheno-chart-data');
    if (!data || !data.bars || !data.bars.length) { return; }

    var bars = data.bars;

    var table = byId('pheno-part-table');
    if (table) {
      var body = table.querySelector('tbody');
      if (body) {
        body.innerHTML = bars.map(function (bar) {
          return '<tr><td>' + esc(bar.label) + '</td>'
               + '<td class="mgdb-numeric">' + number(bar.count) + '</td></tr>';
        }).join('');
      }
    }

    if (!window.MGDB || !window.MGDB.chart) { return; }

    /* Plotly stacks horizontal bars bottom-up, so the array is reversed to put
       the largest structure at the top, matching the table. */
    var ordered = bars.slice().reverse();
    var el = byId('pheno-part-chart');
    var height = Math.max(320, ordered.length * 40 + 110);
    if (el) { el.style.height = height + 'px'; }

    /* Margins are sized from the figure, not fixed. A 150px label gutter plus
       a 96px label margin is most of a phone's width: left as constants they
       squeeze the plot to nothing and every bar draws as a sliver. Below the
       breakpoint the gutter shrinks and the value labels come off, because the
       table underneath already carries every number. */
    var NARROW = 560;
    function metrics() {
      var width = el ? el.getBoundingClientRect().width : 0;
      var narrow = width > 0 && width < NARROW;
      return {
        narrow: narrow,
        /* Floors under automargin, which only ever grows a margin. */
        margin: narrow ? { l: 104, r: 16, t: 8, b: 44 } : { l: 150, r: 72, t: 8, b: 44 },
        nticks: narrow ? 3 : 0
      };
    }
    var m = metrics();

    var trace = {
      type: 'bar',
      orientation: 'h',
      y: ordered.map(function (bar) { return bar.label; }),
      x: ordered.map(function (bar) { return bar.count; }),
      /* The rolled-up tail is not a structure you can search, so it is drawn
         in a muted tone to read as a summary rather than another category. */
      marker: {
        color: ordered.map(function (bar) {
          return bar.ids ? '#285d46' : '#9aa8a0';
        })
      },
      /* A non-breaking space: SVG collapses a plain leading one, leaving the
         label flush against the end of its bar. */
      text: ordered.map(function (bar) { return '\u00A0' + number(bar.count); }),
      textposition: m.narrow ? 'none' : 'outside',
      cliponaxis: false,
      hovertemplate: '%{y}<br>%{x:,} phenotypes<extra></extra>'
    };

    window.MGDB.chart({
      target: 'pheno-part-chart',
      traces: [trace],
      layout: {
        height: height,
        showlegend: false,
        margin: m.margin,
        xaxis: { title: 'Phenotypes', zeroline: false, tickformat: ',d', nticks: m.nticks },
        yaxis: { automargin: true }
      }
    });

    /* MGDB.chart re-runs Plotly.Plots.resize on a window resize, which
       rescales the figure but keeps the margins it was drawn with. Crossing
       the breakpoint has to relayout. */
    if (el && window.Plotly && window.Plotly.relayout) {
      var lastNarrow = m.narrow;
      var timer = null;
      window.addEventListener('resize', function () {
        if (timer) { window.clearTimeout(timer); }
        timer = window.setTimeout(function () {
          var next = metrics();
          if (next.narrow === lastNarrow) { return; }
          lastNarrow = next.narrow;
          window.Plotly.relayout(el, { margin: next.margin, 'xaxis.nticks': next.nticks });
          window.Plotly.restyle(el, { textposition: next.narrow ? 'none' : 'outside' });
        }, 180);
      });
    }

    if (!el || !window.MutationObserver) { return; }

    /* Selecting a bar searches that structure. Plotly only gains its event
       emitter once it has drawn, so wait for the draw rather than guessing a
       delay. */
    var attached = false;
    var observer = new window.MutationObserver(function () {
      if (attached || typeof el.on !== 'function') { return; }
      attached = true;
      observer.disconnect();
      el.on('plotly_click', function (event) {
        if (!event || !event.points || !event.points.length) { return; }
        var match = ordered[event.points[0].pointIndex];
        if (!match || !match.ids) { return; }

        var partSelect = byId('pheno-part');
        if (partSelect) { partSelect.value = match.ids; }
        var adv = document.querySelector('.pheno-adv');
        if (adv) { adv.open = true; }
        state.part = match.ids;
        state.page = 1;
        executeSearch(true);
      });
    });
    observer.observe(el, { childList: true, subtree: true });
  }

  function init() {
    readUrlParams();
    buildTabs();
    initForm();
    initResultControls();
    initFigure();
    initViewToggle();
    updateExportLinks();

    // If query term, trait, or body part in URL, search immediately
    if (state.term || state.trait || state.part) {
      executeSearch(false);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
