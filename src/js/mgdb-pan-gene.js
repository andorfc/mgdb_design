/* ==========================================================================
   Pan-Gene Center search — page behavior
   --------------------------------------------------------------------------
   Companion to /css/mgdb-pan-gene.css and templates/static/mgdb_pan_gene.bau.
   Depends only on MGDB (js/mgdb-modern.js); the legacy jQuery in the shell is
   deliberately not used.

   Progressive enhancement: with this file missing the page still renders its
   server-side content — the summary figures, the annotation table, the
   downloads form, and the definitions. Only the search results, which were
   always fetched over the network, are lost.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  var API = '/search/pan_gene/pan_gene_search_api.php';
  var PAGE_SIZE = 25;
  var VALUE_LIMIT = 3;      // list values shown per cell before "+n more"

  function byId(id) { return document.getElementById(id); }

  var els = {};

  /* State is the whole query. It is mirrored into the address bar so a result
     set can be linked to and the back button behaves. */
  var state = {
    mode: 'simple',
    term: '',
    sort: 'members',
    page: 1,
    filters: {}
  };

  var ADVANCED_FIELDS = [
    { key: 'analysis', id: 'pan-gene-analysis', type: 'value' },
    { key: 'gene_models', id: 'pan-gene-gene-models', type: 'value' },
    { key: 'proteins', id: 'pan-gene-proteins', type: 'value' },
    { key: 'min', id: 'pan-gene-min', type: 'value' },
    { key: 'max', id: 'pan-gene-max', type: 'value' },
    { key: 'min_annots', id: 'pan-gene-min-annots', type: 'value' },
    { key: 'max_annots', id: 'pan-gene-max-annots', type: 'value' },
    { key: 'locus', id: 'pan-gene-locus', type: 'flag' },
    { key: 'trait', id: 'pan-gene-trait', type: 'flag' },
    { key: 'protein_any', id: 'pan-gene-protein-any', type: 'flag' },
    { key: 'appear', id: 'pan-gene-appear', type: 'multi' },
    { key: 'not_appear', id: 'pan-gene-not-appear', type: 'multi' }
  ];

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
     Size distribution figure
     ------------------------------------------------------------------------ */

  function numbersFrom(element, attribute) {
    var raw = element.getAttribute(attribute) || '';
    if (!raw) { return []; }
    return raw.split(',').map(function (value) {
      return parseInt(value, 10);
    }).filter(function (value) { return !isNaN(value); });
  }

  function buildDistribution() {
    var target = byId('pan-gene-distribution-chart');
    if (!target) { return; }

    var sizes = numbersFrom(target, 'data-sizes');
    var counts = numbersFrom(target, 'data-counts');
    if (!sizes.length || sizes.length !== counts.length) { return; }

    // The accessible alternative to the canvas: every plotted value, in a
    // table that is in the DOM whether or not Plotly ever loads.
    var rows = byId('pan-gene-distribution-rows');
    if (rows) {
      var html = '';
      for (var i = 0; i < sizes.length; i++) {
        html += '<tr><td class="mgdb-numeric">' + sizes[i].toLocaleString() +
                '</td><td class="mgdb-numeric">' + counts[i].toLocaleString() + '</td></tr>';
      }
      rows.innerHTML = html;
    }

    MGDB.chart({
      target: target,
      traces: [{
        type: 'bar',
        x: sizes,
        y: counts,
        marker: { color: MGDB.CHART_COLORS[1] },
        hovertemplate: '%{x} members<br>%{y:,} pan-genes<extra></extra>'
      }],
      layout: {
        xaxis: { title: { text: 'Members in the pan-gene' }, dtick: 10 },
        yaxis: { title: { text: 'Pan-genes' } },
        bargap: 0.05
      }
    });
  }

  /* ------------------------------------------------------------------------
     Query building
     ------------------------------------------------------------------------ */

  function readAdvancedForm() {
    var filters = {};
    ADVANCED_FIELDS.forEach(function (field) {
      var el = byId(field.id);
      if (!el) { return; }
      if (field.type === 'flag') {
        if (el.checked) { filters[field.key] = '1'; }
      } else if (field.type === 'multi') {
        var selected = Array.prototype.filter.call(el.options, function (option) {
          return option.selected;
        }).map(function (option) { return option.value; });
        if (selected.length) { filters[field.key] = selected.join(','); }
      } else if (el.value.trim() !== '') {
        filters[field.key] = el.value.trim();
      }
    });
    return filters;
  }

  function writeAdvancedForm(filters) {
    ADVANCED_FIELDS.forEach(function (field) {
      var el = byId(field.id);
      if (!el) { return; }
      var value = filters[field.key];
      if (field.type === 'flag') {
        el.checked = (value === '1');
      } else if (field.type === 'multi') {
        var wanted = value ? value.split(',') : [];
        Array.prototype.forEach.call(el.options, function (option) {
          option.selected = wanted.indexOf(option.value) !== -1;
        });
      } else {
        el.value = value || '';
      }
    });
  }

  function clearAdvancedForm() {
    ADVANCED_FIELDS.forEach(function (field) {
      var el = byId(field.id);
      if (!el) { return; }
      if (field.type === 'flag') { el.checked = false; }
      else if (field.type === 'multi') {
        Array.prototype.forEach.call(el.options, function (option) { option.selected = false; });
      } else { el.value = ''; }
    });
  }

  function buildQuery() {
    var params = new window.URLSearchParams();
    params.set('mode', state.mode);
    params.set('page', String(state.page));
    params.set('page_size', String(PAGE_SIZE));
    params.set('sort', state.sort);
    if (state.mode === 'simple') {
      params.set('term', state.term);
    } else {
      Object.keys(state.filters).forEach(function (key) {
        params.set(key, state.filters[key]);
      });
    }
    return params;
  }

  function syncUrl() {
    if (!window.history || !window.history.replaceState) { return; }
    var params = new window.URLSearchParams();
    if (state.mode === 'simple') {
      if (state.term) { params.set('term', state.term); }
    } else {
      params.set('mode', 'advanced');
      Object.keys(state.filters).forEach(function (key) {
        params.set(key, state.filters[key]);
      });
    }
    if (state.sort !== 'members') { params.set('sort', state.sort); }
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
      ADVANCED_FIELDS.forEach(function (field) {
        var value = params.get(field.key);
        if (value !== null && value !== '') { filters[field.key] = value; }
      });
      state.filters = filters;
      writeAdvancedForm(filters);
      if (els.advanced && Object.keys(filters).length) { els.advanced.open = true; }
      return Object.keys(filters).length > 0;
    }

    // pan_gene_term is the parameter the legacy search form used; honour it so
    // existing links keep working.
    var term = params.get('term') || params.get('pan_gene_term') || '';
    if (term) {
      state.mode = 'simple';
      state.term = term;
      if (els.term) { els.term.value = term; }
      return true;
    }
    return false;
  }

  /* ------------------------------------------------------------------------
     Rendering
     ------------------------------------------------------------------------ */

  function escape(value) { return MGDB.escapeHtml(value); }

  function show(el, visible) { if (el) { el.hidden = !visible; } }

  function valueList(values, className) {
    if (!values || !values.length) {
      return '<span class="pan-gene-none">&mdash;</span>';
    }
    var shown = values.slice(0, VALUE_LIMIT).map(escape).join(', ');
    var html = '<span class="pan-gene-values ' + (className || '') + '">' + shown;
    if (values.length > VALUE_LIMIT) {
      html += '<span class="pan-gene-values-more">and ' +
              (values.length - VALUE_LIMIT) + ' more</span>';
    }
    return html + '</span>';
  }

  function locusCell(row) {
    if (!row.loci || !row.loci.length) {
      return '<span class="pan-gene-none">&mdash;</span>';
    }

    var links = row.loci.map(function (locus) {
      return '<a href="/gene_center/gene/' + encodeURIComponent(locus) + '">' +
             escape(locus) + '</a>';
    }).join(', ');

    var html = '<span class="pan-gene-values">' + links + '</span>';

    if (row.locus_evidence && row.locus_evidence.length) {
      html += '<details class="pan-gene-evidence"><summary>Why this locus</summary><ul>';
      row.locus_evidence.forEach(function (item) {
        html += '<li>' + escape(item.locus) + ' via <a href="/gene_center/gene/' +
                encodeURIComponent(item.gene_model) + '">' + escape(item.gene_model) +
                '</a>, per ' + escape(item.source) + '</li>';
      });
      html += '</ul></details>';
    }
    return html;
  }

  function coverageCell(row) {
    var total = row.annotation_total || 0;
    var percent = total > 0 ? Math.round(100 * row.annotation_count / total) : 0;
    return '<span class="pan-gene-coverage">' + row.annotation_count +
           (total ? ' / ' + total : '') + '</span>' +
           '<span class="pan-gene-coverage-bar" aria-hidden="true"><span style="width:' +
           percent + '%"></span></span>';
  }

  function matchedCell(row) {
    if (!row.matched_as || !row.matched_as.length) {
      return '<span class="pan-gene-none">&mdash;</span>';
    }
    return row.matched_as.map(function (kind) {
      return '<span class="mgdb-pill mgdb-pill-info pan-gene-matched">' + escape(kind) + '</span>';
    }).join('');
  }

  function recordUrl(row) {
    return '/pan_gene_center/pan_gene/' + encodeURIComponent(row.exemplar);
  }

  function renderRows(results) {
    var html = '';
    results.forEach(function (row) {
      html += '<tr>' +
        '<th scope="row"><a class="pan-gene-id" href="' + recordUrl(row) + '">' +
          escape(row.exemplar) + '</a></th>' +
        '<td>' + locusCell(row) + '</td>' +
        '<td>' + valueList(row.proteins) + '</td>' +
        '<td>' + valueList(row.traits) + '</td>' +
        '<td class="mgdb-numeric" data-value="' + row.member_count + '">' +
          row.member_count.toLocaleString() + '</td>' +
        '<td class="mgdb-numeric" data-value="' + row.annotation_count + '">' +
          coverageCell(row) + '</td>' +
        '<td>' + matchedCell(row) + '</td>' +
        '</tr>';
    });
    els.rows.innerHTML = html;
  }

  function renderSingle(row) {
    if (!els.single) { return; }
    els.single.innerHTML =
      '<div>' +
        '<span class="mgdb-eyebrow">Exactly one pan-gene matched</span>' +
        '<h3>' + escape(row.exemplar) + '</h3>' +
        '<p>' + row.member_count.toLocaleString() + ' member gene models across ' +
          row.annotation_count + ' of ' + row.annotation_total + ' annotations' +
          (row.loci && row.loci.length ? ', associated with ' + escape(row.loci.join(', ')) : '') +
        '.</p>' +
      '</div>' +
      '<a class="mgdb-button mgdb-button-primary" href="' + recordUrl(row) + '">Open the pan-gene record</a>';
    show(els.single, true);
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
      html += '<a href="#pan-gene-results" data-pan-gene-page="' + (current - 1) + '" rel="prev">Previous</a>';
    }
    var previous = 0;
    pages.forEach(function (page) {
      if (previous && page - previous > 1) { html += '<span aria-hidden="true">&hellip;</span>'; }
      if (page === current) {
        html += '<span aria-current="page">' + page + '</span>';
      } else {
        html += '<a href="#pan-gene-results" data-pan-gene-page="' + page + '">' + page + '</a>';
      }
      previous = page;
    });
    if (current < last) {
      html += '<a href="#pan-gene-results" data-pan-gene-page="' + (current + 1) + '" rel="next">Next</a>';
    }
    html += '<span class="mgdb-pagination-status">Page ' + current + ' of ' + last + '</span>';

    els.pagination.innerHTML = html;
    show(els.pagination, true);
  }

  /* The three "found nothing" cases the legacy page distinguished. Each sends
     the reader somewhere different, so they are worth keeping apart. */
  function renderEmpty(payload) {
    var title = 'No pan-genes found';
    var body = 'Check the spelling, or try a different identifier.';
    var actionLabel = '';
    var actionHref = '';

    if (payload.reason === 'no-term') {
      title = 'Enter a search term';
      body = 'Type a locus symbol, gene model, transcript, or protein identifier above.';
    } else if (payload.reason === 'no-filters') {
      title = 'No filters were set';
      body = 'Fill in at least one field in the advanced search before running it.';
    } else if (payload.reason === 'obsolete') {
      title = 'That gene model is obsolete';
      body = 'It was retired from its annotation, so it is not in a pan-gene. Look in the download directory for your assembly of interest to see whether a cross-reference file links preliminary and official gene model IDs.';
      actionLabel = 'Open the download directory';
      actionHref = 'https://download.maizegdb.org';
    } else if (payload.reason === 'singleton') {
      title = 'That gene model is a singleton';
      body = 'It exists, but the analysis did not place it in a pan-gene with any other gene model. The Gene Center holds the rest of what is known about it.';
      actionLabel = 'Open the Gene Center';
      actionHref = '/gene_center/gene';
    } else if (payload.mode === 'simple') {
      body = 'Nothing matched ' + payload.query.term +
             '. If you believe it is a valid gene model or locus, the Gene Center may still have information about it.';
      actionLabel = 'Search the Gene Center';
      actionHref = '/gene_center/gene';
    } else {
      body = 'No pan-gene matched every one of those filters. Try relaxing the narrowest one.';
    }

    els.emptyTitle.textContent = title;
    els.emptyBody.textContent = body;
    if (actionLabel) {
      els.emptyAction.textContent = actionLabel;
      els.emptyAction.href = actionHref;
      show(els.emptyAction, true);
    } else {
      show(els.emptyAction, false);
    }
    show(els.empty, true);
  }

  function renderCriteria(payload) {
    if (!els.criteria) { return; }
    if (!payload.criteria || !payload.criteria.length || payload.mode !== 'advanced') {
      show(els.criteria, false);
      return;
    }
    els.criteria.innerHTML = '<strong>Searching for pan-genes</strong> ' +
      escape(payload.criteria.join(', and ')) + '.';
    show(els.criteria, true);
  }

  function render(payload) {
    var summary = payload.summary || {};
    var total = summary.total || 0;

    show(els.loading, false);
    show(els.error, false);
    show(els.single, false);
    renderCriteria(payload);

    if (total === 0) {
      els.rows.innerHTML = '';
      show(els.tableWrap, false);
      show(els.pagination, false);
      show(els.sortWrap, false);
      renderEmpty(payload);
      els.status.textContent = payload.reason === 'no-term' || payload.reason === 'no-filters'
        ? '' : 'No matching pan-genes.';
      MGDB.announce('No matching pan-genes.');
      return;
    }

    show(els.empty, false);
    renderRows(payload.results);
    show(els.tableWrap, true);
    show(els.sortWrap, true);
    renderPagination(summary);

    // Exactly one match: the legacy page jumped straight to the record. Offer
    // the same destination as the primary action instead of navigating away
    // from the search the reader just ran.
    if (total === 1 && payload.mode === 'simple') {
      renderSingle(payload.results[0]);
    }

    var first = (summary.page - 1) * summary.page_size + 1;
    var last = first + payload.results.length - 1;
    var message = total.toLocaleString() + ' matching pan-gene' + (total === 1 ? '' : 's');
    if (summary.page_count > 1) {
      message += ' &mdash; showing ' + first.toLocaleString() + ' to ' + last.toLocaleString();
    }
    els.status.innerHTML = message;
    MGDB.announce(total.toLocaleString() + ' matching pan-genes');
  }

  /* ------------------------------------------------------------------------
     Running a search
     ------------------------------------------------------------------------ */

  function runSearch() {
    show(els.empty, false);
    show(els.error, false);
    show(els.single, false);
    show(els.loading, true);
    els.status.textContent = 'Searching…';

    MGDB.request(API + '?' + buildQuery().toString(), { key: 'pan-gene-search' })
      .then(function (payload) {
        if (!payload || !payload.ok) { throw new Error('search failed'); }
        render(payload);
        syncUrl();
      })
      .catch(function (error) {
        // An aborted request is a newer search superseding this one, not a
        // failure the reader needs to hear about.
        if (error && error.name === 'AbortError') { return; }
        show(els.loading, false);
        show(els.tableWrap, false);
        show(els.empty, false);
        show(els.error, true);
        els.status.textContent = 'The search could not be completed.';
      });
  }

  function searchSimple(term) {
    state.mode = 'simple';
    state.term = term;
    state.page = 1;
    runSearch();
  }

  function searchAdvanced() {
    state.mode = 'advanced';
    state.filters = readAdvancedForm();
    state.page = 1;
    runSearch();
  }

  /* ------------------------------------------------------------------------
     Wiring
     ------------------------------------------------------------------------ */

  function init() {
    els = {
      form: byId('pan-gene-form'),
      term: byId('pan-gene-term'),
      clear: byId('pan-gene-clear'),
      advanced: byId('pan-gene-advanced'),
      advancedForm: byId('pan-gene-advanced-form'),
      advancedClear: byId('pan-gene-advanced-clear'),
      sort: byId('pan-gene-sort'),
      sortWrap: byId('pan-gene-sort-wrap'),
      status: byId('pan-gene-status'),
      criteria: byId('pan-gene-criteria'),
      loading: byId('pan-gene-loading'),
      single: byId('pan-gene-single'),
      tableWrap: byId('pan-gene-table-wrap'),
      rows: byId('pan-gene-rows'),
      empty: byId('pan-gene-empty'),
      emptyTitle: byId('pan-gene-empty-title'),
      emptyBody: byId('pan-gene-empty-body'),
      emptyAction: byId('pan-gene-empty-action'),
      error: byId('pan-gene-error'),
      pagination: byId('pan-gene-pagination')
    };

    buildTabs();
    buildDistribution();

    if (!els.form || !els.rows) { return; }

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
        els.rows.innerHTML = '';
        show(els.tableWrap, false);
        show(els.pagination, false);
        show(els.single, false);
        show(els.empty, false);
        show(els.sortWrap, false);
        show(els.criteria, false);
        els.status.textContent = 'Enter an identifier above, or open the advanced search, to begin.';
        syncUrl();
      });
    }

    Array.prototype.forEach.call(
      document.querySelectorAll('[data-pan-gene-example]'), function (button) {
        button.addEventListener('click', function () {
          var term = button.getAttribute('data-pan-gene-example');
          els.term.value = term;
          searchSimple(term);
        });
      });

    if (els.advancedForm) {
      els.advancedForm.addEventListener('submit', function (event) {
        event.preventDefault();
        searchAdvanced();
      });
    }

    if (els.advancedClear) {
      els.advancedClear.addEventListener('click', function () {
        clearAdvancedForm();
        state.filters = {};
      });
    }

    if (els.sort) {
      els.sort.addEventListener('change', function () {
        state.sort = els.sort.value;
        state.page = 1;
        runSearch();
      });
    }

    if (els.pagination) {
      els.pagination.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('[data-pan-gene-page]') : null;
        if (!link) { return; }
        var page = parseInt(link.getAttribute('data-pan-gene-page'), 10);
        if (isNaN(page)) { return; }
        state.page = page;
        runSearch();
      });
    }

    var downloadExample = byId('pan-gene-download-example');
    if (downloadExample) {
      downloadExample.addEventListener('click', function () {
        var field = byId('pan-gene-download-list');
        if (field) {
          field.value = ['Zm00001eb269630', 'Zm00001eb156130', 'Zm00001eb124920',
                         'Zm00001eb127100', 'Zm00001eb047750'].join('\n');
          field.focus();
        }
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
