/* Insertion Data Center JavaScript (/insertion)
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
    document.querySelectorAll('.ins-example').forEach(function (button) {
      button.addEventListener('click', function () {
        var key = button.dataset.example;
        var form = button.closest('form');
        if (key === 'genes') { byId('ins-gene-list').value = EXAMPLES.genes; }
        if (key === 'names') { byId('ins-stock-list').value = EXAMPLES.names; }
        if (form) { form.dispatchEvent(new Event('submit', { cancelable: true })); }
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

    if (mode === 'gene') {
      params.set('genes', byId('ins-gene-list').value);
      params.set('dataset', byId('ins-gene-dataset').value);
      params.set('background', byId('ins-gene-background').value);
      params.set('structure', byId('ins-gene-structure').value);
    } else if (mode === 'region') {
      params.set('assembly', byId('ins-region-assembly').value);
      params.set('chromosome', byId('ins-region-chromosome').value);
      params.set('start', byId('ins-region-start').value.replace(/,/g, ''));
      params.set('end', byId('ins-region-end').value.replace(/,/g, ''));
      params.set('dataset', byId('ins-region-dataset').value);
      params.set('background', byId('ins-region-background').value);
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
      })
      .catch(function () {
        resultsEl.innerHTML = '';
        notesEl.innerHTML = '<div class="mgdb-message mgdb-message-error" role="alert">The search request failed. Please try again.</div>';
        statusEl.textContent = 'Search failed.';
      });
  }

  function renderResults(data) {
    var statusEl = byId('insertion-results-status');
    var notesEl = byId('insertion-notes');
    var resultsEl = byId('insertion-results');
    var emptyEl = byId('insertion-empty');
    var exportLink = byId('insertion-export-tsv');

    var notesHtml = '';
    (data.notes || []).forEach(function (note) {
      var kind = note.code === 'truncated' ? 'mgdb-message-info' : 'mgdb-message-info';
      notesHtml += '<div class="mgdb-message ' + kind + '" role="status">' + esc(note.detail) + '</div>';
    });
    notesEl.innerHTML = notesHtml;

    var total = data.summary && data.summary.total ? data.summary.total : 0;
    var elapsed = data.summary && data.summary.elapsed_ms != null ? data.summary.elapsed_ms : null;
    var withStock = data.summary && data.summary.with_stock ? data.summary.with_stock : 0;

    if (!total) {
      resultsEl.innerHTML = '';
      emptyEl.hidden = false;
      statusEl.textContent = 'No insertions matched.';
      return;
    }

    statusEl.textContent = number(total) + ' insertion' + (total === 1 ? '' : 's') + ' found'
      + (withStock ? ', ' + number(withStock) + ' with a seed stock' : '')
      + (elapsed != null ? ' (' + elapsed + ' ms)' : '') + '.';

    resultsEl.innerHTML = buildTableHtml(data.results);
    exportLink.href = API_URL + '?' + lastQuery + '&format=tsv';
    exportLink.hidden = false;
  }

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

  /* ── Section Navigation Tabs & Scrollspy ────────────────────────────────── */

  function initSectionTabs() {
    var nav = document.querySelector('.mgdb-section-tabs');
    if (!nav) return;
    var links = nav.querySelectorAll('a[href^="#"]');
    if (!links.length) return;

    var sections = [];
    Array.prototype.forEach.call(links, function (link) {
      var id = link.getAttribute('href').slice(1);
      var el = document.getElementById(id);
      if (el) sections.push({ id: id, link: link, el: el });
    });

    if (!('IntersectionObserver' in window)) return;

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          sections.forEach(function (s) {
            var current = s.el === entry.target;
            s.link.classList.toggle('is-current', current);
            if (current) {
              s.link.setAttribute('aria-current', 'true');
            } else {
              s.link.removeAttribute('aria-current');
            }
          });
        }
      });
    }, { rootMargin: '-20% 0px -70% 0px' });

    sections.forEach(function (s) { observer.observe(s.el); });
  }

  /* ── Init ────────────────────────────────────────────────────────────── */

  function init() {
    initSectionTabs();
    initTabs();
    initBackgroundSync('ins-gene-dataset', 'ins-gene-background');
    initBackgroundSync('ins-region-dataset', 'ins-region-background');
    initExamples();
    initForms();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
