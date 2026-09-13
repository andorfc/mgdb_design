/* file: mgdb-expression.js
 *
 * purpose: behavior for the Expression Data Hub (/expression).
 *
 *   - the gene lookup, its examples, and its assembly filter
 *   - the results table: sorting, filtering, page size, pagination, TSV export
 *   - the expression profile a reader opens from a result, drawn by
 *     MGDB.geneExpression (js/mgdb-gene-expression.js, shared with the gene
 *     record) from /api/v1/data/expression/{genome}/{gene}
 *   - the "samples by tissue reading" figure
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
  var PROFILE_API = '/api/v1/data/expression/';

  /* The API caps a page at 200. "All results" therefore means "as many as the
     endpoint will give at once", which is stated in the status line rather
     than quietly truncated. */
  var MAX_PAGE = 200;

  function defaultView() {
    var params = new URLSearchParams(window.location.search);
    var v = params.get('view');
    if (v === 'cards' || v === 'table') { return v; }
    return (window.innerWidth && window.innerWidth < 768) ? 'cards' : 'table';
  }

  var state = {
    term: '',
    assembly: '',
    expressionOnly: false,
    filter: '',
    sort: '',
    dir: 'asc',
    page: 1,
    pageSize: 25,
    view: defaultView(),
    rows: [],
    total: 0,
    searched: false,
    loading: false
  };

  var request = null;
  var profileRequest = null;

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
     trigger fires in every case, and the results and profile sections appear
     and disappear under the tabs as the reader works.
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

    ['expression-results-section', 'expression-profile'].forEach(function (id) {
      var el = byId(id);
      if (el && window.MutationObserver) {
        new window.MutationObserver(update).observe(el, {
          childList: true, subtree: true, attributes: true, attributeFilter: ['hidden']
        });
      }
    });

    update();
  }

  /* ======================================================================
     Search
     ====================================================================== */

  function readForm() {
    var query = byId('expression-query');
    state.term = query ? query.value.trim() : '';
    state.assembly = (byId('expression-filter-assembly') || {}).value || '';
    var only = byId('expression-expression-only');
    state.expressionOnly = !!(only && only.checked);
    var advSort = byId('expression-adv-sort');
    if (advSort && advSort.value) {
      var parts = advSort.value.split('-');
      state.sort = parts[0];
      state.dir = parts[1] || 'asc';
    }
  }

  function queryString(extra) {
    var qs = new URLSearchParams();
    if (state.term) { qs.set('term', state.term); }
    if (state.assembly) { qs.set('assembly', state.assembly); }
    if (state.expressionOnly) { qs.set('expression_only', '1'); }
    if (state.sort) { qs.set('sort', state.sort + '-' + state.dir); }
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

  function compare(a, b) {
    if (!state.sort) { return 0; }
    var dir = state.dir === 'desc' ? -1 : 1;
    var av = String(a[state.sort] || '');
    var bv = String(b[state.sort] || '');
    return dir * av.localeCompare(bv, undefined, { numeric: true, sensitivity: 'base' });
  }

  function render(summary) {
    var body = byId('expression-results-body');
    var empty = byId('expression-results-empty');
    var cardsContainer = byId('expression-cards-view');
    var scroll = byId('expression-table-scroll');
    if (!body) { return; }

    var rows = state.rows.slice();
    if (state.sort) { rows.sort(compare); }

    body.innerHTML = rows.map(rowHtml).join('');
    if (cardsContainer) {
      cardsContainer.innerHTML = rows.map(renderCard).join('');
    }

    /* The empty panel offers to reset the search, so it belongs to a search
       that found nothing. When the table filter is what emptied the page the
       search did match -- the status line says so and the filter can just be
       cleared. */
    if (empty) { empty.hidden = state.rows.length !== 0; }
    if (scroll) { scroll.hidden = rows.length === 0; }

    updateViewDisplay();
    updateStatus(summary, rows.length);
    renderPagination(summary);
    updateSortIndicators();
    updateExport();
    applyResultFilter();
    initRowButtons();
  }

  /* The links a row carries. The endpoint sends null for every target that
     has no data for that assembly -- qTeller for W22, eFP for a NAM founder --
     and only what it sends is drawn. Apps on maizegdb.org subdomains and
     external hosts open in a new tab; the gene record does not. */
  function rowLinks(row) {
    var links = [
      { label: 'qTeller', url: row.qteller_url },
      { label: 'qTeller NAM', url: row.qteller_nam_url },
      { label: 'Gene record', url: row.gene_center_url },
      { label: 'JBrowse', url: row.jbrowse_url },
      { label: 'GBrowse', url: row.gbrowse_url },
      { label: 'eFP', url: row.efp_url }
    ].filter(function (link) { return link.url; }).map(function (link) {
      var host = (link.url.match(/^https?:\/\/([^/?#]+)/i) || [])[1];
      var newTab = !!host;
      return '<a href="' + esc(link.url) + '"' + (newTab ? ' target="_blank" rel="noopener"' : '') + '>'
           + esc(link.label) + '</a>';
    });
    var profile = row.expression_genome
      ? '<button type="button" class="expression-profile-btn" data-gene="' + esc(row.gene_name)
        + '" data-genome="' + esc(row.expression_genome) + '" data-symbol="' + esc(row.locus_name || '')
        + '">Profile</button>'
      : '<span class="expression-no-profile" title="qTeller has no expression data for this assembly">No profile</span>';
    return profile + links.join('');
  }

  function rowHtml(row) {
    var locus = row.locus_name
      ? '<strong>' + esc(row.locus_name) + '</strong>'
        + (row.locus_full_name ? '<span class="expression-locus-full">' + esc(row.locus_full_name) + '</span>' : '')
      : '<span class="mgdb-muted">&mdash;</span>';

    var haystack = [row.gene_name, row.locus_name, row.locus_full_name,
                    row.assembly_version, row.coordinates].filter(Boolean).join(' ').toLowerCase();

    return '<tr data-search="' + esc(haystack) + '"' + (row.expression_genome ? ' class="has-profile"' : '') + '>'
      + '<td><a class="expression-gene-name" href="' + esc(row.gene_center_url.split('#')[0]) + '">' + esc(row.gene_name) + '</a></td>'
      + '<td>' + locus + '</td>'
      + '<td>' + esc(row.assembly_version || '—') + '</td>'
      + '<td><span class="expression-coords">' + esc(row.coordinates || '—') + '</span></td>'
      + '<td class="expression-col-open"><span class="expression-row-links">' + rowLinks(row) + '</span></td>'
      + '</tr>';
  }

  function renderCard(row) {
    var geneUrl = row.gene_center_url || ('/gene_center/gene/' + encodeURIComponent(row.gene_name));
    var asmBadge = row.assembly_version
      ? '<span class="mgdb-pill">' + esc(row.assembly_version) + '</span>'
      : '';

    var locusUrl = row.locus_id
      ? '/data_center/locus?id=' + encodeURIComponent(row.locus_id)
      : '';

    var locusHtml = '';
    if (row.locus_name) {
      var locusLinked = locusUrl
        ? '<a href="' + locusUrl + '">' + esc(row.locus_name) + '</a>'
        : esc(row.locus_name);
      locusHtml = '<div class="expression-card-locus">' +
        'Locus: <strong>' + locusLinked + '</strong>' +
        (row.locus_full_name ? '<span class="expression-card-locus-desc">' + esc(row.locus_full_name) + '</span>' : '') +
      '</div>';
    }

    var metaItems = [];
    if (row.coordinates && row.coordinates !== '—') {
      metaItems.push('<dt>Coordinates</dt><dd>' + esc(row.coordinates) + '</dd>');
    }
    if (row.assembly_version) {
      metaItems.push('<dt>Assembly</dt><dd>' + esc(row.assembly_version) + '</dd>');
    }
    var metaHtml = metaItems.length ? '<dl class="expression-card-meta-list">' + metaItems.join('') + '</dl>' : '';

    var haystack = [row.gene_name, row.locus_name, row.locus_full_name,
                    row.assembly_version, row.coordinates].filter(Boolean).join(' ').toLowerCase();

    return '<article class="expression-result-card" data-search="' + esc(haystack) + '">' +
      '<div>' +
        '<div class="expression-card-header">' +
          '<h3 class="expression-card-title"><a href="' + esc(geneUrl) + '">' + esc(row.gene_name) + '</a></h3>' +
          '<div class="expression-card-badges">' + asmBadge + '</div>' +
        '</div>' +
        locusHtml +
        metaHtml +
      '</div>' +
      '<div class="expression-card-actions">' +
        '<div class="expression-card-tools expression-row-links">' + rowLinks(row) + '</div>' +
        '<div class="expression-card-copy-btns">' +
          '<button class="expression-copy-btn" type="button" data-copy-value="' + esc(row.gene_name) + '">Copy ID</button>' +
          (row.coordinates && row.coordinates !== '—' ? '<button class="expression-copy-btn" type="button" data-copy-value="' + esc(row.coordinates) + '">Copy Coords</button>' : '') +
        '</div>' +
      '</div>' +
    '</article>';
  }

  function updateViewDisplay() {
    var tableView = byId('expression-table-scroll');
    var cardsView = byId('expression-cards-view');
    var btnCards = byId('expression-view-cards');
    var btnTable = byId('expression-view-table');

    var isCards = state.view === 'cards';
    var hasResults = state.rows && state.rows.length > 0;

    if (tableView) { tableView.hidden = isCards || !hasResults; }
    if (cardsView) { cardsView.hidden = !isCards || !hasResults; }

    if (btnCards) {
      btnCards.setAttribute('aria-pressed', isCards ? 'true' : 'false');
      btnCards.classList.toggle('is-active', isCards);
    }
    if (btnTable) {
      btnTable.setAttribute('aria-pressed', !isCards ? 'true' : 'false');
      btnTable.classList.toggle('is-active', !isCards);
    }
  }

  function initRowButtons() {
    Array.prototype.forEach.call(document.querySelectorAll('.expression-copy-btn'), function (btn) {
      btn.addEventListener('click', function () {
        var val = btn.getAttribute('data-copy-value');
        if (!val) { return; }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(val).then(function () {
            var orig = btn.textContent;
            btn.textContent = 'Copied!';
            setTimeout(function () { btn.textContent = orig; }, 1500);
          });
        }
      });
    });
    Array.prototype.forEach.call(document.querySelectorAll('.expression-profile-btn'), function (btn) {
      btn.addEventListener('click', function () {
        openProfile(btn.getAttribute('data-gene'), btn.getAttribute('data-genome'),
                    btn.getAttribute('data-symbol'), { scroll: true });
      });
    });
  }

  function applyResultFilter() {
    var input = byId('expression-results-filter');
    var body = byId('expression-results-body');
    var cardsContainer = byId('expression-cards-view');
    if (!input) { return; }

    var query = input.value.trim().toLowerCase();
    state.filter = query;
    var terms = query.split(/\s+/).filter(Boolean);
    var visible = 0;
    var total = state.rows.length;

    if (body) {
      var rows = body.rows;
      for (var i = 0; i < rows.length; i++) {
        var hay = (rows[i].getAttribute('data-search') || '').toLowerCase();
        var match = true;
        for (var t = 0; t < terms.length; t++) {
          if (hay.indexOf(terms[t]) === -1) { match = false; break; }
        }
        rows[i].hidden = !match;
        rows[i].classList.toggle('is-alt', match && visible % 2 === 1);
        if (match) { visible++; }
      }
    }

    if (cardsContainer) {
      var cards = cardsContainer.querySelectorAll('.expression-result-card');
      for (var j = 0; j < cards.length; j++) {
        var cHay = (cards[j].getAttribute('data-search') || '').toLowerCase();
        var cMatch = true;
        for (var ct = 0; ct < terms.length; ct++) {
          if (cHay.indexOf(terms[ct]) === -1) { cMatch = false; break; }
        }
        cards[j].hidden = !cMatch;
      }
    }

    var note = byId('expression-filter-count');
    if (note) {
      note.textContent = query === '' ? '' : visible + ' of ' + total + ' shown';
    }
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
        ? 'Nothing on this page matches the table filter “' + state.filter + '”. '
          + num(total) + ' ' + noun + ' matched the search.'
        : 'Showing ' + num(shown) + ' of the ' + num(state.rows.length)
          + ' results on this page matching “' + state.filter + '”, out of '
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
    else if (state.expressionOnly) { filters.push('assemblies with expression profiles only'); }
    if (filters.length) { text += ' Matching ' + filters.join(', ') + '.'; }

    var withProfile = state.rows.filter(function (r) { return r.expression_genome; }).length;
    if (state.rows.length && withProfile < state.rows.length) {
      text += ' ' + withProfile + ' of the ' + state.rows.length + ' on this page have an expression profile.';
    }
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
      document.querySelectorAll('#expression-results-table th button[data-sort-key]'),
      function (btn) {
        var th = btn.closest('th');
        if (!th) { return; }
        var key = btn.getAttribute('data-sort-key');
        th.setAttribute('aria-sort', state.sort === key
          ? (state.dir === 'desc' ? 'descending' : 'ascending')
          : 'none');
      });
  }

  function updateExport() {
    var link = byId('expression-export-tsv') || byId('expression-export');
    if (!link) { return; }
    link.setAttribute('href', API + '?' + queryString({ format: 'tsv', limit: MAX_PAGE, offset: 0 }));
  }

  /* ======================================================================
     Expression profile

     One section for whichever result is open. The payload is the record
     the API serves for the gene ({attributes, sections, links}); the figure
     itself -- tiles, one bar per sample by study, the by-tissue and
     stress-condition panels, the study list -- is MGDB.geneExpression,
     shared with the gene record so the two pages draw the same thing.
     ====================================================================== */

  function profileLinks(gene, genome, data) {
    var links = data.links || {};
    var out = [];
    function add(label, url, external) {
      if (!url) { return; }
      out.push('<a class="mgdb-button mgdb-button-secondary mgdb-button-sm" href="' + esc(url) + '"'
        + (external ? ' target="_blank" rel="noopener"' : '') + '>' + esc(label) + '</a>');
    }
    add('Gene record', '/gene_center/gene/' + encodeURIComponent(gene) + '#gene-record-expression', false);
    /* The row the profile came from knows which qTeller pages apply; the
       API's own link is the release's chart page. */
    var row = state.rows.filter(function (r) { return r.gene_name === gene && r.expression_genome === genome; })[0];
    if (row) {
      add('qTeller', row.qteller_url, true);
      add('qTeller NAM founders', row.qteller_nam_url, true);
      add('JBrowse RNA-seq', row.jbrowse_url, true);
      add('GBrowse', row.gbrowse_url, true);
      add('eFP browser', row.efp_url, true);
    } else if (links.qteller) {
      add('qTeller', links.qteller + '&info=all', true);
    }
    return out.join('');
  }

  function openProfile(gene, genome, symbol, options) {
    var opts = options || {};
    var section = byId('expression-profile');
    var head = byId('expression-profile-head');
    var status = byId('expression-profile-status');
    var figure = byId('expression-profile-figure');
    if (!section || !head || !figure || !gene || !genome) { return; }

    section.hidden = false;
    head.innerHTML = '<div class="expression-profile-title"><span class="expression-gene-name">' + esc(gene) + '</span>'
      + (symbol ? ' <strong>' + esc(symbol) + '</strong>' : '')
      + ' <span class="expression-profile-genome">' + esc(genome) + '</span></div>';
    figure.innerHTML = '';
    if (status) { status.hidden = false; status.textContent = 'Loading the expression profile for ' + gene + '…'; }
    if (opts.scroll) { section.scrollIntoView({ behavior: 'smooth', block: 'start' }); }

    if (profileRequest && profileRequest.abort) { profileRequest.abort(); }
    var controller = window.AbortController ? new window.AbortController() : null;
    profileRequest = controller;

    var url = PROFILE_API + encodeURIComponent(genome) + '/' + encodeURIComponent(gene);
    window.fetch(url, controller ? { signal: controller.signal, headers: { Accept: 'application/json' } } : undefined)
      .then(function (res) { return res.json().then(function (json) { return { ok: res.ok, json: json }; }); })
      .then(function (r) {
        var data = r.json && r.json.data;
        if (!r.ok || !data || !data.sections) {
          var detail = r.json && r.json.detail ? ' ' + r.json.detail : '';
          if (status) { status.textContent = 'No expression profile could be loaded for ' + gene + '.' + detail; }
          return;
        }
        head.innerHTML += '<div class="expression-profile-links">' + profileLinks(gene, genome, r.json) + '</div>';
        var drawn = window.MGDB && window.MGDB.geneExpression
          ? window.MGDB.geneExpression(figure, {
              gene: { name: gene, symbol: symbol || null },
              profile: { attributes: data.attributes, sections: data.sections, links: r.json.links || {} },
              qteller: null
            })
          : false;
        if (status) {
          status.hidden = drawn;
          if (!drawn) { status.textContent = 'The profile for ' + gene + ' has no sample values to draw.'; }
        }
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') { return; }
        if (status) { status.textContent = 'Network error while loading the expression profile.'; }
      });
  }

  function closeProfile() {
    var section = byId('expression-profile');
    if (!section) { return; }
    if (profileRequest && profileRequest.abort) { profileRequest.abort(); }
    section.hidden = true;
    byId('expression-profile-figure').innerHTML = '';
    byId('expression-profile-head').innerHTML = '';
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
    var only = byId('expression-expression-only');
    var advSort = byId('expression-adv-sort');

    function rerun() {
      readForm();
      state.page = 1;
      if (state.searched) { runSearch({}); }
    }
    if (assembly) { assembly.addEventListener('change', rerun); }
    if (only) { only.addEventListener('change', rerun); }
    if (advSort) { advSort.addEventListener('change', rerun); }

    /* "Search" in the release table: that assembly, with whatever term is in
       the box. Without a term it lists the assembly's models, paged. */
    Array.prototype.forEach.call(document.querySelectorAll('[data-expression-assembly]'), function (btn) {
      btn.addEventListener('click', function () {
        if (assembly) { assembly.value = btn.getAttribute('data-expression-assembly'); }
        var adv = byId('expression-adv');
        if (adv) { adv.open = true; }
        readForm();
        state.page = 1;
        runSearch({ scroll: true });
      });
    });

    var advReset = byId('expression-adv-reset');
    if (advReset) {
      advReset.addEventListener('click', function () {
        if (assembly) { assembly.value = ''; }
        if (only) { only.checked = false; }
        if (advSort) { advSort.value = ''; }
        state.sort = '';
        state.dir = 'asc';
        rerun();
      });
    }

    var emptyReset = byId('expression-empty-reset');
    if (emptyReset) {
      emptyReset.addEventListener('click', function () {
        if (query) { query.value = ''; }
        if (assembly) { assembly.value = ''; }
        if (only) { only.checked = false; }
        if (advSort) { advSort.value = ''; }
        var filter = byId('expression-results-filter');
        if (filter) { filter.value = ''; }
        state.filter = '';
        state.sort = '';
        state.dir = 'asc';
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
        applyResultFilter();
        updateStatus(null, state.rows.filter(function (_, i) {
          var body = byId('expression-results-body');
          return body && body.rows[i] && !body.rows[i].hidden;
        }).length);
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

    var btnCards = byId('expression-view-cards');
    var btnTable = byId('expression-view-table');
    if (btnCards) {
      btnCards.addEventListener('click', function () { state.view = 'cards'; updateViewDisplay(); });
    }
    if (btnTable) {
      btnTable.addEventListener('click', function () { state.view = 'table'; updateViewDisplay(); });
    }

    Array.prototype.forEach.call(
      document.querySelectorAll('#expression-results-table th button[data-sort-key]'),
      function (btn) {
        btn.addEventListener('click', function () {
          var key = btn.getAttribute('data-sort-key');
          if (state.sort === key) {
            state.dir = state.dir === 'asc' ? 'desc' : 'asc';
          } else {
            state.sort = key;
            state.dir = 'asc';
          }
          render();
        });
      });

    var close = byId('expression-profile-close');
    if (close) { close.addEventListener('click', closeProfile); }

    updateClearButton();

    /* A lookup can be linked to: /expression?term=adh1, ?assembly=…, and a
       profile with ?gene=Zm00001eb056510&genome=Zm-B73-REFERENCE-NAM-5.0. */
    var params = new URLSearchParams(window.location.search);
    var linked = false;
    if (params.get('view') === 'cards' || params.get('view') === 'table') {
      state.view = params.get('view');
    }
    if (params.get('term') && query) { query.value = params.get('term'); linked = true; }
    if (params.get('assembly') && assembly) { assembly.value = params.get('assembly'); linked = true; }
    if (params.get('expression_only') === '1' && only) { only.checked = true; linked = true; }
    if (linked) {
      if (params.get('assembly') || params.get('expression_only')) {
        var adv = byId('expression-adv');
        if (adv) { adv.open = true; }
      }
      updateClearButton();
      readForm();
      runSearch({ scroll: !params.get('gene') });
    }
    if (params.get('gene') && params.get('genome')) {
      openProfile(params.get('gene'), params.get('genome'), '', { scroll: true });
    }
  }

  /* ======================================================================
     Samples by tissue reading

     RNA-seq and proteomics samples stacked per tissue. .mgdb-chart is a
     fixed 320px in the design system, so the height is set on the element
     and handed to Plotly from the same variable. Margins follow the
     figure's width: MGDB.chart re-runs Plotly's resize on a window resize,
     which keeps the margins the figure was drawn with, so a desktop gutter
     would otherwise survive onto a phone.
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
    var el = byId('expression-tissue-chart');
    if (!el || !window.MGDB || !window.MGDB.chart) { return; }

    var labels = readAttrJson(el, 'data-labels');
    var series = {
      other: readAttrJson(el, 'data-other'),
      abiotic: readAttrJson(el, 'data-abiotic'),
      biotic: readAttrJson(el, 'data-biotic'),
      control: readAttrJson(el, 'data-control'),
      protein: readAttrJson(el, 'data-protein')
    };
    if (!labels || !labels.length) { return; }
    for (var k in series) { if (!series[k] || series[k].length !== labels.length) { return; } }

    /* Plotly draws a horizontal bar chart bottom-up; the catalogue's order
       (root at the top) is what the table below shows, so reverse it. */
    var y = labels.slice().reverse();
    var rev = function (a) { return a.slice().reverse(); };
    var display = y.map(function (t) { return t.charAt(0).toUpperCase() + t.slice(1); });
    /* On a phone the full labels take 160px of a 259px figure through
       automargin and leave the plot 92px. The narrow set shortens the
       longest one ("seedling / whole plant") to its first word; the bars
       stay keyed on the full labels and only the tick text is swapped, so
       Plotly does not see new categories. */
    var SHORT = { 'seedling / whole plant': 'Seedling' };
    var displayNarrow = y.map(function (t) { return SHORT[t] || (t.charAt(0).toUpperCase() + t.slice(1)); });

    function metrics() {
      var w = el.getBoundingClientRect().width;
      var narrow = w > 0 && w < 560;
      return {
        narrow: narrow,
        margin: narrow ? { l: 8, r: 12, t: 8, b: 44 } : { l: 8, r: 24, t: 8, b: 48 },
        nticks: narrow ? 4 : 0,
        ticktext: narrow ? displayNarrow : display
      };
    }

    /* The same colours the profile figure uses for these readings: green
       for RNA-seq, the stress conditions' blue, red and grey, gold for
       proteomics. Every colour is also a column in the table below. */
    function trace(name, key, color, noun) {
      return {
        type: 'bar', orientation: 'h', name: name, x: rev(series[key]), y: y,
        marker: { color: color },
        hovertemplate: '%{y}<br>%{x:,} ' + noun + '<extra></extra>'
      };
    }

    var m = metrics();
    var height = sizeChart('expression-tissue-chart', Math.max(320, labels.length * 36 + 140));

    window.MGDB.chart({
      target: 'expression-tissue-chart',
      traces: [
        trace('RNA-seq, other studies', 'other', '#285d46', 'RNA-seq samples from non-stress studies'),
        trace('Abiotic stress', 'abiotic', '#0641a5', 'abiotic stress samples'),
        trace('Biotic stress', 'biotic', '#b03a2e', 'biotic stress samples'),
        trace('Control', 'control', '#8d978f', 'stress-study control samples'),
        trace('Proteomics', 'protein', '#d99a0b', 'proteomics samples')
      ],
      layout: {
        height: height,
        barmode: 'stack',
        bargap: 0.3,
        margin: m.margin,
        xaxis: { title: { text: 'Samples in the B73 v5 release' }, automargin: true, nticks: m.nticks },
        yaxis: { type: 'category', automargin: true, tickmode: 'array', tickvals: y, ticktext: m.ticktext }
      }
    });

    /* Relayout when the breakpoint is crossed: the margins, the tick
       density and the tick text. */
    var lastNarrow = m.narrow;
    window.addEventListener('resize', function () {
      if (!window.Plotly || typeof el.on !== 'function') { return; }
      var now = metrics();
      if (now.narrow === lastNarrow) { return; }
      lastNarrow = now.narrow;
      window.Plotly.relayout(el, { margin: now.margin, 'xaxis.nticks': now.nticks, 'yaxis.ticktext': now.ticktext });
    });
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
