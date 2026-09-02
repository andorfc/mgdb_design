/* Insertion Data Hub JavaScript (/insertion)
   Handles the three search-mode tabs, dataset-dependent background options,
   example fill-ins, the live search request, results rendering, and the TSV
   export link. */

(function () {
  'use strict';

  var API_URL = '/search/insertion/insertion_search_api.php';

  var BACKGROUNDS = {
    'UniformMu': ['W22'],
    'BonnMu': ['B73', 'Co125', 'DK105', 'EP1', 'F7'],
    'Dooner-Du Ac/Ds': ['W22-polymorphic'],
    'Volbrecht Ac/Ds': ['W22']
  };

  var EXAMPLES = {
    genes: 'Zm00001eb228780, Zm00001eb064660, Zm00001eb000240, Zm00001eb000270, Zm00001eb374220',
    names: 'mu1000002, mu1001742, BonnMu0266339, AcDs-I.S08.4225, tdsgR197F10'
  };

  var lastQuery = null;

  function byId(id) { return document.getElementById(id); }

  function esc(str) {
    if (!str && str !== 0) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function number(n) { return Number(n || 0).toLocaleString(); }

  /* ── Tabs ────────────────────────────────────────────────────────────── */

  function initTabs() {
    var tabs = document.querySelectorAll('.ins-tab');
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () { selectMode(tab.dataset.mode); });
    });
  }

  function selectMode(mode) {
    document.querySelectorAll('.ins-tab').forEach(function (tab) {
      var active = tab.dataset.mode === mode;
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
      tab.tabIndex = active ? 0 : -1;
    });
    document.querySelectorAll('.ins-search-form').forEach(function (form) {
      form.hidden = form.dataset.mode !== mode;
    });
  }

  /* ── Dataset -> background options ──────────────────────────────────── */

  function initBackgroundSync(datasetId, backgroundId) {
    var datasetSelect = byId(datasetId);
    var backgroundSelect = byId(backgroundId);
    if (!datasetSelect || !backgroundSelect) return;

    function sync() {
      var options = BACKGROUNDS[datasetSelect.value] || [];
      backgroundSelect.innerHTML = '<option value="">Any</option>';
      options.forEach(function (name) {
        var opt = document.createElement('option');
        opt.value = name;
        opt.textContent = name;
        backgroundSelect.appendChild(opt);
      });
    }

    datasetSelect.addEventListener('change', sync);
    sync();
  }

  /* ── Examples ────────────────────────────────────────────────────────── */

  function initExamples() {
    document.querySelectorAll('[data-ins-example]').forEach(function (button) {
      button.addEventListener('click', function () {
        var key = button.getAttribute('data-ins-example');
        var form = button.closest('form');

        if (key === 'genes') { byId('ins-gene-list').value = EXAMPLES.genes; }
        else if (key === 'names') { byId('ins-stock-list').value = EXAMPLES.names; }
        else if (key === 'region') {
          byId('ins-region-chromosome').value = 'chr1';
          byId('ins-region-start').value = '4897501';
          byId('ins-region-end').value = '5413000';
        } else if (byId('ins-gene-list')) {
          // Anything else is a single gene model typed into the example.
          byId('ins-gene-list').value = key;
        }

        if (form) { form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true })); }
      });
    });
  }

  /* ── Forms ───────────────────────────────────────────────────────────── */

  function initForms() {
    document.querySelectorAll('.ins-search-form').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        runSearch(form);
      });
    });
  }

  function buildParams(form) {
    var mode = form.dataset.mode;
    var params = new URLSearchParams();
    params.set('mode', mode);

    /* Collection, background and gene structure live in one advanced panel
       shared by every mode, rather than being repeated per panel. The gene and
       region modes both honour collection and background; structure only means
       anything against a gene, and the panel says so. */
    var dataset = byId('ins-dataset');
    var background = byId('ins-background');
    var structure = byId('ins-structure');

    if (mode === 'gene') {
      params.set('genes', byId('ins-gene-list').value);
      if (dataset) { params.set('dataset', dataset.value); }
      if (background) { params.set('background', background.value); }
      if (structure) { params.set('structure', structure.value); }
    } else if (mode === 'region') {
      params.set('assembly', byId('ins-region-assembly').value);
      params.set('chromosome', byId('ins-region-chromosome').value);
      params.set('start', byId('ins-region-start').value.replace(/,/g, ''));
      params.set('end', byId('ins-region-end').value.replace(/,/g, ''));
      if (dataset) { params.set('dataset', dataset.value); }
      if (background) { params.set('background', background.value); }
    } else if (mode === 'stock') {
      params.set('names', byId('ins-stock-list').value);
    }

    return params;
  }

  function validate(mode, params) {
    if (mode === 'gene' && !params.get('genes').trim()) {
      return 'Enter at least one gene model or transcript.';
    }
    if (mode === 'region') {
      if (!params.get('chromosome')) { return 'Choose a chromosome.'; }
      if (!params.get('start') || !params.get('end')) { return 'Enter both a start and an end coordinate.'; }
      if (!/^\d+$/.test(params.get('start')) || !/^\d+$/.test(params.get('end'))) {
        return 'Start and end must be whole numbers.';
      }
    }
    if (mode === 'stock' && !params.get('names').trim()) {
      return 'Enter at least one insertion identifier.';
    }
    return null;
  }

  function runSearch(form) {
    var mode = form.dataset.mode;
    var params = buildParams(form);
    var error = validate(mode, params);
    var statusEl = byId('insertion-results-status');
    var notesEl = byId('insertion-notes');
    var resultsEl = byId('insertion-results');
    var emptyEl = byId('insertion-empty');
    var exportLink = byId('insertion-export-tsv');

    notesEl.innerHTML = '';
    emptyEl.hidden = true;
    exportLink.hidden = true;

    var section = byId('insertion-results-section');
    if (section) { section.hidden = false; }

    if (error) {
      notesEl.innerHTML = '<div class="mgdb-message mgdb-message-error" role="alert">' + esc(error) + '</div>';
      return;
    }

    resultsEl.innerHTML = '<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span>Searching&hellip;</div>';
    statusEl.textContent = 'Searching…';

    lastQuery = params.toString();

    fetch(API_URL + '?' + lastQuery)
      .then(function (response) { return response.json().then(function (data) { return { ok: response.ok, data: data }; }); })
      .then(function (wrap) {
        if (!wrap.ok || !wrap.data.ok) {
          var message = (wrap.data && (wrap.data.message || wrap.data.detail)) || 'The search could not be completed.';
          resultsEl.innerHTML = '';
          notesEl.innerHTML = '<div class="mgdb-message mgdb-message-error" role="alert">' + esc(message) + '</div>';
          statusEl.textContent = 'Search failed.';
          return;
        }
        renderResults(wrap.data);
        if (section) { section.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
      })
      .catch(function () {
        resultsEl.innerHTML = '';
        notesEl.innerHTML = '<div class="mgdb-message mgdb-message-error" role="alert">The search request failed. Please try again.</div>';
        statusEl.textContent = 'Search failed.';
      });
  }

  var currentData = null;
  var currentGroupBy = 'insertion';

  function renderResults(data) {
    currentData = data;
    var notesEl = byId('insertion-notes');
    var emptyEl = byId('insertion-empty');
    var exportLink = byId('insertion-export-tsv');

    var notesHtml = '';
    (data.notes || []).forEach(function (note) {
      var kind = note.code === 'truncated' ? 'mgdb-message-info' : 'mgdb-message-info';
      notesHtml += '<div class="mgdb-message ' + kind + '" role="status">' + esc(note.detail) + '</div>';
    });
    notesEl.innerHTML = notesHtml;

    var total = data.summary && data.summary.total ? data.summary.total : 0;
    if (!total) {
      byId('insertion-results').innerHTML = '';
      emptyEl.hidden = false;
      exportLink.hidden = true;
      byId('insertion-results-status').textContent = 'No insertions matched.';
      return;
    }

    emptyEl.hidden = true;
    exportLink.hidden = false;
    updateView();
  }

  function updateView() {
    if (!currentData) return;
    var statusEl = byId('insertion-results-status');
    var resultsEl = byId('insertion-results');
    var exportLink = byId('insertion-export-tsv');

    var total = currentData.summary && currentData.summary.total ? currentData.summary.total : 0;
    var elapsed = currentData.summary && currentData.summary.elapsed_ms != null ? currentData.summary.elapsed_ms : null;
    var withStock = currentData.summary && currentData.summary.with_stock ? currentData.summary.with_stock : 0;

    if (currentGroupBy === 'gene') {
      var geneGroups = groupResultsByGene(currentData.results);
      statusEl.textContent = number(geneGroups.length) + ' gene model' + (geneGroups.length === 1 ? '' : 's')
        + ' with ' + number(total) + ' insertion' + (total === 1 ? '' : 's')
        + (withStock ? ', ' + number(withStock) + ' with a seed stock' : '')
        + (elapsed != null ? ' (' + elapsed + ' ms)' : '') + '.';
      resultsEl.innerHTML = buildGeneTableHtml(geneGroups);
      exportLink.href = API_URL + '?' + lastQuery + '&format=tsv&group_by=gene';
    } else {
      statusEl.textContent = number(total) + ' insertion' + (total === 1 ? '' : 's') + ' found'
        + (withStock ? ', ' + number(withStock) + ' with a seed stock' : '')
        + (elapsed != null ? ' (' + elapsed + ' ms)' : '') + '.';
      resultsEl.innerHTML = buildTableHtml(currentData.results);
      exportLink.href = API_URL + '?' + lastQuery + '&format=tsv&group_by=insertion';
    }

    applyResultView();
  }

  /* ── Page size, paging and the within-results filter ────────────────────

     The endpoint answers a whole search at once -- it is bounded by the caps
     in insertion_search_lib.php rather than paged -- so the page size and the
     filter both work on the rendered rows. That keeps paging instant and means
     the export always covers the whole result rather than the visible page. */

  var viewState = { page: 1, pageSize: 25, filter: '' };

  function currentRows() {
    var resultsEl = byId('insertion-results');
    return resultsEl ? Array.prototype.slice.call(resultsEl.querySelectorAll('tbody tr')) : [];
  }

  function applyResultView() {
    var rows = currentRows();
    if (!rows.length) { renderPagination(0); return; }

    var terms = viewState.filter.toLowerCase().split(/\s+/).filter(Boolean);
    var matched = rows.filter(function (row) {
      if (!terms.length) { return true; }
      var hay = (row.textContent || '').toLowerCase();
      for (var i = 0; i < terms.length; i++) {
        if (hay.indexOf(terms[i]) === -1) { return false; }
      }
      return true;
    });

    var size = viewState.pageSize === 'all' ? matched.length || 1 : viewState.pageSize;
    var pageCount = Math.max(1, Math.ceil(matched.length / size));
    if (viewState.page > pageCount) { viewState.page = pageCount; }
    var start = (viewState.page - 1) * size;

    rows.forEach(function (row) { row.hidden = true; });
    matched.slice(start, start + size).forEach(function (row) { row.hidden = false; });

    appendViewStatus(matched.length, rows.length, start, Math.min(size, matched.length - start));
    renderPagination(pageCount);
  }

  /* The grouping status line is written by updateView; this adds what the page
     controls did to it, so the two never contradict each other. */
  function appendViewStatus(matched, total, start, shown) {
    var statusEl = byId('insertion-results-status');
    if (!statusEl) { return; }

    var base = statusEl.textContent.replace(/\s*Showing .*$/, '').replace(/\s*Nothing on this .*$/, '');
    if (viewState.filter && matched === 0) {
      statusEl.textContent = base + ' Nothing on this page matches the filter \u201C' + viewState.filter + '\u201D.';
      return;
    }
    if (viewState.filter) {
      statusEl.textContent = base + ' Showing ' + number(shown) + ' of the ' + number(matched)
        + ' rows matching \u201C' + viewState.filter + '\u201D, out of ' + number(total) + '.';
      return;
    }
    if (viewState.pageSize !== 'all' && matched > shown) {
      statusEl.textContent = base + ' Showing rows ' + number(start + 1) + '\u2013' + number(start + shown)
        + ' of ' + number(matched) + '.';
    }
  }

  function renderPagination(pageCount) {
    var nav = byId('insertion-pagination');
    if (!nav) { return; }

    if (viewState.pageSize === 'all' || pageCount <= 1) { nav.innerHTML = ''; return; }

    var current = viewState.page;
    var html = '<button class="ins-page-btn" type="button" data-page="' + (current - 1) + '"'
             + (current === 1 ? ' disabled' : '') + '>&larr; Previous</button>';

    var pages = [1];
    if (current > 3) { pages.push('gap'); }
    for (var pnum = Math.max(2, current - 1); pnum <= Math.min(pageCount - 1, current + 1); pnum++) { pages.push(pnum); }
    if (current < pageCount - 2) { pages.push('gap'); }
    if (pageCount > 1) { pages.push(pageCount); }

    pages.forEach(function (page) {
      if (page === 'gap') {
        html += '<span class="ins-page-ellipsis" aria-hidden="true">&hellip;</span>';
      } else {
        html += '<button class="ins-page-btn' + (page === current ? ' is-active' : '') + '"'
             +  ' type="button" data-page="' + page + '"'
             +  (page === current ? ' aria-current="page"' : '') + '>' + page + '</button>';
      }
    });

    html += '<button class="ins-page-btn" type="button" data-page="' + (current + 1) + '"'
         +  (current === pageCount ? ' disabled' : '') + '>Next &rarr;</button>';

    nav.innerHTML = html;

    Array.prototype.forEach.call(nav.querySelectorAll('button[data-page]'), function (btn) {
      btn.addEventListener('click', function () {
        var page = parseInt(btn.getAttribute('data-page'), 10);
        if (!page || page < 1 || page > pageCount || page === viewState.page) { return; }
        viewState.page = page;
        applyResultView();
      });
    });
  }

  function initResultControls() {
    var sizeSelect = byId('ins-page-size');
    if (sizeSelect) {
      sizeSelect.addEventListener('change', function () {
        viewState.pageSize = sizeSelect.value === 'all' ? 'all' : parseInt(sizeSelect.value, 10) || 25;
        viewState.page = 1;
        if (currentData) { updateView(); }
      });
    }

    var filterInput = byId('ins-results-filter');
    if (filterInput) {
      filterInput.addEventListener('input', function () {
        viewState.filter = filterInput.value.trim();
        viewState.page = 1;
        if (currentData) { updateView(); }
      });
    }

    var advReset = byId('ins-adv-reset');
    if (advReset) {
      advReset.addEventListener('click', function () {
        ['ins-dataset', 'ins-background', 'ins-structure'].forEach(function (id) {
          var el = byId(id);
          if (el) { el.selectedIndex = 0; }
        });
      });
    }

    var emptyReset = byId('insertion-empty-reset');
    if (emptyReset) {
      emptyReset.addEventListener('click', function () {
        ['ins-gene-list', 'ins-stock-list', 'ins-region-start', 'ins-region-end'].forEach(function (id) {
          var el = byId(id);
          if (el) { el.value = ''; }
        });
        if (filterInput) { filterInput.value = ''; }
        viewState.filter = '';
        viewState.page = 1;
        var section = byId('insertion-results-section');
        if (section) { section.hidden = true; }
        var focusTarget = byId('ins-gene-list');
        if (focusTarget) { focusTarget.focus(); }
      });
    }
  }

  /* ── Alignments by gene structure ───────────────────────────────────────

     .mgdb-chart is a fixed 320px in the design system, so the height has to be
     set on the element and handed to Plotly from the same variable. */

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
    var el = byId('ins-structure-chart');
    if (!el || !window.MGDB || !window.MGDB.chart) { return; }

    var labels = readAttrJson(el, 'data-labels');
    var values = readAttrJson(el, 'data-values');
    if (!labels || !values || !labels.length) { return; }

    var height = sizeChart('ins-structure-chart', Math.max(320, labels.length * 34 + 110));

    window.MGDB.chart({
      target: 'ins-structure-chart',
      traces: [{
        type: 'bar',
        orientation: 'h',
        x: values,
        y: labels,
        text: values.map(function (value) { return '\u00A0' + Number(value).toLocaleString(); }),
        textposition: 'outside',
        textangle: 0,
        cliponaxis: false,
        marker: { color: '#285d46' },
        hovertemplate: '%{y}<br>%{x:,} alignments<extra></extra>'
      }],
      layout: {
        height: height,
        margin: { l: 10, r: 96, t: 8, b: 48 },
        bargap: 0.28,
        xaxis: { title: { text: 'Insertion alignments' }, automargin: true },
        yaxis: { type: 'category', automargin: true }
      }
    });

    /* Selecting a bar sets the gene-structure filter and opens the panel, so
       the filter is visible rather than applied invisibly. Plotly only gains
       its event emitter once it has drawn. */
    if (!window.MutationObserver) { return; }
    var attached = false;
    var observer = new window.MutationObserver(function () {
      if (attached || typeof el.on !== 'function') { return; }
      attached = true;
      observer.disconnect();
      el.on('plotly_click', function (event) {
        if (!event || !event.points || !event.points.length) { return; }
        var wanted = String(event.points[0].y).toLowerCase();
        var select = byId('ins-structure');
        if (!select) { return; }
        Array.prototype.forEach.call(select.options, function (option) {
          if (option.textContent.trim().toLowerCase() === wanted) { select.value = option.value; }
        });
        var adv = byId('ins-adv');
        if (adv) { adv.open = true; }
        var geneTab = byId('ins-tab-gene');
        if (geneTab) { geneTab.click(); }
        var panel = byId('ins-panel-gene');
        if (panel) { panel.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
      });
    });
    observer.observe(el, { childList: true, subtree: true });
  }

  /* ── Group By Gene Model ────────────────────────────────────────────────── */

  function groupResultsByGene(results) {
    var geneMap = {};
    var geneOrder = [];

    results.forEach(function (result) {
      var alignments = result.alignments || [];
      if (!alignments.length) {
        var key = '__intergenic__';
        if (!geneMap[key]) {
          geneMap[key] = {
            key: key,
            gene: null,
            gene_url: null,
            alignments: [],
            stocks: []
          };
          geneOrder.push(key);
        }
        geneMap[key].alignments.push({
          insertion_id: result.id,
          insertion_name: result.name,
          insertion_url: result.url,
          status: result.status,
          dataset: '',
          assembly_label: '',
          chromosome: '',
          start: null,
          end: null,
          structures: ''
        });
        (result.stocks || []).forEach(function (st) {
          if (!geneMap[key].stocks.some(function (s) { return s.id === st.id; })) {
            geneMap[key].stocks.push(st);
          }
        });
        return;
      }

      alignments.forEach(function (place) {
        var key = place.gene || '__intergenic__';
        if (!geneMap[key]) {
          geneMap[key] = {
            key: key,
            gene: place.gene || null,
            gene_url: place.gene_url || null,
            alignments: [],
            stocks: []
          };
          geneOrder.push(key);
        }
        geneMap[key].alignments.push({
          insertion_id: result.id,
          insertion_name: result.name,
          insertion_url: result.url,
          status: result.status,
          dataset: place.dataset,
          assembly_label: place.assembly_label,
          chromosome: place.chromosome,
          start: place.start,
          end: place.end,
          structures: place.structures
        });
        (result.stocks || []).forEach(function (st) {
          if (!geneMap[key].stocks.some(function (s) { return s.id === st.id; })) {
            geneMap[key].stocks.push(st);
          }
        });
      });
    });

    geneOrder.sort(function (a, b) {
      if (a === '__intergenic__') return 1;
      if (b === '__intergenic__') return -1;
      return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
    });

    return geneOrder.map(function (k) { return geneMap[k]; });
  }

  function buildGeneTableHtml(geneGroups) {
    var rows = geneGroups.map(function (group) {
      return '<tr>' + buildGeneIdentityCell(group) + buildGeneInsertionsCell(group) + buildGeneStocksCell(group) + '</tr>';
    }).join('');

    return '<div class="mgdb-table-scroll" tabindex="0">'
      + '<table class="mgdb-table ins-table">'
      + '<caption>Matching gene models<span class="mgdb-muted">' + number(geneGroups.length) + ' shown</span></caption>'
      + '<thead><tr><th scope="col">Gene model</th><th scope="col">Insertions &amp; alignments</th><th scope="col">Seed stocks</th></tr></thead>'
      + '<tbody>' + rows + '</tbody>'
      + '</table></div>';
  }

  function buildGeneIdentityCell(group) {
    if (!group.gene) {
      return '<td scope="row" class="ins-gene-cell"><strong class="mgdb-muted">Intergenic / Unassigned</strong><span>'
        + number(group.alignments.length) + ' insertion' + (group.alignments.length === 1 ? '' : 's') + '</span></td>';
    }
    var link = group.gene_url
      ? '<a href="' + esc(group.gene_url) + '"><strong>' + esc(group.gene) + '</strong></a>'
      : '<strong>' + esc(group.gene) + '</strong>';
    var count = '<span>' + number(group.alignments.length) + ' insertion' + (group.alignments.length === 1 ? '' : 's') + '</span>';
    return '<td scope="row" class="ins-gene-cell">' + link + count + '</td>';
  }

  function buildGeneInsertionsCell(group) {
    if (!group.alignments || !group.alignments.length) {
      return '<td class="mgdb-muted">No insertions on file</td>';
    }
    var items = group.alignments.map(function (al) {
      var insName = al.insertion_name ? esc(al.insertion_name) : '(unnamed)';
      var insLink = al.insertion_url ? '<a href="' + esc(al.insertion_url) + '">' + insName + '</a>' : insName;
      var badge = al.status === 'withdrawn' ? ' <span class="mgdb-pill mgdb-pill-warn">Withdrawn</span>' : '';
      var dsTag = al.dataset ? '<span class="ins-dataset-tag">' + esc(al.dataset) + '</span>' : '';
      var coords = (al.chromosome ? esc(al.chromosome) : '')
        + (al.start != null ? ':' + number(al.start) + '–' + number(al.end) : '');
      var asm = al.assembly_label ? esc(al.assembly_label) : '';
      var struct = al.structures ? ' &middot; ' + esc(al.structures) : '';

      return '<li>' + dsTag + insLink + badge
        + (asm ? ' &middot; ' + asm : '')
        + (coords ? ' &middot; ' + coords : '')
        + struct + '</li>';
    }).join('');

    return '<td><ul class="ins-alignment-list">' + items + '</ul></td>';
  }

  function buildGeneStocksCell(group) {
    if (!group.stocks || !group.stocks.length) {
      return '<td class="mgdb-muted">None on file</td>';
    }
    var items = group.stocks.map(function (stock) {
      var link = '<a href="' + esc(stock.url) + '">' + esc(stock.name) + '</a>';
      var bg = stock.background ? ' (' + esc(stock.background) + ')' : '';
      var status = stock.available
        ? ''
        : ' <span class="ins-stock-withdrawn">unavailable</span>';
      return '<li>' + link + bg + status + '</li>';
    }).join('');
    return '<td><ul class="ins-stock-list">' + items + '</ul></td>';
  }

  /* ── Group By Insertion (Default) ───────────────────────────────────────── */

  function buildTableHtml(results) {
    var rows = results.map(function (result) {
      return '<tr>' + buildIdentityCell(result) + buildAlignmentsCell(result) + buildStocksCell(result) + '</tr>';
    }).join('');

    return '<div class="mgdb-table-scroll" tabindex="0">'
      + '<table class="mgdb-table ins-table">'
      + '<caption>Matching insertions<span class="mgdb-muted">' + number(results.length) + ' shown</span></caption>'
      + '<thead><tr><th scope="col">Insertion</th><th scope="col">Alignments</th><th scope="col">Seed stocks</th></tr></thead>'
      + '<tbody>' + rows + '</tbody>'
      + '</table></div>';
  }

  function buildIdentityCell(result) {
    var name = result.name ? esc(result.name) : '(unnamed)';
    var link = result.url ? '<a href="' + esc(result.url) + '">' + name + '</a>' : name;
    var badge = result.status === 'withdrawn'
      ? ' <span class="mgdb-pill mgdb-pill-warn">Withdrawn</span>'
      : '';
    return '<td scope="row">' + link + badge + '</td>';
  }

  function buildAlignmentsCell(result) {
    if (!result.alignments || !result.alignments.length) {
      return '<td class="mgdb-muted">No genome alignment on file</td>';
    }
    var items = result.alignments.map(function (place) {
      var gene = place.gene_url
        ? '<a href="' + esc(place.gene_url) + '">' + esc(place.gene) + '</a>'
        : esc(place.gene || 'intergenic');
      var coords = (place.chromosome ? esc(place.chromosome) : '')
        + (place.start != null ? ':' + number(place.start) + '–' + number(place.end) : '');
      return '<li><span class="ins-dataset-tag">' + esc(place.dataset) + '</span>'
        + esc(place.assembly_label) + ' &middot; ' + gene
        + (coords ? ' &middot; ' + coords : '')
        + (place.structures ? ' &middot; ' + esc(place.structures) : '') + '</li>';
    }).join('');
    return '<td><ul class="ins-alignment-list">' + items + '</ul></td>';
  }

  function buildStocksCell(result) {
    if (!result.stocks || !result.stocks.length) {
      return '<td class="mgdb-muted">None on file</td>';
    }
    var items = result.stocks.map(function (stock) {
      var link = '<a href="' + esc(stock.url) + '">' + esc(stock.name) + '</a>';
      var bg = stock.background ? ' (' + esc(stock.background) + ')' : '';
      var status = stock.available
        ? ''
        : ' <span class="ins-stock-withdrawn">unavailable</span>';
      return '<li>' + link + bg + status + '</li>';
    }).join('');
    return '<td><ul class="ins-stock-list">' + items + '</ul></td>';
  }

  /* ── View Toggle Buttons ────────────────────────────────────────────────── */

  function initViewToggle() {
    var buttons = document.querySelectorAll('.ins-view-btn');
    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var group = btn.dataset.group;
        if (!group || group === currentGroupBy) return;
        currentGroupBy = group;
        buttons.forEach(function (b) {
          var active = b.dataset.group === currentGroupBy;
          b.classList.toggle('is-active', active);
          b.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        if (currentData) {
          updateView();
        }
      });
    });
  }

  /* ── Section Navigation Tabs & Scrollspy ────────────────────────────────── */

  /* Sticky section tabs, driven by scroll, IntersectionObserver and resize
     together: no single trigger fires everywhere, and the results section
     appears and disappears under the bar as searches run. The previous version
     used IntersectionObserver alone, which delivers nothing in some embedded
     browsers and left the bar frozen on the first tab. */
  function initSectionTabs() {
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

    var results = byId('insertion-results-section');
    if (results && window.MutationObserver) {
      new window.MutationObserver(update).observe(results, {
        childList: true, subtree: true, attributes: true, attributeFilter: ['hidden']
      });
    }

    update();
  }

  /* ── Init ────────────────────────────────────────────────────────────── */

  function init() {
    initSectionTabs();
    initViewToggle();
    initTabs();
    initBackgroundSync('ins-dataset', 'ins-background');
    initExamples();
    initForms();
    initResultControls();
    initFigure();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
