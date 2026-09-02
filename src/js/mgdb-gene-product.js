/* file: mgdb-gene-product.js
 *
 * purpose: behavior for the Gene Product Data Hub (/data_center/gene_product).
 *
 *   - the product search, its examples, and its four advanced filters
 *   - the results table: sorting, filtering, page size, pagination, TSV export
 *   - the "gene products by functional class" figure
 *   - the class-card shortcuts into the search
 *   - the sticky section tab scrollspy
 *
 * The search is answered by search/gene_product/gene_product_search_api.php,
 * which pages server side. Everything else is rendered by the controller.
 *
 * Bauplan's includeScript emits into <head>, so this file runs while the
 * document is still parsing. Everything below waits for DOMContentLoaded or
 * every query returns null.
 */

(function () {
  'use strict';

  var API = '/search/gene_product/gene_product_search_api.php';

  /* The API caps a page at 200. "All results" therefore means "as many as the
     endpoint will give at once", which the status line says rather than
     quietly truncating. */
  var MAX_PAGE = 200;

  var state = {
    term: '', type: '', ec: '', localization: '', pathway: '',
    filter: '', sort: '', dir: 'asc',
    page: 1, pageSize: 25,
    rows: [], total: 0, searched: false
  };

  var request = null;

  function byId(id) { return document.getElementById(id); }

  function esc(value) {
    if (value === null || value === undefined) { return ''; }
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function num(value) { return Number(value || 0).toLocaleString(); }

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

    var results = byId('gene-product-results-section');
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
    var query = byId('gp-query');
    state.term = query ? query.value.trim() : '';
    state.type = (byId('gp-filter-type') || {}).value || '';
    state.ec = ((byId('gp-filter-ec') || {}).value || '').trim();
    state.localization = (byId('gp-filter-loc') || {}).value || '';
    state.pathway = (byId('gp-filter-pathway') || {}).value || '';
  }

  function hasCriteria() {
    return !!(state.term || state.type || state.ec || state.localization || state.pathway);
  }

  function queryString(extra) {
    var qs = new URLSearchParams();
    if (state.term) { qs.set('term', state.term); }
    if (state.type) { qs.set('type', state.type); }
    if (state.ec) { qs.set('ec_num', state.ec); }
    if (state.localization) { qs.set('localization', state.localization); }
    if (state.pathway) { qs.set('pathway', state.pathway); }
    qs.set('limit', state.pageSize === 'all' ? MAX_PAGE : state.pageSize);
    qs.set('offset', state.pageSize === 'all' ? 0 : (state.page - 1) * state.pageSize);
    Object.keys(extra || {}).forEach(function (key) { qs.set(key, extra[key]); });
    return qs.toString();
  }

  function runSearch(options) {
    var opts = options || {};
    var section = byId('gene-product-results-section');
    var status = byId('gp-results-status');
    if (!section) { return; }

    /* An empty form would ask the endpoint for the whole collection. The
       search stays closed until there is something to search for. */
    if (!hasCriteria()) {
      state.searched = false;
      section.hidden = true;
      return;
    }

    state.searched = true;
    section.hidden = false;
    if (status) { status.textContent = 'Searching gene products…'; }

    // A slower earlier request must not overwrite a newer one's results.
    if (request && request.abort) { request.abort(); }
    var controller = window.AbortController ? new window.AbortController() : null;
    request = controller;

    window.fetch(API + '?' + queryString(), controller ? { signal: controller.signal } : undefined)
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || !data.ok) { showError('The gene product search could not be completed.'); return; }
        state.rows = data.results || [];
        state.total = data.summary ? data.summary.total : state.rows.length;
        render(data.summary);
        if (opts.scroll) { section.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') { return; }
        showError('Network error while searching gene products.');
      });
  }

  function showError(message) {
    var body = byId('gp-results-body');
    if (body) { body.innerHTML = ''; }
    var status = byId('gp-results-status');
    if (status) { status.textContent = message; }
    var empty = byId('gp-results-empty');
    if (empty) { empty.hidden = true; }
    var pagination = byId('gp-pagination');
    if (pagination) { pagination.innerHTML = ''; }
  }

  /* ======================================================================
     Results
     ====================================================================== */

  function rowHaystack(row) {
    return [row.name, row.type,
            (row.synonyms || []).join(' '),
            (row.ec_numbers || []).join(' '),
            (row.encoded_by || []).map(function (l) { return l.name; }).join(' '),
            (row.gene_models || []).join(' '),
            (row.pathways || []).join(' '),
            (row.localizations || []).join(' ')].join(' ').toLowerCase();
  }

  function visibleRows() {
    if (!state.filter) { return state.rows; }
    var terms = state.filter.toLowerCase().split(/\s+/).filter(Boolean);
    return state.rows.filter(function (row) {
      var hay = rowHaystack(row);
      for (var i = 0; i < terms.length; i++) {
        if (hay.indexOf(terms[i]) === -1) { return false; }
      }
      return true;
    });
  }

  function sortValue(row) {
    switch (state.sort) {
      case 'type': return row.type || '';
      case 'ec': return (row.ec_numbers || [])[0] || '';
      case 'encoded': return ((row.encoded_by || [])[0] || {}).name || (row.gene_models || [])[0] || '';
      default: return row.name || '';
    }
  }

  function compare(a, b) {
    if (!state.sort) { return 0; }
    var dir = state.dir === 'desc' ? -1 : 1;
    return dir * String(sortValue(a)).localeCompare(String(sortValue(b)), undefined, { numeric: true });
  }

  function render(summary) {
    var body = byId('gp-results-body');
    var empty = byId('gp-results-empty');
    var scroll = document.querySelector('.mgdb-gene-product-page .gp-results-section .mgdb-table-scroll');
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
    var name = row.url
      ? '<a class="gp-product-name" href="' + esc(row.url) + '">' + esc(row.name || '(unnamed)') + '</a>'
      : '<span class="gp-product-name">' + esc(row.name || '(unnamed)') + '</span>';

    var synonyms = '';
    if (row.synonyms && row.synonyms.length) {
      var others = row.synonyms.filter(function (s) {
        return s && s.toLowerCase() !== String(row.name || '').toLowerCase();
      });
      if (others.length) {
        synonyms = '<span class="gp-synonyms">Synonyms&#58; ' + esc(others.slice(0, 4).join(', '))
                 + (others.length > 4 ? '…' : '') + '</span>';
      }
    }

    var ec = (row.ec_numbers && row.ec_numbers.length)
      ? '<span class="gp-chip-row">' + row.ec_numbers.map(function (n) {
          return '<a class="gp-ec-badge" href="https://enzyme.expasy.org/EC/' + encodeURIComponent(n)
               + '" target="_blank" rel="noopener">' + esc(n) + '</a>';
        }).join('') + '</span>'
      : '<span class="mgdb-muted">&mdash;</span>';

    var encoded = [];
    (row.encoded_by || []).forEach(function (locus) {
      encoded.push('<a href="' + esc(locus.url) + '"><em>' + esc(locus.name) + '</em></a>');
    });
    (row.gene_models || []).slice(0, 3).forEach(function (gm) {
      encoded.push('<a href="/gene_center/gene/' + encodeURIComponent(gm) + '">' + esc(gm) + '</a>');
    });
    if ((row.gene_models || []).length > 3) {
      encoded.push('<span class="mgdb-muted">+' + ((row.gene_models || []).length - 3) + ' more</span>');
    }
    var encodedCell = encoded.length
      ? '<span class="gp-encoded-list">' + encoded.join('') + '</span>'
      : '<span class="mgdb-muted">&mdash;</span>';

    var meta = '';
    if (row.pathways && row.pathways.length) {
      meta += '<span class="gp-meta-list"><strong>Pathways&#58;</strong> ' + esc(row.pathways.join(', ')) + '</span>';
    }
    if (row.localizations && row.localizations.length) {
      meta += '<span class="gp-meta-list"><strong>Localization&#58;</strong> ' + esc(row.localizations.join(', ')) + '</span>';
    }
    if (!meta) { meta = '<span class="mgdb-muted">&mdash;</span>'; }

    return '<tr>'
      + '<td>' + name + synonyms + '</td>'
      + '<td>' + (row.type ? esc(row.type) : '<span class="mgdb-muted">Unclassified</span>') + '</td>'
      + '<td>' + ec + '</td>'
      + '<td>' + encodedCell + '</td>'
      + '<td>' + meta + '</td>'
      + '</tr>';
  }

  function updateStatus(summary, shown) {
    var status = byId('gp-results-status');
    if (!status) { return; }

    var total = summary ? summary.total : state.total;
    if (!total) { status.textContent = 'No gene products matched.'; return; }

    var noun = total === 1 ? 'gene product' : 'gene products';

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
        ? 'Showing all ' + num(total) + ' matching ' + noun + '.'
        : 'Showing the first ' + num(shown) + ' of ' + num(total) + ' matching ' + noun
          + ', which is as many as the search returns at once.';
    } else {
      var start = (state.page - 1) * state.pageSize + 1;
      text = 'Showing ' + num(start) + '–' + num(start + shown - 1) + ' of ' + num(total)
           + ' matching ' + noun + '.';
    }

    var filters = [];
    if (state.term) { filters.push('term “' + state.term + '”'); }
    if (state.type) {
      var typeSel = byId('gp-filter-type');
      if (typeSel && typeSel.selectedOptions && typeSel.selectedOptions[0]) {
        filters.push(typeSel.selectedOptions[0].textContent.replace(/\s*\(.*\)$/, '').trim());
      }
    }
    if (state.ec) { filters.push('EC ' + state.ec); }
    if (state.localization) { filters.push('a localization filter'); }
    if (state.pathway) { filters.push('a pathway filter'); }
    if (state.filter) { filters.push('table filter “' + state.filter + '”'); }
    if (filters.length) { text += ' Matching ' + filters.join(', ') + '.'; }
    if (summary && summary.elapsed_ms !== undefined) { text += ' (' + summary.elapsed_ms + ' ms)'; }

    status.textContent = text;
  }

  function renderPagination(summary) {
    var nav = byId('gp-pagination');
    if (!nav) { return; }

    var total = summary ? summary.total : state.total;
    if (state.pageSize === 'all' || !total) { nav.innerHTML = ''; return; }

    var pageCount = Math.ceil(total / state.pageSize);
    if (pageCount <= 1) { nav.innerHTML = ''; return; }

    var current = state.page;
    var html = '<button class="gp-page-btn" type="button" data-page="' + (current - 1) + '"'
             + (current === 1 ? ' disabled' : '') + '>&larr; Previous</button>';

    var pages = [1];
    if (current > 3) { pages.push('gap'); }
    for (var p = Math.max(2, current - 1); p <= Math.min(pageCount - 1, current + 1); p++) { pages.push(p); }
    if (current < pageCount - 2) { pages.push('gap'); }
    if (pageCount > 1) { pages.push(pageCount); }

    pages.forEach(function (page) {
      if (page === 'gap') {
        html += '<span class="gp-page-ellipsis" aria-hidden="true">&hellip;</span>';
      } else {
        html += '<button class="gp-page-btn' + (page === current ? ' is-active' : '') + '"'
             +  ' type="button" data-page="' + page + '"'
             +  (page === current ? ' aria-current="page"' : '') + '>' + page + '</button>';
      }
    });

    html += '<button class="gp-page-btn" type="button" data-page="' + (current + 1) + '"'
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
      document.querySelectorAll('#gp-results-table th[data-gp-sort]'),
      function (th) {
        var key = th.getAttribute('data-gp-sort');
        th.setAttribute('aria-sort', state.sort === key
          ? (state.dir === 'desc' ? 'descending' : 'ascending')
          : 'none');
      });
  }

  function updateExport() {
    var link = byId('gp-export');
    if (link) { link.setAttribute('href', API + '?' + queryString({ format: 'tsv', limit: MAX_PAGE, offset: 0 })); }
  }

  /* ======================================================================
     Form wiring
     ====================================================================== */

  function updateClearButton() {
    var clear = byId('gp-query-clear');
    var query = byId('gp-query');
    if (clear && query) { clear.hidden = query.value.length === 0; }
  }

  function resetAdvanced() {
    ['gp-filter-type', 'gp-filter-loc', 'gp-filter-pathway'].forEach(function (id) {
      var el = byId(id);
      if (el) { el.value = ''; }
    });
    var ec = byId('gp-filter-ec');
    if (ec) { ec.value = ''; }
  }

  function initSearch() {
    var form = byId('gp-search-form');
    var query = byId('gp-query');

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

    var clear = byId('gp-query-clear');
    if (clear && query) {
      clear.addEventListener('click', function () {
        query.value = '';
        updateClearButton();
        query.focus();
        if (state.searched) { readForm(); state.page = 1; runSearch({}); }
      });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-gp-example]'), function (btn) {
      btn.addEventListener('click', function () {
        if (query) { query.value = btn.getAttribute('data-gp-example'); }
        updateClearButton();
        readForm();
        state.page = 1;
        state.sort = '';
        runSearch({ scroll: true });
      });
    });

    /* The four class cards below the results are shortcuts into the class
       filter, so they open the advanced panel rather than filtering invisibly. */
    Array.prototype.forEach.call(document.querySelectorAll('.gp-filter-shortcut'), function (btn) {
      btn.addEventListener('click', function () {
        var select = byId('gp-filter-type');
        if (select) { select.value = btn.getAttribute('data-type') || ''; }
        var adv = byId('gp-adv');
        if (adv) { adv.open = true; }
        readForm();
        state.page = 1;
        runSearch({ scroll: true });
      });
    });

    ['gp-filter-type', 'gp-filter-loc', 'gp-filter-pathway'].forEach(function (id) {
      var el = byId(id);
      if (!el) { return; }
      el.addEventListener('change', function () {
        readForm();
        state.page = 1;
        runSearch({});
      });
    });

    var ecInput = byId('gp-filter-ec');
    if (ecInput) {
      ecInput.addEventListener('change', function () {
        readForm();
        state.page = 1;
        runSearch({});
      });
    }

    var advReset = byId('gp-adv-reset');
    if (advReset) {
      advReset.addEventListener('click', function () {
        resetAdvanced();
        readForm();
        state.page = 1;
        runSearch({});
      });
    }

    var emptyReset = byId('gp-empty-reset');
    if (emptyReset) {
      emptyReset.addEventListener('click', function () {
        if (query) { query.value = ''; }
        resetAdvanced();
        var filter = byId('gp-results-filter');
        if (filter) { filter.value = ''; }
        state.filter = '';
        state.page = 1;
        updateClearButton();
        readForm();
        runSearch({});
        if (query) { query.focus(); }
      });
    }

    var filter = byId('gp-results-filter');
    if (filter) {
      filter.addEventListener('input', function () {
        state.filter = filter.value.trim();
        render();
      });
    }

    var pageSize = byId('gp-page-size');
    if (pageSize) {
      pageSize.addEventListener('change', function () {
        state.pageSize = pageSize.value === 'all' ? 'all' : parseInt(pageSize.value, 10) || 25;
        state.page = 1;
        if (state.searched) { runSearch({}); }
      });
    }

    Array.prototype.forEach.call(
      document.querySelectorAll('#gp-results-table th[data-gp-sort] button'),
      function (btn) {
        btn.addEventListener('click', function () {
          var key = btn.parentNode.getAttribute('data-gp-sort');
          if (state.sort === key) { state.dir = state.dir === 'asc' ? 'desc' : 'asc'; }
          else { state.sort = key; state.dir = 'asc'; }
          render();
        });
      });

    updateClearButton();

    /* A search can be linked to: ?term=kinase, or ?type=… from a bar in the
       figure below or one of the class cards. */
    var params = new URLSearchParams(window.location.search);
    var linked = false;
    if (params.get('term') && query) { query.value = params.get('term'); linked = true; }
    [['type', 'gp-filter-type'], ['ec_num', 'gp-filter-ec'],
     ['localization', 'gp-filter-loc'], ['pathway', 'gp-filter-pathway']].forEach(function (pair) {
      var value = params.get(pair[0]);
      var el = byId(pair[1]);
      if (value && el) { el.value = value; linked = true; }
    });
    if (linked) {
      if (params.get('type') || params.get('ec_num') || params.get('localization') || params.get('pathway')) {
        var adv = byId('gp-adv');
        if (adv) { adv.open = true; }
      }
      updateClearButton();
      readForm();
      runSearch({ scroll: true });
    }
  }

  /* ======================================================================
     Gene products by functional class

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
    var el = byId('gp-class-chart');
    if (!el || !window.MGDB || !window.MGDB.chart) { return; }

    var labels = readAttrJson(el, 'data-labels');
    var values = readAttrJson(el, 'data-values');
    var ids = readAttrJson(el, 'data-ids') || [];
    if (!labels || !values || !labels.length) { return; }

    var height = sizeChart('gp-class-chart', Math.max(320, labels.length * 34 + 110));

    window.MGDB.chart({
      target: 'gp-class-chart',
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
        hovertemplate: '%{y}<br>%{x:,} gene products<extra></extra>'
      }],
      layout: {
        height: height,
        /* Room on the right for the outside value labels. The left margin is
           left to automargin plus the shared tick standoff, because class names
           vary in length. */
        margin: { l: 10, r: 84, t: 8, b: 48 },
        bargap: 0.28,
        xaxis: { title: { text: 'Curated gene products' }, automargin: true },
        yaxis: { type: 'category', automargin: true }
      }
    });

    /* Selecting a bar searches that class. Plotly only gains its event emitter
       once it has drawn, so wait for the draw rather than guessing at a delay. */
    if (!window.MutationObserver) { return; }
    var attached = false;
    var observer = new window.MutationObserver(function () {
      if (attached || typeof el.on !== 'function') { return; }
      attached = true;
      observer.disconnect();
      el.on('plotly_click', function (event) {
        if (!event || !event.points || !event.points.length) { return; }
        var index = labels.indexOf(event.points[0].y);
        if (index === -1 || !ids[index]) { return; }
        var select = byId('gp-filter-type');
        if (select) { select.value = String(ids[index]); }
        var adv = byId('gp-adv');
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
