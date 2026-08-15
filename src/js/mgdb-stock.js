/* ==========================================================================
   Stock data center — page behavior
   --------------------------------------------------------------------------
   Companion to /css/mgdb-stock.css and templates/static/mgdb_stock.bau.
   Depends only on MGDB (js/mgdb-modern.js).

   Progressive enhancement: without this file the page still renders its
   server-side content — the summary figures, the category table, the
   collections, and the NAM founder list. Only the search results, which were
   always fetched over the network, are lost.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  var API = '/search/stock/stock_search_api.php';
  var PAGE_SIZE = 25;

  function byId(id) { return document.getElementById(id); }

  var els = {};

  var state = {
    mode: 'simple',      // simple | advanced
    source: 'mgdb',      // mgdb | grin — which set of results is on screen
    term: '',
    caseSensitive: false,
    sort: 'relevance',
    page: 1,
    filters: {},
    grinTotal: 0
  };

  /* Each advanced filter is a checkbox that switches it on, optionally paired
     with a control that narrows it. */
  var FILTER_IDS = [
    'f_mgsc', 'f_bank', 'f_expvp', 'f_available', 'f_developer', 'f_name',
    'f_type', 'f_linkage', 'f_parent', 'f_genvar1', 'f_genvar2', 'f_genvar3',
    'f_karyotype', 'f_phenotype'
  ];
  var VALUE_KEYS = [
    'available', 'developer', 'name', 'type', 'linkage', 'parent',
    'genvar1', 'genvar2', 'genvar3', 'karyotype', 'phenotype', 'attribution'
  ];

  function filterInput(key) { return document.querySelector('[data-stock-filter="' + key + '"]'); }
  function valueInput(key) { return document.querySelector('[data-stock-value="' + key + '"]'); }

  /* ------------------------------------------------------------------------
     Section tabs
     ------------------------------------------------------------------------ */

  function buildTabs() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length) { return; }

    var pairs = [];
    Array.prototype.forEach.call(tabs, function (tab) {
      var section = document.querySelector(tab.getAttribute('href'));
      if (section) { pairs.push({ tab: tab, section: section }); }
    });

    function markCurrent(target) {
      pairs.forEach(function (pair) {
        var current = pair.section === target;
        pair.tab.classList.toggle('is-current', current);
        if (current) { pair.tab.setAttribute('aria-current', 'true'); }
        else { pair.tab.removeAttribute('aria-current'); }
      });
    }

    var initial = pairs[0];
    if (window.location.hash) {
      pairs.forEach(function (pair) {
        if ('#' + pair.section.id === window.location.hash) { initial = pair; }
      });
    }
    if (initial) { markCurrent(initial.section); }

    pairs.forEach(function (pair) {
      pair.tab.addEventListener('click', function () { markCurrent(pair.section); });
    });

    if (!window.IntersectionObserver) { return; }

    var observer = new window.IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) { markCurrent(entry.target); }
      });
    }, { rootMargin: '-25% 0px -65% 0px' });

    pairs.forEach(function (pair) { observer.observe(pair.section); });
  }

  /* ------------------------------------------------------------------------
     Stock category figure
     ------------------------------------------------------------------------ */

  function buildCategoryChart() {
    var target = byId('stock-type-chart');
    if (!target) { return; }

    var labels = (target.getAttribute('data-labels') || '').split('|')
      .filter(function (label) { return label !== ''; });
    var values = (target.getAttribute('data-values') || '').split(',')
      .map(function (value) { return parseInt(value, 10); })
      .filter(function (value) { return !isNaN(value); });

    if (!labels.length || labels.length !== values.length) { return; }

    var rows = byId('stock-type-rows');
    if (rows) {
      var html = '';
      for (var i = 0; i < labels.length; i++) {
        html += '<tr><td>' + MGDB.escapeHtml(labels[i]) + '</td>' +
                '<td class="mgdb-numeric">' + values[i].toLocaleString() + '</td></tr>';
      }
      rows.innerHTML = html;
    }

    // Horizontal bars: stock type names are long enough that vertical bars
    // would force the labels to rotate.
    MGDB.chart({
      target: target,
      traces: [{
        type: 'bar',
        orientation: 'h',
        x: values.slice().reverse(),
        y: labels.slice().reverse(),
        marker: { color: MGDB.CHART_COLORS[3] },
        hovertemplate: '%{y}<br>%{x:,} stocks<extra></extra>'
      }],
      layout: {
        margin: { l: 200, r: 20, t: 12, b: 48 },
        xaxis: { title: { text: 'Current stock records' } },
        yaxis: { automargin: true }
      }
    });
  }

  /* ------------------------------------------------------------------------
     Query state
     ------------------------------------------------------------------------ */

  function readAdvancedForm() {
    var filters = {};
    FILTER_IDS.forEach(function (key) {
      var el = filterInput(key);
      if (el && el.checked) { filters[key] = '1'; }
    });
    VALUE_KEYS.forEach(function (key) {
      var el = valueInput(key);
      if (!el) { return; }
      var value = el.value.trim();
      if (value !== '' && value !== '0') { filters[key] = value; }
    });
    return filters;
  }

  function writeAdvancedForm(filters) {
    FILTER_IDS.forEach(function (key) {
      var el = filterInput(key);
      if (el) { el.checked = (filters[key] === '1'); }
    });
    VALUE_KEYS.forEach(function (key) {
      var el = valueInput(key);
      if (el) { el.value = filters[key] || (el.tagName === 'SELECT' ? '0' : ''); }
    });
  }

  function clearAdvancedForm() {
    writeAdvancedForm({});
    state.filters = {};
  }

  function buildQuery(mode) {
    var params = new window.URLSearchParams();
    params.set('mode', mode);
    params.set('page', String(state.page));
    params.set('page_size', String(PAGE_SIZE));

    if (mode === 'advanced') {
      params.set('sort', state.sort === 'relevance' ? 'name' : state.sort);
      Object.keys(state.filters).forEach(function (key) {
        params.set(key, state.filters[key]);
      });
    } else {
      params.set('sort', state.sort);
      params.set('term', state.term);
      if (state.caseSensitive) { params.set('case', '1'); }
    }
    return params;
  }

  function syncUrl() {
    if (!window.history || !window.history.replaceState) { return; }
    var params = new window.URLSearchParams();

    if (state.mode === 'advanced') {
      params.set('mode', 'advanced');
      Object.keys(state.filters).forEach(function (key) {
        params.set(key, state.filters[key]);
      });
    } else if (state.term) {
      params.set('term', state.term);
      if (state.caseSensitive) { params.set('case', '1'); }
      if (state.source === 'grin') { params.set('source', 'grin'); }
    }
    if (state.sort !== 'relevance') { params.set('sort', state.sort); }
    if (state.page > 1) { params.set('page', String(state.page)); }

    var query = params.toString();
    window.history.replaceState(null, '',
      window.location.pathname + (query ? '?' + query : '') + window.location.hash);
  }

  function readUrl() {
    if (!window.URLSearchParams) { return false; }
    var params = new window.URLSearchParams(window.location.search);

    var sort = params.get('sort');
    if (sort) {
      state.sort = sort;
      if (els.sort) { els.sort.value = sort; }
    }
    var page = parseInt(params.get('page'), 10);
    if (!isNaN(page) && page > 0) { state.page = page; }

    if (params.get('mode') === 'advanced') {
      state.mode = 'advanced';
      var filters = {};
      FILTER_IDS.concat(VALUE_KEYS).forEach(function (key) {
        var value = params.get(key);
        if (value !== null && value !== '') { filters[key] = value; }
      });
      state.filters = filters;
      writeAdvancedForm(filters);
      if (els.advanced && Object.keys(filters).length) { els.advanced.open = true; }
      return Object.keys(filters).length > 0;
    }

    // stock_term is the parameter the legacy form used; honour it so existing
    // links keep working.
    var term = params.get('term') || params.get('stock_term') || '';
    if (term) {
      state.mode = 'simple';
      state.term = term;
      state.caseSensitive = (params.get('case') === '1');
      state.source = params.get('source') === 'grin' ? 'grin' : 'mgdb';
      if (els.term) { els.term.value = term; }
      if (els.caseBox) { els.caseBox.checked = state.caseSensitive; }
      return true;
    }
    return false;
  }

  /* ------------------------------------------------------------------------
     Rendering
     ------------------------------------------------------------------------ */

  function escape(value) { return MGDB.escapeHtml(value); }
  function show(el, visible) { if (el) { el.hidden = !visible; } }

  var STATUS_LABEL = {
    unavailable: ['mgdb-pill-warn', 'Unavailable'],
    discontinued: ['mgdb-pill-error', 'Discontinued']
  };

  function statusPill(status) {
    var pill = STATUS_LABEL[status];
    if (!pill) { return ''; }
    return '<span class="mgdb-pill ' + pill[0] + '">' + pill[1] + '</span>';
  }

  function fact(label, value) {
    if (!value) { return ''; }
    return '<div><dt>' + label + '</dt><dd>' + value + '</dd></div>';
  }

  function stockItem(row) {
    var url = '/data_center/stock?id=' + encodeURIComponent(row.id);

    var html = '<li class="stock-item">' +
      '<div class="stock-item-head">' +
        '<h3><a href="' + url + '">' + escape(row.name) + '</a></h3>' +
        statusPill(row.status) +
      '</div>';

    if (row.synonyms && row.synonyms.length) {
      html += '<p class="stock-synonyms">Also known as <em>' +
              row.synonyms.map(escape).join('</em>, <em>') + '</em></p>';
    }

    var facts = fact('Type', escape(row.type));
    if (row.linkage_group) {
      facts += fact('Focus linkage group',
        '<a href="/data_center/lg?id=' + encodeURIComponent(row.linkage_group_id) + '">' +
        escape(row.linkage_group) + '</a>');
    }
    if (row.provider) {
      facts += fact('Available from',
        '<a href="/person?id=' + encodeURIComponent(row.provider_id) + '">' +
        escape(row.provider) + '</a>');
    }
    if (facts) {
      html += '<dl class="stock-facts">' + facts + '</dl>';
    }

    if (row.comments && row.comments.length) {
      html += '<details class="stock-comments"><summary>Curator notes (' +
              row.comments.length + ')</summary><dl>';
      row.comments.forEach(function (comment) {
        html += '<dt>' + escape(comment.label) + '</dt><dd>' + escape(comment.text) + '</dd>';
      });
      html += '</dl></details>';
    }

    return html + '</li>';
  }

  function grinItem(row) {
    var html = '<li class="stock-item stock-item-grin">' +
      '<div class="stock-item-head"><h3>' + escape(row.name) + '</h3>' +
      '<span class="mgdb-pill mgdb-pill-info">GRIN</span></div>';

    var facts = fact('Accession', escape(row.accession)) +
                fact('Improvement status', escape(row.improvement)) +
                fact('Genus', escape(row.genus)) +
                fact('Origin', escape(row.origin));
    if (facts) { html += '<dl class="stock-facts">' + facts + '</dl>'; }

    if (row.grin_id) {
      html += '<p class="stock-synonyms"><a href="https://npgsweb.ars-grin.gov/gringlobal/accessiondetail.aspx?id=' +
              encodeURIComponent(row.grin_id) + '">See this accession at GRIN-Global</a></p>';
    }

    return html + '</li>';
  }

  function renderPagination(summary) {
    if (!els.pagination) { return; }
    if (summary.page_count <= 1) {
      els.pagination.innerHTML = '';
      show(els.pagination, false);
      return;
    }

    var current = summary.page;
    var last = summary.page_count;
    var wanted = [1, last, current, current - 1, current + 1, current - 2, current + 2];
    var pages = wanted.filter(function (page, index, all) {
      return page >= 1 && page <= last && all.indexOf(page) === index;
    }).sort(function (a, b) { return a - b; });

    var html = '';
    if (current > 1) {
      html += '<a href="#stock-results" data-stock-page="' + (current - 1) + '" rel="prev">Previous</a>';
    }
    var previous = 0;
    pages.forEach(function (page) {
      if (previous && page - previous > 1) { html += '<span aria-hidden="true">&hellip;</span>'; }
      if (page === current) { html += '<span aria-current="page">' + page + '</span>'; }
      else { html += '<a href="#stock-results" data-stock-page="' + page + '">' + page + '</a>'; }
      previous = page;
    });
    if (current < last) {
      html += '<a href="#stock-results" data-stock-page="' + (current + 1) + '" rel="next">Next</a>';
    }
    html += '<span class="mgdb-pagination-status">Page ' + current + ' of ' + last + '</span>';

    els.pagination.innerHTML = html;
    show(els.pagination, true);
  }

  function renderSources() {
    // The GRIN switch only makes sense for a term search, and only when there
    // is something on the other side of it.
    var relevant = (state.mode === 'simple' && state.term !== '' && state.grinTotal > 0);
    show(els.sources, relevant);
    if (!relevant) { return; }

    els.sourceMgdb.setAttribute('aria-pressed', state.source === 'mgdb' ? 'true' : 'false');
    els.sourceGrin.setAttribute('aria-pressed', state.source === 'grin' ? 'true' : 'false');
    els.sourceGrin.textContent = state.grinTotal.toLocaleString() + ' GRIN accession' +
      (state.grinTotal === 1 ? '' : 's');
  }

  function renderEmpty(payload) {
    var title = 'No stocks found';
    var body = 'Check the spelling, or try a shorter term.';
    var actionLabel = '';
    var action = null;

    if (payload.reason === 'no-term') {
      title = 'Enter a search term';
      body = 'Type a stock identifier, synonym, or external accession above.';
    } else if (payload.reason === 'no-filters') {
      title = 'No filters were set';
      body = 'Tick at least one box in the advanced search before running it.';
    } else if (payload.mode === 'grin') {
      title = 'No GRIN accessions found';
      body = 'Nothing in the mirrored USDA collection matched that term.';
    } else if (payload.mode === 'advanced') {
      body = 'No stock matched every one of those criteria. Try removing the narrowest one.';
    } else if (state.grinTotal > 0) {
      body = 'No MaizeGDB stock matched that term, but the mirrored USDA GRIN collection has ' +
             state.grinTotal.toLocaleString() + '.';
      actionLabel = 'Show the GRIN accessions';
      action = function () { switchSource('grin'); };
    }

    els.emptyTitle.textContent = title;
    els.emptyBody.textContent = body;

    els.emptyAction.onclick = action;
    if (actionLabel) {
      els.emptyAction.textContent = actionLabel;
      show(els.emptyAction, true);
    } else {
      show(els.emptyAction, false);
    }
    show(els.empty, true);
  }

  function renderCriteria(payload) {
    if (!els.criteria) { return; }
    if (payload.mode !== 'advanced' || !payload.criteria || !payload.criteria.length) {
      show(els.criteria, false);
      return;
    }
    els.criteria.innerHTML = '<strong>Searching for stocks</strong> ' +
      escape(payload.criteria.join(', and ')) + '.';
    show(els.criteria, true);
  }

  function render(payload) {
    var summary = payload.summary || {};
    var total = summary.total || 0;
    var isGrin = (payload.mode === 'grin');

    if (payload.mode === 'simple' && typeof payload.grin_total === 'number') {
      state.grinTotal = payload.grin_total;
    }

    show(els.loading, false);
    show(els.error, false);
    show(els.single, false);
    renderCriteria(payload);
    renderSources();

    if (total === 0) {
      els.list.innerHTML = '';
      show(els.list, false);
      show(els.pagination, false);
      show(els.sortWrap, false);
      renderEmpty(payload);
      els.status.textContent = (payload.reason === 'no-term' || payload.reason === 'no-filters')
        ? '' : 'No matching records.';
      MGDB.announce('No matching records.');
      return;
    }

    show(els.empty, false);
    els.list.innerHTML = payload.results.map(isGrin ? grinItem : stockItem).join('');
    show(els.list, true);
    show(els.sortWrap, !isGrin);
    renderPagination(summary);

    // Exactly one match: the legacy page navigated straight to the record.
    // This offers the same destination without moving the reader off the
    // search they just ran.
    if (total === 1 && !isGrin && payload.mode === 'simple') {
      var row = payload.results[0];
      els.single.innerHTML =
        '<div><span class="mgdb-eyebrow">Exactly one stock matched</span>' +
        '<h3>' + escape(row.name) + '</h3>' +
        '<p>' + (row.type ? escape(row.type) : 'Stock record') +
        (row.provider ? ', available from ' + escape(row.provider) : '') + '.</p></div>' +
        '<a class="mgdb-button mgdb-button-primary" href="/data_center/stock?id=' +
        encodeURIComponent(row.id) + '">Open the stock record</a>';
      show(els.single, true);
    }

    var noun = isGrin ? 'GRIN accession' : 'stock record';
    var first = (summary.page - 1) * summary.page_size + 1;
    var last = first + payload.results.length - 1;
    var message = total.toLocaleString() + ' matching ' + noun + (total === 1 ? '' : 's');
    if (summary.page_count > 1) {
      message += ' &mdash; showing ' + first.toLocaleString() + ' to ' + last.toLocaleString();
    }
    els.status.innerHTML = message;
    MGDB.announce(total.toLocaleString() + ' matching ' + noun + (total === 1 ? '' : 's'));
  }

  /* ------------------------------------------------------------------------
     Running a search
     ------------------------------------------------------------------------ */

  function runSearch() {
    var mode = (state.mode === 'simple' && state.source === 'grin') ? 'grin' : state.mode;

    show(els.empty, false);
    show(els.error, false);
    show(els.single, false);
    show(els.loading, true);
    els.status.textContent = 'Searching…';

    MGDB.request(API + '?' + buildQuery(mode).toString(), { key: 'stock-search' })
      .then(function (payload) {
        if (!payload || !payload.ok) { throw new Error('search failed'); }
        render(payload);
        syncUrl();
      })
      .catch(function (error) {
        // An aborted request is a newer search superseding this one.
        if (error && error.name === 'AbortError') { return; }
        show(els.loading, false);
        show(els.list, false);
        show(els.empty, false);
        show(els.error, true);
        els.status.textContent = 'The search could not be completed.';
      });
  }

  function searchSimple(term) {
    state.mode = 'simple';
    state.source = 'mgdb';
    state.term = term;
    state.caseSensitive = els.caseBox ? els.caseBox.checked : false;
    state.grinTotal = 0;
    state.page = 1;
    runSearch();
  }

  function searchAdvanced() {
    state.mode = 'advanced';
    state.source = 'mgdb';
    state.filters = readAdvancedForm();
    state.page = 1;
    runSearch();
  }

  function switchSource(source) {
    state.source = source;
    state.page = 1;
    runSearch();
  }

  /* ------------------------------------------------------------------------
     Wiring
     ------------------------------------------------------------------------ */

  function init() {
    els = {
      form: byId('stock-form'),
      term: byId('stock-term'),
      caseBox: byId('stock-case'),
      clear: byId('stock-clear'),
      advanced: byId('stock-advanced'),
      advancedForm: byId('stock-advanced-form'),
      advancedClear: byId('stock-advanced-clear'),
      sort: byId('stock-sort'),
      sortWrap: byId('stock-sort-wrap'),
      status: byId('stock-status'),
      criteria: byId('stock-criteria'),
      sources: byId('stock-sources'),
      sourceMgdb: byId('stock-source-mgdb'),
      sourceGrin: byId('stock-source-grin'),
      loading: byId('stock-loading'),
      single: byId('stock-single'),
      list: byId('stock-list'),
      empty: byId('stock-empty'),
      emptyTitle: byId('stock-empty-title'),
      emptyBody: byId('stock-empty-body'),
      emptyAction: byId('stock-empty-action'),
      error: byId('stock-error'),
      pagination: byId('stock-pagination')
    };

    buildTabs();
    buildCategoryChart();

    if (!els.form || !els.list) { return; }

    els.form.addEventListener('submit', function (event) {
      event.preventDefault();
      searchSimple(els.term.value.trim());
    });

    if (els.clear) {
      els.clear.addEventListener('click', function () {
        els.term.value = '';
        els.term.focus();
        state.term = '';
        state.mode = 'simple';
        state.source = 'mgdb';
        state.grinTotal = 0;
        els.list.innerHTML = '';
        show(els.list, false);
        show(els.pagination, false);
        show(els.single, false);
        show(els.empty, false);
        show(els.sortWrap, false);
        show(els.criteria, false);
        show(els.sources, false);
        els.status.textContent = 'Enter an identifier above, or open the advanced search, to begin.';
        syncUrl();
      });
    }

    Array.prototype.forEach.call(
      document.querySelectorAll('[data-stock-example]'), function (button) {
        button.addEventListener('click', function () {
          var term = button.getAttribute('data-stock-example');
          els.term.value = term;
          searchSimple(term);
        });
      });

    if (els.advancedForm) {
      els.advancedForm.addEventListener('submit', function (event) {
        event.preventDefault();
        searchAdvanced();
      });

      // Entering a value is a clear enough signal that the filter is wanted;
      // ticking the box by hand as well would only be a way to get it wrong.
      VALUE_KEYS.forEach(function (key) {
        var value = valueInput(key);
        var box = filterInput('f_' + key);
        if (!value || !box) { return; }
        value.addEventListener('change', function () {
          if (value.value.trim() !== '' && value.value.trim() !== '0') { box.checked = true; }
        });
      });

      // The phenotype attribution field belongs to the phenotype filter.
      var attribution = valueInput('attribution');
      var phenotypeBox = filterInput('f_phenotype');
      if (attribution && phenotypeBox) {
        attribution.addEventListener('change', function () {
          if (attribution.value.trim() !== '') { phenotypeBox.checked = true; }
        });
      }
    }

    if (els.advancedClear) {
      els.advancedClear.addEventListener('click', clearAdvancedForm);
    }

    if (els.sort) {
      els.sort.addEventListener('change', function () {
        state.sort = els.sort.value;
        state.page = 1;
        runSearch();
      });
    }

    if (els.sourceMgdb) {
      els.sourceMgdb.addEventListener('click', function () { switchSource('mgdb'); });
    }
    if (els.sourceGrin) {
      els.sourceGrin.addEventListener('click', function () { switchSource('grin'); });
    }

    if (els.pagination) {
      els.pagination.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('[data-stock-page]') : null;
        if (!link) { return; }
        var page = parseInt(link.getAttribute('data-stock-page'), 10);
        if (isNaN(page)) { return; }
        state.page = page;
        runSearch();
      });
    }

    if (readUrl()) {
      runSearch();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
