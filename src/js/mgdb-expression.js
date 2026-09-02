/* file: mgdb-expression.js
 *
 * purpose: behavior for the Expression Data Hub (/expression).
 *
 *   - the gene lookup, its examples, and its assembly filter
 *   - the results table: sorting, filtering, page size, pagination, TSV export
 *   - the "gene models by assembly" figure
 *   - the sticky section tab scrollspy
 *
 * The lookup is answered by search/expression/expression_search_api.php, which
 * pages server side. Everything else on the page is rendered by the controller.
 *
 * Bauplan's includeScript emits into <head>, so this file runs while the
 * document is still parsing. Everything below waits for DOMContentLoaded or
 * every query returns null.
 */

(function () {
  'use strict';

  var API = '/search/expression/expression_search_api.php';

  /* The API caps a page at 200. "All results" therefore means "as many as the
     endpoint will give at once", which is stated in the status line rather
     than quietly truncated. */
  var MAX_PAGE = 200;

  var state = {
    term: '',
    assembly: '',
    filter: '',
    sort: '',
    dir: 'asc',
    page: 1,
    pageSize: 25,
    rows: [],
    total: 0,
    searched: false,
    loading: false
  };

  var request = null;

  function byId(id) { return document.getElementById(id); }

  function num(value) { return Number(value || 0).toLocaleString(); }

  function esc(value) {
    if (value === null || value === undefined) { return ''; }
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /* ======================================================================
     Sticky section tabs

     Driven by scroll, IntersectionObserver and resize together: no single
     trigger fires in every case, and the results section appears and
     disappears under the tabs as searches run.
     ====================================================================== */

  function initTabs() {
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

    /* The line the spy measures against is the section's own scroll-margin-top,
       read back from CSS rather than repeated here, so a clicked tab and the
       scrollspy agree by construction even when the bar wraps to two rows. */
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

      // At the foot of the document the last section may never reach the line.
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

    var results = byId('expression-results-section');
    if (results && window.MutationObserver) {
      new window.MutationObserver(update).observe(results, {
        childList: true, subtree: true, attributes: true, attributeFilter: ['hidden']
      });
    }

    update();
  }

  /* ======================================================================
     Search
     ====================================================================== */

  function readForm() {
    var query = byId('expression-query');
    state.term = query ? query.value.trim() : '';
    state.assembly = (byId('expression-filter-assembly') || {}).value || '';
  }

  function queryString(extra) {
    var qs = new URLSearchParams();
    if (state.term) { qs.set('term', state.term); }
    if (state.assembly) { qs.set('assembly', state.assembly); }
    qs.set('limit', state.pageSize === 'all' ? MAX_PAGE : state.pageSize);
    qs.set('offset', state.pageSize === 'all' ? 0 : (state.page - 1) * state.pageSize);
    Object.keys(extra || {}).forEach(function (key) { qs.set(key, extra[key]); });
    return qs.toString();
  }

  function runSearch(options) {
    var opts = options || {};
    var section = byId('expression-results-section');
    var status = byId('expression-results-status');
    if (!section) { return; }

    state.searched = true;
    section.hidden = false;
    if (status) { status.textContent = 'Searching gene models…'; }

    // A slower earlier request must not overwrite a newer one's results.
    if (request && request.abort) { request.abort(); }
    var controller = window.AbortController ? new window.AbortController() : null;
    request = controller;
    state.loading = true;

    window.fetch(API + '?' + queryString(), controller ? { signal: controller.signal } : undefined)
      .then(function (res) { return res.json(); })
      .then(function (data) {
        state.loading = false;
        if (!data || !data.ok) { showError('The expression lookup could not be completed.'); return; }
        state.rows = data.results || [];
        state.total = data.summary ? data.summary.total : state.rows.length;
        render(data.summary);
        if (opts.scroll) { section.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') { return; }
        state.loading = false;
        showError('Network error while searching gene models.');
      });
  }

  function showError(message) {
    var body = byId('expression-results-body');
    var status = byId('expression-results-status');
    if (body) { body.innerHTML = ''; }
    if (status) { status.textContent = message; }
    var empty = byId('expression-results-empty');
    if (empty) { empty.hidden = true; }
    var pagination = byId('expression-pagination');
    if (pagination) { pagination.innerHTML = ''; }
  }

  /* ======================================================================
     Results
     ====================================================================== */

  /* The within-results filter runs over the fields the table shows, so
     "narrow this table" and what a reader can see agree. */
  function visibleRows() {
    if (!state.filter) { return state.rows; }
    var terms = state.filter.toLowerCase().split(/\s+/).filter(Boolean);
    return state.rows.filter(function (row) {
      var hay = [row.gene_name, row.locus_name, row.locus_full_name,
                 row.assembly_version, row.coordinates].join(' ').toLowerCase();
      for (var i = 0; i < terms.length; i++) {
        if (hay.indexOf(terms[i]) === -1) { return false; }
      }
      return true;
    });
  }

  function compare(a, b) {
    if (!state.sort) { return 0; }
    var dir = state.dir === 'desc' ? -1 : 1;
    var av = String(a[state.sort] || '');
    var bv = String(b[state.sort] || '');
    return dir * av.localeCompare(bv, undefined, { numeric: true });
  }

  function render(summary) {
    var body = byId('expression-results-body');
    var empty = byId('expression-results-empty');
    var scroll = document.querySelector('.mgdb-expression-page .expression-results-section .mgdb-table-scroll');
    if (!body) { return; }

    var rows = visibleRows().slice();
    if (state.sort) { rows.sort(compare); }

    body.innerHTML = rows.map(rowHtml).join('');
    /* The empty panel offers to reset the search, so it belongs to a search
       that found nothing. When the table filter is what emptied the page the
       search did match -- the status line says so and the filter can just be
       cleared. */
    if (empty) { empty.hidden = state.rows.length !== 0; }
    if (scroll) { scroll.hidden = rows.length === 0; }

    updateStatus(summary, rows.length);
    renderPagination(summary);
    updateSortIndicators();
    updateExport();
  }

  function rowHtml(row) {
    var links = [
      { label: 'qTeller', url: row.qteller_url },
      { label: 'Gene record', url: row.gene_center_url },
      { label: 'JBrowse', url: row.jbrowse_url },
      { label: 'eFP', url: row.efp_url }
    ].filter(function (link) { return link.url; }).map(function (link) {
      var external = /^https?:\/\//i.test(link.url);
      return '<a href="' + esc(link.url) + '"' + (external ? ' target="_blank" rel="noopener"' : '') + '>'
           + esc(link.label) + ' <span aria-hidden="true">' + (external ? '&nearr;' : '&rarr;') + '</span></a>';
    }).join('');

    var locus = row.locus_name
      ? '<strong>' + esc(row.locus_name) + '</strong>'
        + (row.locus_full_name ? '<span class="expression-locus-full">' + esc(row.locus_full_name) + '</span>' : '')
      : '<span class="mgdb-muted">&mdash;</span>';

    return '<tr>'
      + '<td><span class="expression-gene-name">' + esc(row.gene_name) + '</span></td>'
      + '<td>' + locus + '</td>'
      + '<td>' + esc(row.assembly_version || '—') + '</td>'
      + '<td><span class="expression-coords">' + esc(row.coordinates || '—') + '</span></td>'
      + '<td class="expression-col-open"><span class="expression-row-links">' + links + '</span></td>'
      + '</tr>';
  }

  function updateStatus(summary, shown) {
    var status = byId('expression-results-status');
    if (!status) { return; }

    var total = summary ? summary.total : state.total;
    if (!total) {
      status.textContent = 'No gene models matched.';
      return;
    }

    var noun = total === 1 ? 'gene model' : 'gene models';

    /* The table filter narrows the page in the browser, so once it is on the
       server's total no longer describes what is on screen. Reporting a range
       against it produced "Showing 1-0 of 110" whenever the filter matched
       nothing on the page. */
    if (state.filter) {
      status.textContent = shown === 0
        ? 'Nothing on this page matches the table filter \u201C' + state.filter + '\u201D. '
          + num(total) + ' ' + noun + ' matched the search.'
        : 'Showing ' + num(shown) + ' of the ' + num(state.rows.length)
          + ' results on this page matching \u201C' + state.filter + '\u201D, out of '
          + num(total) + ' ' + noun + ' matched by the search.';
      return;
    }
    var text;
    if (state.pageSize === 'all') {
      text = shown >= total
        ? 'Showing all ' + total.toLocaleString() + ' matching ' + noun + '.'
        : 'Showing the first ' + shown.toLocaleString() + ' of ' + total.toLocaleString() + ' matching ' + noun
          + ', which is as many as the lookup returns at once.';
    } else {
      var start = (state.page - 1) * state.pageSize + 1;
      text = 'Showing ' + start.toLocaleString() + '–' + (start + shown - 1).toLocaleString()
           + ' of ' + total.toLocaleString() + ' matching ' + noun + '.';
    }

    var filters = [];
    if (state.term) { filters.push('term “' + state.term + '”'); }
    if (state.assembly) { filters.push(state.assembly); }
    if (state.filter) { filters.push('table filter “' + state.filter + '”'); }
    if (filters.length) { text += ' Matching ' + filters.join(', ') + '.'; }
    if (summary && summary.elapsed_ms !== undefined) { text += ' (' + summary.elapsed_ms + ' ms)'; }

    status.textContent = text;
  }

  function renderPagination(summary) {
    var nav = byId('expression-pagination');
    if (!nav) { return; }

    var total = summary ? summary.total : state.total;
    if (state.pageSize === 'all' || !total) { nav.innerHTML = ''; return; }

    var pageCount = Math.ceil(total / state.pageSize);
    if (pageCount <= 1) { nav.innerHTML = ''; return; }

    var current = state.page;
    var html = '<button class="expression-page-btn" type="button" data-page="' + (current - 1) + '"'
             + (current === 1 ? ' disabled' : '') + '>&larr; Previous</button>';

    var pages = [1];
    if (current > 3) { pages.push('gap'); }
    for (var p = Math.max(2, current - 1); p <= Math.min(pageCount - 1, current + 1); p++) { pages.push(p); }
    if (current < pageCount - 2) { pages.push('gap'); }
    if (pageCount > 1) { pages.push(pageCount); }

    pages.forEach(function (page) {
      if (page === 'gap') {
        html += '<span class="expression-page-ellipsis" aria-hidden="true">&hellip;</span>';
      } else {
        html += '<button class="expression-page-btn' + (page === current ? ' is-active' : '') + '"'
             +  ' type="button" data-page="' + page + '"'
             +  (page === current ? ' aria-current="page"' : '') + '>' + page + '</button>';
      }
    });

    html += '<button class="expression-page-btn" type="button" data-page="' + (current + 1) + '"'
         +  (current === pageCount ? ' disabled' : '') + '>Next &rarr;</button>';

    nav.innerHTML = html;

    Array.prototype.forEach.call(nav.querySelectorAll('button[data-page]'), function (btn) {
      btn.addEventListener('click', function () {
        var page = parseInt(btn.getAttribute('data-page'), 10);
        if (!page || page < 1 || page > pageCount || page === state.page) { return; }
        state.page = page;
        runSearch({ scroll: true });
      });
    });
  }

  function updateSortIndicators() {
    Array.prototype.forEach.call(
      document.querySelectorAll('#expression-results-table th[data-expression-sort]'),
      function (th) {
        var key = th.getAttribute('data-expression-sort');
        th.setAttribute('aria-sort', state.sort === key
          ? (state.dir === 'desc' ? 'descending' : 'ascending')
          : 'none');
      });
  }

  function updateExport() {
    var link = byId('expression-export');
    if (!link) { return; }
    link.setAttribute('href', API + '?' + queryString({ format: 'tsv', limit: MAX_PAGE, offset: 0 }));
  }

  /* ======================================================================
     Form wiring
     ====================================================================== */

  function updateClearButton() {
    var clear = byId('expression-query-clear');
    var query = byId('expression-query');
    if (clear && query) { clear.hidden = query.value.length === 0; }
  }

  function initSearch() {
    var form = byId('expression-search-form');
    var query = byId('expression-query');

    if (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        readForm();
        state.page = 1;
        state.sort = '';
        state.dir = 'asc';
        runSearch({ scroll: true });
      });
    }

    if (query) {
      query.addEventListener('input', function () {
        updateClearButton();
        // Before the first search, typing must not open the results section.
        if (!state.searched) { return; }
        readForm();
        state.page = 1;
        runSearch({});
      });
    }

    var clear = byId('expression-query-clear');
    if (clear && query) {
      clear.addEventListener('click', function () {
        query.value = '';
        updateClearButton();
        query.focus();
        if (state.searched) { readForm(); state.page = 1; runSearch({}); }
      });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-expression-example]'), function (btn) {
      btn.addEventListener('click', function () {
        if (query) { query.value = btn.getAttribute('data-expression-example'); }
        updateClearButton();
        readForm();
        state.page = 1;
        state.sort = '';
        runSearch({ scroll: true });
      });
    });

    var assembly = byId('expression-filter-assembly');
    if (assembly) {
      assembly.addEventListener('change', function () {
        readForm();
        state.page = 1;
        if (state.searched) { runSearch({}); }
      });
    }

    var advReset = byId('expression-adv-reset');
    if (advReset) {
      advReset.addEventListener('click', function () {
        if (assembly) { assembly.value = ''; }
        readForm();
        state.page = 1;
        if (state.searched) { runSearch({}); }
      });
    }

    var emptyReset = byId('expression-empty-reset');
    if (emptyReset) {
      emptyReset.addEventListener('click', function () {
        if (query) { query.value = ''; }
        if (assembly) { assembly.value = ''; }
        var filter = byId('expression-results-filter');
        if (filter) { filter.value = ''; }
        state.filter = '';
        state.page = 1;
        updateClearButton();
        readForm();
        runSearch({ scroll: true });
        if (query) { query.focus(); }
      });
    }

    var filter = byId('expression-results-filter');
    if (filter) {
      filter.addEventListener('input', function () {
        state.filter = filter.value.trim();
        render();
      });
    }

    var pageSize = byId('expression-page-size');
    if (pageSize) {
      pageSize.addEventListener('change', function () {
        state.pageSize = pageSize.value === 'all' ? 'all' : parseInt(pageSize.value, 10) || 25;
        state.page = 1;
        if (state.searched) { runSearch({}); }
      });
    }

    Array.prototype.forEach.call(
      document.querySelectorAll('#expression-results-table th[data-expression-sort] button'),
      function (btn) {
        btn.addEventListener('click', function () {
          var key = btn.parentNode.getAttribute('data-expression-sort');
          if (state.sort === key) { state.dir = state.dir === 'asc' ? 'desc' : 'asc'; }
          else { state.sort = key; state.dir = 'asc'; }
          render();
        });
      });

    updateClearButton();

    /* A lookup can be linked to: /expression?term=adh1, or ?assembly=… from a
       bar in the figure below. */
    var params = new URLSearchParams(window.location.search);
    var linked = false;
    if (params.get('term') && query) { query.value = params.get('term'); linked = true; }
    if (params.get('assembly') && assembly) { assembly.value = params.get('assembly'); linked = true; }
    if (linked) {
      if (params.get('assembly')) {
        var adv = byId('expression-adv');
        if (adv) { adv.open = true; }
      }
      updateClearButton();
      readForm();
      runSearch({ scroll: true });
    }
  }

  /* ======================================================================
     Gene models by assembly

     .mgdb-chart is a fixed 320px in the design system, so the height has to be
     set on the element and handed to Plotly from the same variable or the bars
     are drawn into a box too short for them.
     ====================================================================== */

  function sizeChart(id, height) {
    var el = byId(id);
    if (el) { el.style.height = height + 'px'; }
    return height;
  }

  function readAttrJson(el, name) {
    if (!el) { return null; }
    try { return JSON.parse(el.getAttribute(name) || 'null'); }
    catch (error) { return null; }
  }

  function initFigure() {
    var el = byId('expression-assembly-chart');
    if (!el || !window.MGDB || !window.MGDB.chart) { return; }

    var labels = readAttrJson(el, 'data-labels');
    var values = readAttrJson(el, 'data-values');
    if (!labels || !values || !labels.length) { return; }

    var height = sizeChart('expression-assembly-chart', Math.max(320, labels.length * 34 + 110));

    window.MGDB.chart({
      target: 'expression-assembly-chart',
      traces: [{
        type: 'bar',
        orientation: 'h',
        x: values,
        y: labels,
        text: values.map(function (value) { return ' ' + Number(value).toLocaleString(); }),
        textposition: 'outside',
        textangle: 0,
        cliponaxis: false,
        marker: { color: '#285d46' },
        hovertemplate: '%{y}<br>%{x:,} gene models<extra></extra>'
      }],
      layout: {
        height: height,
        /* Room on the right for the outside value labels, which sit past the
           longest bar. The left margin is left to automargin plus the shared
           tick standoff, because assembly names vary in length. */
        margin: { l: 10, r: 96, t: 8, b: 48 },
        bargap: 0.28,
        xaxis: { title: { text: 'Distinct gene models' }, automargin: true },
        yaxis: { type: 'category', automargin: true }
      }
    });

    /* Selecting a bar searches that assembly. Plotly only gains its event
       emitter once it has drawn, so wait for the draw rather than guessing at
       a delay. */
    if (!window.MutationObserver) { return; }
    var attached = false;
    var observer = new window.MutationObserver(function () {
      if (attached || typeof el.on !== 'function') { return; }
      attached = true;
      observer.disconnect();
      el.on('plotly_click', function (event) {
        if (!event || !event.points || !event.points.length) { return; }
        var select = byId('expression-filter-assembly');
        if (select) { select.value = event.points[0].y; }
        var adv = byId('expression-adv');
        if (adv) { adv.open = true; }
        readForm();
        state.page = 1;
        runSearch({ scroll: true });
      });
    });
    observer.observe(el, { childList: true, subtree: true });
  }

  /* ====================================================================== */

  function init() {
    initTabs();
    initSearch();
    initFigure();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
