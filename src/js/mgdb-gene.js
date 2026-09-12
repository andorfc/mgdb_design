/* file: mgdb-gene.js
 *
 * purpose: behavior for the Gene Data Hub (/gene_center/gene).
 *
 *   - simple and advanced search against search/gene/gene_search_api.php
 *   - the three-way region form, whose action and method change with the
 *     selected radio, exactly as gene.js setRegion() did
 *   - client-side validation for the BLAST, bulk position, score and
 *     download-all forms, matching the alerts of the previous page
 *   - the three Plotly figures
 *
 * Every form still posts the same field names to the same endpoint as before,
 * so nothing downstream of this page had to change.
 *
 * Bauplan's includeScript emits into <head>, so the entry point waits for
 * DOMContentLoaded or every query below returns nothing.
 */

(function () {
  'use strict';

  var API = '/search/gene/gene_search_api.php';

  var EXAMPLES = {
    mixed: 'Zm00001eb014280\nZm00001eb165610_T002\nZm00001eb220660\nZm00001eb284010_T003\nZm00001eb411380_P001',
    models: 'Zm00001eb014280\nZm00001eb067740\nZm00001eb165610\n',
    positions: 'Chr3:1349161..1545106\nChr3:1851542..1980827\nChr3:7136721..7414401\nChr3:124200581..124666812',
    scores: 'Zm00001eb014280\nZm00001eb067740\nZm00001eb165610'
    /* No sequence example here: the BLAST form carried in "Sequence and region"
       is the one from /BLAST, and it has its own Load an example button. */
  };

  function byId(id) { return document.getElementById(id); }

  function esc(value) {
    if (value === null || value === undefined || value === '') { return ''; }
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function num(value) { return Number(value || 0).toLocaleString(); }

  function readJson(id) {
    var el = byId(id);
    if (!el) { return null; }
    try { return JSON.parse(el.textContent || 'null'); }
    catch (error) { return null; }
  }

  /* ======================================================================
     Search state & handlers
     ====================================================================== */

  var lastQuery = '';

  var isMobile = window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
  var initialView = isMobile ? 'cards' : 'table';
  if (window.URLSearchParams) {
    var viewParam = new window.URLSearchParams(window.location.search).get('view');
    if (viewParam === 'cards' || viewParam === 'table') {
      initialView = viewParam;
    }
  }

  var state = {
    term: '',
    models: [],
    loci: [],
    pageSize: '25',
    sort: '',
    dir: 'asc',
    filter: '',
    view: initialView,
    summary: {}
  };

  function searchParams(extra) {
    var params = new URLSearchParams();
    var term = byId('gene-search-term');
    var limit = byId('gene-search-limit');

    params.set('term', term ? term.value.trim() : '');
    if (limit && limit.value) { params.set('limit', limit.value); }
    if (extra && extra.broad) { params.set('broad', '1'); }
    return params;
  }

  function setStatus(text) {
    var el = byId('gene-results-status');
    if (el) { el.textContent = text; }
  }

  function showError(target, message) {
    var el = byId(target);
    if (el) {
      el.innerHTML = '<div class="mgdb-message mgdb-message-error" role="alert">' + esc(message) + '</div>';
    }
  }

  function runSearch(options) {
    var term = byId('gene-search-term');
    if (!term || term.value.trim() === '') {
      setStatus('Enter a locus name or an identifier to search.');
      return;
    }

    var params = searchParams(options);
    var section = byId('gene-results-section');
    var results = byId('gene-results');
    var cardsContainer = byId('gene-cards-view');
    var notes = byId('gene-notes');
    var empty = byId('gene-empty');
    var exportLink = byId('gene-export-tsv');

    if (section) { section.hidden = false; }
    if (notes) { notes.innerHTML = ''; }
    if (empty) { empty.hidden = true; }
    if (exportLink) { exportLink.hidden = true; }
    if (results) {
      results.innerHTML = '<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span>Searching gene models&hellip;</div>';
    }
    if (cardsContainer) {
      cardsContainer.innerHTML = '';
    }
    setStatus('Searching…');

    state.term = term.value.trim();
    lastQuery = params.toString();

    fetch(API + '?' + lastQuery, { credentials: 'same-origin' })
      .then(function (response) {
        return response.json().then(function (data) { return { ok: response.ok, data: data }; });
      })
      .then(function (wrap) {
        if (!wrap.ok || !wrap.data || !wrap.data.ok) {
          var message = (wrap.data && (wrap.data.message || wrap.data.detail)) || 'The search could not be completed.';
          if (results) { results.innerHTML = ''; }
          showError('gene-notes', message);
          setStatus('Search failed.');
          return;
        }
        var data = wrap.data;
        state.models = data.models || [];
        state.loci = data.loci || [];
        state.summary = data.summary || {};
        render();
        if (options && options.scroll && section) {
          section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      })
      .catch(function () {
        if (results) { results.innerHTML = ''; }
        showError('gene-notes', 'The search request failed. Please try again.');
        setStatus('Search failed.');
      });
  }

  function sortValue(row, key) {
    switch (key) {
      case 'gene_model': return row.gene_model || '';
      case 'annotation': return row.annotation || '';
      case 'line': return row.line || '';
      case 'locus_name': return row.locus_name || '';
      case 'model_type': return row.model_type || '';
      case 'position': return (row.chromosome || '') + ':' + String(row.start || 0).padStart(12, '0');
      case 'transcripts': return Number(row.transcripts || 0);
      default: return row.gene_model || '';
    }
  }

  function compareModels(a, b) {
    if (!state.sort) { return 0; }
    var dir = state.dir === 'desc' ? -1 : 1;
    var va = sortValue(a, state.sort);
    var vb = sortValue(b, state.sort);
    if (typeof va === 'number' && typeof vb === 'number') {
      return dir * (va - vb);
    }
    return dir * String(va).localeCompare(String(vb), undefined, { numeric: true, sensitivity: 'base' });
  }

  function modelTable(rows, caption) {
    var sortCols = [
      { key: 'gene_model', label: 'Gene model' },
      { key: 'annotation', label: 'Annotation' },
      { key: 'line', label: 'Line' },
      { key: 'locus_name', label: 'Locus' },
      { key: 'model_type', label: 'Type' },
      { key: 'position', label: 'Position' },
      { key: 'transcripts', label: 'Transcripts', numeric: true }
    ];

    var ths = sortCols.map(function (col) {
      var sortAttr = 'none';
      if (state.sort === col.key) {
        sortAttr = state.dir === 'desc' ? 'descending' : 'ascending';
      }
      var cls = col.numeric ? ' class="mgdb-numeric"' : '';
      return '<th scope="col" aria-sort="' + sortAttr + '"' + cls + '>'
        + '<button type="button" data-sort-key="' + col.key + '">' + esc(col.label) + '</button></th>';
    }).join('');

    var html = '<div class="mgdb-table-scroll"><table class="mgdb-table" id="gene-models-table">'
      + '<caption>' + esc(caption) + '</caption><thead><tr>' + ths + '</tr></thead><tbody>';

    rows.forEach(function (row) {
      var position = row.chromosome
        ? esc(row.chromosome) + (row.start ? '&#58;' + num(row.start) + '&ndash;' + num(row.end) : '')
        : '';

      var haystack = [row.gene_model, row.annotation, row.line, row.locus_name, row.model_type, row.chromosome, row.start, row.end, row.transcripts, row.genbank].join(' ').toLowerCase();

      html += '<tr data-search="' + esc(haystack) + '">'
        + '<th scope="row"><a class="gene-model-id" href="' + esc(row.url) + '">' + esc(row.gene_model) + '</a></th>'
        + '<td>' + esc(row.annotation) + '</td>'
        + '<td>' + esc(row.line) + '</td>'
        + '<td>' + (row.locus_name
            ? '<a href="/data_center/locus?id=' + esc(row.locus_id) + '"><i>' + esc(row.locus_name) + '</i></a>'
            : '<span class="mgdb-muted">&mdash;</span>') + '</td>'
        + '<td>' + esc(row.model_type) + '</td>'
        + '<td class="gene-coords">' + position + '</td>'
        + '<td class="mgdb-numeric" data-value="' + (row.transcripts || 0) + '">'
          + (row.transcripts ? num(row.transcripts) : '') + '</td>'
        + '</tr>';
    });

    return html + '</tbody></table></div>';
  }

  /* One gene model per locus, picked in the search library by annotation --
     the current B73 set first, then W22, Mo17, PH207, then a NAM founder. The
     line is carried beside it because for a locus with no B73 model (mab31,
     rf3, tps35) the identifier alone does not say which assembly it is in.

     The MaizeGDB ID column went with it: the number is in the locus link's
     href, in the TSV export and on the locus page itself, and a bare internal
     id was the least useful thing in a row about a named gene. */
  function locusTable(rows) {
    var html = '<div class="mgdb-table-scroll"><table class="mgdb-table" id="gene-loci-table">'
      + '<caption>Gene loci</caption><thead><tr>'
      + '<th scope="col">Locus</th>'
      + '<th scope="col">Full name</th>'
      + '<th scope="col">Current gene model</th>'
      + '<th scope="col" class="mgdb-numeric">Gene models</th>'
      + '<th scope="col" class="mgdb-numeric">Annotations</th>'
      + '</tr></thead><tbody>';

    rows.forEach(function (row) {
      var haystack = [row.locus_name, row.full_name, row.current_model, row.current_line,
                      row.models, row.annotations].join(' ').toLowerCase();
      var current = row.current_model
        ? '<a class="gene-model-id" href="' + esc(row.current_url) + '">' + esc(row.current_model) + '</a>'
          + (row.current_line
              ? '<span class="gene-locus-line">' + esc(row.current_line) + '</span>'
              : '')
        : '<span class="mgdb-muted">&mdash;</span>';

      html += '<tr data-search="' + esc(haystack) + '">'
        + '<th scope="row"><a href="' + esc(row.url) + '"><i>' + esc(row.locus_name) + '</i></a></th>'
        + '<td>' + esc(row.full_name) + '</td>'
        + '<td class="gene-locus-current">' + current + '</td>'
        + '<td class="mgdb-numeric" data-value="' + row.models + '">' + num(row.models) + '</td>'
        + '<td class="mgdb-numeric" data-value="' + row.annotations + '">' + num(row.annotations) + '</td>'
        + '</tr>';
    });

    return html + '</tbody></table></div>';
  }

  function renderModelCard(row) {
    var position = row.chromosome
      ? esc(row.chromosome) + (row.start ? '&#58;' + num(row.start) + '&ndash;' + num(row.end) : '')
      : '';
    var posRaw = row.chromosome && row.start ? row.chromosome + ':' + row.start + '-' + row.end : '';

    var badges = '';
    if (row.annotation) {
      badges += '<span class="mgdb-pill mgdb-pill-accent">' + esc(row.annotation) + '</span>';
    }
    if (row.line) {
      badges += '<span class="mgdb-pill">' + esc(row.line) + '</span>';
    }
    if (row.model_type) {
      badges += '<span class="mgdb-pill">' + esc(row.model_type) + '</span>';
    }

    var metaItems = [];
    if (row.locus_name) {
      metaItems.push('<dt>Locus</dt><dd><a href="/data_center/locus?id=' + esc(row.locus_id) + '"><em>' + esc(row.locus_name) + '</em></a></dd>');
    }
    if (position) {
      metaItems.push('<dt>Position</dt><dd>' + position + (posRaw ? ' <button class="gene-copy-btn" type="button" data-copy-value="' + esc(posRaw) + '">Copy Pos</button>' : '') + '</dd>');
    }
    if (row.transcripts) {
      var txText = num(row.transcripts) + (row.canonical ? ' (canonical&#58; <span class="gene-model-id">' + esc(row.canonical) + '</span>)' : '');
      metaItems.push('<dt>Transcripts</dt><dd>' + txText + '</dd>');
    }
    if (row.genbank) {
      metaItems.push('<dt>GenBank</dt><dd>' + esc(row.genbank) + '</dd>');
    }

    var metaHtml = metaItems.length ? '<dl class="gene-card-meta-list">' + metaItems.join('') + '</dl>' : '';

    var haystack = [row.gene_model, row.annotation, row.line, row.locus_name, row.model_type, row.chromosome, row.canonical, row.genbank].join(' ').toLowerCase();

    var copyButtons = '<button class="gene-copy-btn" type="button" data-copy-value="' + esc(row.gene_model) + '">Copy ID</button>';

    var modelUrl = row.url || ('/gene_center/gene/' + encodeURIComponent(row.gene_model));

    return '<article class="gene-result-card" data-search="' + esc(haystack) + '">' +
      '<div>' +
        '<div class="gene-card-header">' +
          '<h3 class="gene-card-title"><a href="' + esc(modelUrl) + '">' + esc(row.gene_model) + '</a></h3>' +
          '<div class="gene-card-badges">' + badges + '</div>' +
        '</div>' +
        metaHtml +
      '</div>' +
      '<div class="gene-card-actions">' +
        '<a href="' + esc(modelUrl) + '">View gene model &rarr;</a>' +
        '<div class="gene-card-copy-btns">' + copyButtons + '</div>' +
      '</div>' +
    '</article>';
  }

  function renderLocusCard(row) {
    var badges = '<span class="mgdb-pill">Gene Locus</span>';
    var metaItems = [];
    if (row.full_name) {
      metaItems.push('<dt>Full name</dt><dd>' + esc(row.full_name) + '</dd>');
    }
    if (row.models) {
      metaItems.push('<dt>Gene models</dt><dd>' + num(row.models) + '</dd>');
    }
    if (row.annotations) {
      metaItems.push('<dt>Annotations</dt><dd>' + num(row.annotations) + '</dd>');
    }
    if (row.locus_id) {
      metaItems.push('<dt>MaizeGDB ID</dt><dd>' + esc(row.locus_id) + '</dd>');
    }

    var metaHtml = metaItems.length ? '<dl class="gene-card-meta-list">' + metaItems.join('') + '</dl>' : '';

    var haystack = [row.locus_name, row.full_name, row.locus_id].join(' ').toLowerCase();
    var copyButtons = '<button class="gene-copy-btn" type="button" data-copy-value="' + esc(row.locus_name) + '">Copy Locus</button>';

    var locusUrl = row.url || ('/data_center/locus?id=' + encodeURIComponent(row.locus_id));

    return '<article class="gene-result-card" data-search="' + esc(haystack) + '">' +
      '<div>' +
        '<div class="gene-card-header">' +
          '<h3 class="gene-card-title"><a href="' + esc(locusUrl) + '"><em>' + esc(row.locus_name) + '</em></a></h3>' +
          '<div class="gene-card-badges">' + badges + '</div>' +
        '</div>' +
        metaHtml +
      '</div>' +
      '<div class="gene-card-actions">' +
        '<a href="' + esc(locusUrl) + '">View locus details &rarr;</a>' +
        '<div class="gene-card-copy-btns">' + copyButtons + '</div>' +
      '</div>' +
    '</article>';
  }

  function initSortButtons(container) {
    Array.prototype.forEach.call(container.querySelectorAll('button[data-sort-key]'), function (btn) {
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
  }

  function initCopyButtons() {
    Array.prototype.forEach.call(document.querySelectorAll('.gene-copy-btn'), function (btn) {
      btn.addEventListener('click', function () {
        var val = btn.getAttribute('data-copy-value');
        if (!val) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(val).then(function () {
            var orig = btn.textContent;
            btn.textContent = 'Copied!';
            setTimeout(function () { btn.textContent = orig; }, 1500);
          });
        }
      });
    });
  }

  function updateViewDisplay() {
    var tableView = byId('gene-table-view');
    var cardsView = byId('gene-cards-view');
    var btnCards = byId('gene-view-cards');
    var btnTable = byId('gene-view-table');

    var isCards = state.view === 'cards';
    var hasResults = (state.models && state.models.length > 0) || (state.loci && state.loci.length > 0);

    if (tableView) {
      tableView.hidden = isCards || !hasResults;
    }
    if (cardsView) {
      cardsView.hidden = !isCards || !hasResults;
    }

    if (btnCards) {
      btnCards.setAttribute('aria-pressed', isCards ? 'true' : 'false');
      btnCards.classList.toggle('is-active', isCards);
    }
    if (btnTable) {
      btnTable.setAttribute('aria-pressed', !isCards ? 'true' : 'false');
      btnTable.classList.toggle('is-active', !isCards);
    }
  }

  function updateUrlView() {
    if (!window.history || !window.history.replaceState) { return; }
    var params = new URLSearchParams(window.location.search);
    params.set('view', state.view);
    var newUrl = window.location.pathname + '?' + params.toString() + window.location.hash;
    window.history.replaceState(null, '', newUrl);
  }

  function applyResultFilter() {
    var query = (state.filter || '').toLowerCase().trim();
    var terms = query.split(/\s+/).filter(Boolean);
    var tableContainer = byId('gene-table-view');
    var cardsContainer = byId('gene-cards-view');
    var total = state.models.length + state.loci.length;
    var visible = 0;

    if (tableContainer) {
      var rows = tableContainer.querySelectorAll('tbody tr');
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
      var cards = cardsContainer.querySelectorAll('.gene-result-card');
      for (var j = 0; j < cards.length; j++) {
        var cHay = (cards[j].getAttribute('data-search') || '').toLowerCase();
        var cMatch = true;
        for (var ct = 0; ct < terms.length; ct++) {
          if (cHay.indexOf(terms[ct]) === -1) { cMatch = false; break; }
        }
        cards[j].hidden = !cMatch;
      }
    }

    var note = byId('gene-filter-count');
    if (note) {
      note.textContent = query === '' ? '' : visible + ' of ' + total + ' shown';
    }
  }

  function render() {
    var section = byId('gene-results-section');
    var results = byId('gene-results');
    var cardsContainer = byId('gene-cards-view');
    var notes = byId('gene-notes');
    var empty = byId('gene-empty');
    var exportLink = byId('gene-export-tsv');
    var summary = state.summary || {};

    if (section) { section.hidden = false; }

    var models = state.models.slice();
    var loci = state.loci.slice();

    if (state.sort) {
      models.sort(compareModels);
    }

    var totalModels = models.length;
    var totalLoci = loci.length;

    if (!totalModels && !totalLoci) {
      if (results) { results.innerHTML = ''; }
      if (cardsContainer) { cardsContainer.innerHTML = ''; }
      if (empty) { empty.hidden = false; }
      setStatus('No genes or gene models matched "' + esc(state.term) + '".');
      updateViewDisplay();
      return;
    }

    if (empty) { empty.hidden = true; }

    var visibleModels = state.pageSize === 'all' ? models : models.slice(0, parseInt(state.pageSize, 10));

    // Table view rendering
    var tableHtml = '';
    if (loci.length) {
      tableHtml += '<div class="gene-result-group"><h3>Gene loci <span class="mgdb-muted">('
        + num(loci.length) + ')</span></h3>' + locusTable(loci) + '</div>';
    }
    if (visibleModels.length) {
      var caption = 'Gene models matching "' + esc(state.term) + '"';
      tableHtml += '<div class="gene-result-group"><h3>Gene models <span class="mgdb-muted">('
        + num(visibleModels.length) + (visibleModels.length < totalModels ? ' of ' + num(totalModels) : '')
        + ')</span></h3>'
        + modelTable(visibleModels, caption) + '</div>';
    }
    if (results) {
      results.innerHTML = tableHtml;
      initSortButtons(results);
    }

    // Cards view rendering
    if (cardsContainer) {
      var cardHtml = '';
      if (loci.length) {
        cardHtml += loci.map(renderLocusCard).join('');
      }
      if (visibleModels.length) {
        cardHtml += visibleModels.map(renderModelCard).join('');
      }
      cardsContainer.innerHTML = cardHtml;
    }

    // Status line
    var statusText = 'Showing 1–' + Math.min(visibleModels.length, totalModels) + ' of ' + num(totalModels)
      + ' gene model' + (totalModels === 1 ? '' : 's');
    if (totalLoci > 0) {
      statusText += ' and ' + num(totalLoci) + ' gene loc' + (totalLoci === 1 ? 'us' : 'i');
    }
    statusText += ' matching "' + esc(state.term) + '" · ' + num(summary.elapsed_ms || 0) + ' ms';
    setStatus(statusText);

    // Messages / notes
    var messages = '';
    if (summary.exact_only) {
      messages += '<div class="mgdb-message mgdb-message-info" role="note"><div>'
        + '<strong>Exact identifier match.</strong> Partial matches were not searched. '
        + '<button type="button" class="mgdb-button mgdb-button-quiet" id="gene-broaden">Search partial matches</button>'
        + '</div></div>';
    }
    if (summary.truncated) {
      messages += '<div class="mgdb-message mgdb-message-info" role="note"><div>'
        + 'More records matched than the limit of ' + num(summary.limit)
        + '. Raise the maximum, or narrow the term with <code>^</code> or <code>$</code>.'
        + '</div></div>';
    }
    if (notes) {
      notes.innerHTML = messages;
      var broaden = byId('gene-broaden');
      if (broaden) {
        broaden.addEventListener('click', function () { runSearch({ broad: true }); });
      }
    }

    if (exportLink) {
      exportLink.href = API + '?' + lastQuery + '&format=tsv';
      exportLink.hidden = false;
    }

    updateViewDisplay();
    applyResultFilter();
    initCopyButtons();
  }

  function initToolbar() {
    var filterInput = byId('gene-results-filter');
    if (filterInput) {
      filterInput.addEventListener('input', function () {
        state.filter = filterInput.value;
        applyResultFilter();
      });
    }

    var pageSizeSelect = byId('gene-page-size');
    if (pageSizeSelect) {
      pageSizeSelect.addEventListener('change', function () {
        state.pageSize = pageSizeSelect.value;
        render();
      });
    }

    var btnCards = byId('gene-view-cards');
    var btnTable = byId('gene-view-table');

    if (btnCards) {
      btnCards.addEventListener('click', function () {
        state.view = 'cards';
        updateUrlView();
        updateViewDisplay();
      });
    }

    if (btnTable) {
      btnTable.addEventListener('click', function () {
        state.view = 'table';
        updateUrlView();
        updateViewDisplay();
      });
    }
  }

  function initSearch() {
    var form = byId('gene-search-form');
    if (!form) { return; }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      runSearch({ scroll: true });
    });

    var termInput = byId('gene-search-term');
    var clearBtn = byId('gene-query-clear');
    if (termInput && clearBtn) {
      var updateClear = function () {
        clearBtn.hidden = !(termInput.value || '').trim();
      };
      termInput.addEventListener('input', updateClear);
      updateClear();
      clearBtn.addEventListener('click', function () {
        termInput.value = '';
        clearBtn.hidden = true;
        termInput.focus();
      });
    }

    Array.prototype.forEach.call(document.querySelectorAll('.gene-examples button[data-gene-example]'), function (button) {
      button.addEventListener('click', function () {
        var input = byId('gene-search-term');
        if (input) {
          input.value = button.getAttribute('data-gene-example');
          if (clearBtn) { clearBtn.hidden = false; }
          runSearch({ scroll: true });
          input.focus();
        }
      });
    });

    initToolbar();

    // ?term= in the address bar runs the search
    if (window.URLSearchParams) {
      var initial = new window.URLSearchParams(window.location.search).get('term');
      if (initial) {
        if (termInput) {
          termInput.value = initial;
          if (clearBtn) { clearBtn.hidden = false; }
        }
        runSearch();
      }
    }
  }

  /* ======================================================================
     Advanced search
     ====================================================================== */

  function runAdvanced(options) {
    var params = new URLSearchParams();
    params.set('mode', 'advanced');

    var limit = byId('gene-search-limit');
    if (limit && limit.value) { params.set('limit', limit.value); }

    var checked = 0;

    // Annotation
    var annot = byId('adv-annotation');
    if (annot && annot.value && annot.value !== 'all') {
      params.set('use_annotation', '1');
      params.set('annotation', annot.value);
      checked++;
    }

    // Model type
    var type = byId('adv-type');
    if (type && type.value && type.value !== 'all') {
      params.set('use_model_type', '1');
      params.set('model_type', type.value);
      checked++;
    }

    // Chromosome
    var chr = byId('adv-chr');
    if (chr && chr.value && chr.value !== 'all') {
      params.set('use_chromosome', '1');
      params.set('chromosome', chr.value);
      checked++;
    }

    // Range
    var rStart = byId('adv-range-start');
    var rEnd = byId('adv-range-end');
    var startVal = rStart ? rStart.value.trim() : '';
    var endVal = rEnd ? rEnd.value.trim() : '';
    if (startVal || endVal) {
      params.set('use_range', '1');
      params.set('range_start', startVal);
      params.set('range_end', endVal);
      checked++;
    }

    // Gene product
    var prod = byId('adv-product');
    if (prod && prod.value && prod.value !== 'all') {
      params.set('use_gene_product', '1');
      params.set('gene_product', prod.value);
      checked++;
    }

    // Phenotype
    var pheno = byId('adv-pheno');
    if (pheno && pheno.value && pheno.value !== '0') {
      params.set('use_phenotype', '1');
      params.set('phenotype', pheno.value);
      checked++;
    }

    // Trait
    var trait = byId('adv-trait');
    if (trait && trait.value && trait.value !== '0') {
      params.set('use_trait', '1');
      params.set('trait', trait.value);
      checked++;
    }

    // Protein
    var prot = byId('adv-protein');
    var protVal = prot ? prot.value.trim() : '';
    if (protVal) {
      params.set('use_protein', '1');
      params.set('protein', protVal);
      checked++;
    }

    // Boolean toggles
    var locusBox = byId('adv-locus-box');
    if (locusBox && locusBox.checked) {
      params.set('use_locus_assoc', '1');
      checked++;
    }

    var tandemBox = byId('adv-tandem-box');
    if (tandemBox && tandemBox.checked) {
      params.set('use_tandem', '1');
      checked++;
    }

    var section = byId('gene-results-section');
    var results = byId('gene-results');
    var cardsContainer = byId('gene-cards-view');
    var notes = byId('gene-notes');
    var empty = byId('gene-empty');
    var status = byId('gene-results-status');

    if (checked === 0) {
      if (section) { section.hidden = false; }
      if (results) { results.innerHTML = ''; }
      if (cardsContainer) { cardsContainer.innerHTML = ''; }
      showError('gene-notes', 'Please select or enter at least one filter criterion.');
      if (status) { status.textContent = 'Advanced search criteria needed.'; }
      return;
    }

    if (section) { section.hidden = false; }
    if (notes) { notes.innerHTML = ''; }
    if (empty) { empty.hidden = true; }
    if (results) {
      results.innerHTML = '<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span>Searching gene models&hellip;</div>';
    }
    if (cardsContainer) {
      cardsContainer.innerHTML = '';
    }
    if (status) { status.textContent = 'Searching gene models…'; }

    lastQuery = params.toString();

    fetch(API + '?' + lastQuery, { credentials: 'same-origin' })
      .then(function (response) {
        return response.json().then(function (data) { return { ok: response.ok, data: data }; });
      })
      .then(function (wrap) {
        if (!wrap.ok || !wrap.data || !wrap.data.ok) {
          var message = (wrap.data && (wrap.data.message || wrap.data.detail)) || 'The search could not be completed.';
          if (results) { results.innerHTML = ''; }
          showError('gene-notes', message);
          setStatus('Search failed.');
          return;
        }

        var data = wrap.data;
        state.models = data.models || [];
        state.loci = data.loci || [];
        state.summary = data.summary || {};
        state.term = (data.summary && data.summary.criteria) || 'Advanced criteria';

        render();
        if (options && options.scroll && section) {
          section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      })
      .catch(function () {
        if (results) { results.innerHTML = ''; }
        showError('gene-notes', 'The search request failed. Please try again.');
        setStatus('Search failed.');
      });
  }

  var optionsLoaded = false;

  function loadAdvancedOptions() {
    if (optionsLoaded) { return; }
    optionsLoaded = true;

    var selects = document.querySelectorAll('[data-gene-options]');
    if (!selects.length) { return; }

    Array.prototype.forEach.call(selects, function (select) { select.disabled = true; });

    fetch(API + '?mode=options', { credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.options) { throw new Error('no options'); }
        Array.prototype.forEach.call(selects, function (select) {
          var html = data.options[select.getAttribute('data-gene-options')];
          if (html) { select.insertAdjacentHTML('beforeend', html); }
          select.disabled = false;
        });
      })
      .catch(function () {
        optionsLoaded = false;
        Array.prototype.forEach.call(selects, function (select) { select.disabled = false; });
        showError('gene-notes', 'The filter lists could not be loaded. Reopen this section to try again.');
      });
  }

  function initAdvanced() {
    var disclosure = byId('gene-advanced');
    if (disclosure) {
      disclosure.addEventListener('toggle', function () {
        if (disclosure.open) { loadAdvancedOptions(); }
      });
      if (disclosure.open) { loadAdvancedOptions(); }
    }

    var advSubmit = byId('gene-adv-submit');
    if (advSubmit) {
      advSubmit.addEventListener('click', function (e) {
        e.preventDefault();
        runAdvanced({ scroll: true });
      });
    }

    var advReset = byId('gene-adv-reset');
    if (advReset) {
      advReset.addEventListener('click', function () {
        var selects = ['adv-annotation', 'adv-type', 'adv-chr', 'adv-product', 'adv-pheno', 'adv-trait'];
        selects.forEach(function (id) {
          var sel = byId(id);
          if (sel) { sel.selectedIndex = 0; }
        });
        var inputs = ['adv-range-start', 'adv-range-end', 'adv-protein'];
        inputs.forEach(function (id) {
          var inp = byId(id);
          if (inp) { inp.value = ''; }
        });
        var checks = ['adv-locus-box', 'adv-tandem-box'];
        checks.forEach(function (id) {
          var chk = byId(id);
          if (chk) { chk.checked = false; }
        });
        var limit = byId('gene-search-limit');
        if (limit) { limit.value = '100'; }
      });
    }
  }

  /* ======================================================================
     Example fillers
     ====================================================================== */

  function initExampleFillers() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-gene-fill]'), function (button) {
      button.addEventListener('click', function () {
        var target = byId(button.getAttribute('data-gene-fill'));
        if (!target) { return; }
        var key = button.getAttribute('data-gene-example');
        target.value = key ? (EXAMPLES[key] || '') : (button.getAttribute('data-gene-value') || '');
        target.focus();
      });
    });
  }

  /* ======================================================================
     Search by region

     Three mutually exclusive ways to name a region, each posting to its own
     endpoint with its own method. The inactive branches are disabled so their
     fields stay out of the submitted query string, which is what the previous
     page's disabled_form_set class did.
     ====================================================================== */

  var REGION_TARGETS = {
    assembly: { action: '/search/gene/gene_chr_position.php', method: 'get' },
    marker:   { action: '/search/gene/gene_marker_position.php', method: 'get' },
    gm:       { action: '/search/gene/gene_gm_position.php', method: 'post' }
  };

  function selectedRegion() {
    var checked = document.querySelector('#gene-region-form input[name="region"]:checked');
    return checked ? checked.value : 'assembly';
  }

  function applyRegion() {
    var form = byId('gene-region-form');
    if (!form) { return; }

    var which = selectedRegion();
    var target = REGION_TARGETS[which] || REGION_TARGETS.assembly;
    form.setAttribute('action', target.action);
    form.setAttribute('method', target.method);

    Array.prototype.forEach.call(form.querySelectorAll('.gene-region-fields'), function (group) {
      var active = group.getAttribute('data-region') === which;
      group.classList.toggle('is-inactive', !active);
      Array.prototype.forEach.call(group.querySelectorAll('input, select, textarea'), function (field) {
        field.disabled = !active;
      });
    });
  }

  function initRegion() {
    var form = byId('gene-region-form');
    if (!form) { return; }

    Array.prototype.forEach.call(form.querySelectorAll('input[name="region"]'), function (radio) {
      radio.addEventListener('change', applyRegion);
    });

    // Clicking into a field selects the branch it belongs to.
    Array.prototype.forEach.call(form.querySelectorAll('.gene-region-fields'), function (group) {
      group.addEventListener('focusin', function () {
        var radio = form.querySelector('input[name="region"][value="' + group.getAttribute('data-region') + '"]');
        if (radio && !radio.checked) {
          radio.checked = true;
          applyRegion();
        }
      });
    });

    form.addEventListener('submit', function (event) {
      var which = selectedRegion();

      if (which === 'assembly') {
        var start = byId('region-start').value.trim();
        var end = byId('region-end').value.trim();
        if (start !== '' || end !== '') {
          if (start === '' || end === '') {
            event.preventDefault();
            window.alert('You need to set a start and end position or leave both fields blank to get genes/gene models for the entire chromosome.');
            return;
          }
          if (parseInt(start, 10) > parseInt(end, 10)) {
            event.preventDefault();
            window.alert('The ending coordinate is smaller than the starting coordinate.');
            return;
          }
        }
      } else if (which === 'marker') {
        if (byId('region-start-marker').value.trim() === '' && byId('region-end-marker').value.trim() === '') {
          event.preventDefault();
          window.alert('You need to enter at least one marker or BAC name.');
          return;
        }
      } else if (which === 'gm') {
        if (byId('region-gm-list').value.trim() === '') {
          event.preventDefault();
          window.alert('No gene models provided.');
          return;
        }
      }

      // A GET form would otherwise reload this page in place.
      form.setAttribute('target', '_blank');
    });

    applyRegion();
  }

  /* ======================================================================
     Form validation carried over from gene.js
     ====================================================================== */

  function requireValue(form, id, message) {
    form.addEventListener('submit', function (event) {
      var field = byId(id);
      if (!field || field.value.trim() === '') {
        event.preventDefault();
        window.alert(message);
      }
    });
  }

  function initToolForms() {
    var blast = byId('gene-blast-form');
    if (blast) {
      blast.addEventListener('submit', function (event) {
        if (byId('gene-blast-target').value === '') {
          event.preventDefault();
          window.alert('You have not selected a target.');
          return;
        }
        if (byId('gene-blast-sequence').value.trim() === '') {
          event.preventDefault();
          window.alert('You have not entered any sequence.');
        }
      });
    }

    var bulk = byId('gene-bulk-position-form');
    if (bulk) {
      bulk.addEventListener('submit', function (event) {
        if (byId('bulk-positions').value.trim() === '') {
          event.preventDefault();
          window.alert('No positions provided.');
          return;
        }
        if (byId('bulk-position-assembly').value === '') {
          event.preventDefault();
          window.alert('No assembly selected.');
        }
      });
    }

    var scores = byId('gene-scores-form');
    if (scores) { requireValue(scores, 'scores-list', 'No gene models provided.'); }

    var translate = byId('gene-translate-form');
    if (translate) { requireValue(translate, 'translate-list', 'No gene models provided.'); }

    var fasta = byId('gene-fasta-form');
    if (fasta) { requireValue(fasta, 'fasta-list', 'No gene models provided.'); }

    deferResults(bulk);
    deferResults(scores);
    deferResults(translate);
    deferResults(fasta);
  }

  /* ======================================================================
     Results that arrive before the window does

     These four tools all posted straight to their endpoint with
     target="_blank". A new tab therefore opened at once and then sat empty
     while the query ran -- on a long gene model list, for a minute or more,
     with nothing in it to say anything was happening and the page behind it
     giving no sign either.

     Now the form is posted in the background with the card showing a spinner,
     and the tab is opened only when there is something to put in it.

     Popup blockers are the catch: window.open() called from a fetch callback
     is not a user gesture, so a browser may refuse it. When that happens the
     card offers an "Open results" button instead -- one click, and because the
     text is already in hand the window it opens is filled immediately.
     ====================================================================== */

  function busyBox(form) {
    var box = form.querySelector('.gene-run-status');
    if (!box) {
      box = document.createElement('div');
      box.className = 'gene-run-status';
      box.setAttribute('aria-live', 'polite');
      var actions = form.querySelector('.mgdb-form-actions');
      if (actions) { actions.parentNode.insertBefore(box, actions.nextSibling); }
      else { form.appendChild(box); }
    }
    return box;
  }

  function showResults(text, title) {
    var win = window.open('', '_blank');
    if (!win) { return false; }
    win.document.open();
    win.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' +
      esc(title) + '</title><style>body{margin:0;padding:1rem;}' +
      'pre{margin:0;font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;' +
      'white-space:pre;}</style></head><body><pre></pre></body></html>');
    win.document.close();
    /* Written as a text node, not as HTML: the payload is data from the
       database and must never be parsed as markup. */
    win.document.querySelector('pre').textContent = text;
    return true;
  }

  function deferResults(form) {
    if (!form || !form.getAttribute('action')) { return; }
    var action = form.getAttribute('action');
    var title = (form.querySelector('h3') || {}).textContent || 'Results';

    form.addEventListener('submit', function (event) {
      if (event.defaultPrevented) { return; }   /* a validator already refused it */
      event.preventDefault();

      var box = busyBox(form);
      box.innerHTML = '<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span>' +
                      '<span>Running your query&hellip;</span></div>';

      /* Which button was pressed. A submitter carrying name="format" means the
         reader asked for a file, and a file must go through a real navigation
         so the browser's download machinery sees Content-Disposition -- fetch
         would hand back the bytes and open nothing. */
      var submitter = event.submitter ||
        (document.activeElement && form.contains(document.activeElement) ? document.activeElement : null);
      if (submitter && submitter.name === 'format') {
        var dl = new FormData(form);
        dl.append('format', submitter.value);
        var post = document.createElement('form');
        post.method = 'POST';
        post.action = action;
        post.style.display = 'none';
        dl.forEach(function (value, key) {
          var input = document.createElement('input');
          input.type = 'hidden'; input.name = key; input.value = value;
          post.appendChild(input);
        });
        document.body.appendChild(post);
        post.submit();
        document.body.removeChild(post);
        box.innerHTML = '<p class="mgdb-hint">Preparing your ' + esc(submitter.value.toUpperCase()) + ' file&hellip;</p>';
        return;
      }

      var data = new FormData(form);
      var options = { method: 'POST', body: data };
      var url = action;
      if ((form.getAttribute('method') || 'post').toLowerCase() === 'get') {
        url = action + (action.indexOf('?') === -1 ? '?' : '&') +
              new URLSearchParams(data).toString();
        options = { method: 'GET' };
      }

      window.fetch(url, options)
        .then(function (response) {
          if (!response.ok) { throw new Error('HTTP ' + response.status); }
          return response.text();
        })
        .then(function (text) {
          if (showResults(text, title)) {
            box.innerHTML = '<p class="mgdb-hint">Results opened in a new tab.</p>';
            return;
          }
          /* Blocked. Hand the reader the click that will not be blocked. */
          box.innerHTML = '';
          var msg = document.createElement('p');
          msg.className = 'mgdb-hint';
          msg.textContent = 'Results are ready. Your browser blocked the new tab.';
          var open = document.createElement('button');
          open.type = 'button';
          open.className = 'mgdb-button mgdb-button-primary';
          open.textContent = 'Open results';
          open.addEventListener('click', function () { showResults(text, title); });
          box.appendChild(msg);
          box.appendChild(open);
        })
        .catch(function (error) {
          box.innerHTML = '<div class="mgdb-message mgdb-message-error" role="alert"><div>' +
            'The query could not be completed&#58; ' + esc(String(error && error.message || error)) +
            '. Try a shorter list, or report it through <a href="/feedback">Feedback</a>.</div></div>';
        });
    });
  }

  /* ======================================================================
     Download all data for a gene model list

     Nothing here any more, deliberately. The form posts straight to
     search/gene/gene_download_all.php, which answers with the file. What this
     replaces: a POST that blocked until a Perl job finished, a poller that
     slept five seconds inside every call, and a synthetic
     <a download target="_blank"> click that a popup blocker can refuse in
     silence. The only thing left worth doing in the browser is refusing an
     empty submission before it becomes a round trip.
     ====================================================================== */

  function initDownloadAll() {
    var form = byId('gene-downloadall-form');
    if (!form) { return; }

    form.addEventListener('submit', function (event) {
      var list = byId('downloadall_list');
      var file = byId('downloadall_file');
      var hasList = list && list.value.trim() !== '';
      var hasFile = file && file.files && file.files.length > 0;
      if (!hasList && !hasFile) {
        event.preventDefault();
        window.alert('No gene models entered. Paste a list of gene models into the box or upload a file.');
      }
    });
  }


  /* ======================================================================
     Figures
     ====================================================================== */

  function fillTable(id, rows) {
    var table = byId(id);
    if (!table) { return; }
    var tbody = table.querySelector('tbody');
    if (!tbody) { return; }
    tbody.innerHTML = rows.join('');
    if (window.MGDB && window.MGDB.sortTable && table.hasAttribute('data-sortable')) {
      window.MGDB.sortTable(table);
    }
  }

  /* .mgdb-chart is a fixed 320px in the shared stylesheet -- 260px at narrow
     widths -- and .mgdb-chart-tall is not defined in any stylesheet, so a
     chart asking for more was drawn past the bottom of its own figure. The
     annotation chart wants 860px for its 35 bars and was getting 260. The
     container takes the same height that Plotly is given, from one variable. */
  function sizeChart(id, height) {
    var el = byId(id);
    if (el) { el.style.height = height + 'px'; }
    return height;
  }

  function annotationChart() {
    var data = readJson('gene-annotation-data');
    if (!data || !data.length) { return; }

    // Ascending, so the largest annotation sits at the top of a horizontal bar.
    var ordered = data.slice().sort(function (a, b) { return a.gene_models - b.gene_models; });

    fillTable('gene-annotation-table', data.map(function (row) {
      return '<tr><th scope="row">' + esc(row.annotation) + '</th>'
        + '<td>' + esc(row.line) + '</td>'
        + '<td class="mgdb-numeric" data-value="' + row.gene_models + '">' + num(row.gene_models) + '</td></tr>';
    }));

    if (!window.MGDB || !window.MGDB.chart) { return; }

    window.MGDB.chart({
      target: 'gene-annotation-chart',
      traces: function () {
        return [{
          type: 'bar',
          orientation: 'h',
          x: ordered.map(function (r) { return r.gene_models; }),
          y: ordered.map(function (r) { return r.line ? r.line + ' · ' + r.annotation : r.annotation; }),
          marker: { color: window.MGDB.CHART_COLORS[0] },
          hovertemplate: '%{y}<br>%{x:,} gene models<extra></extra>'
        }];
      },
      layout: {
        height: sizeChart('gene-annotation-chart', Math.max(420, ordered.length * 24 + 110)),
        margin: { l: 190, r: 24, t: 12, b: 48 },
        xaxis: { title: { text: 'Gene models' }, tickformat: ',d' },
        yaxis: { automargin: true, tickfont: { size: 11 } }
      }
    });
  }

  function chromosomeChart() {
    var data = readJson('gene-chromosome-data');
    if (!data || !data.bins || !data.bins.length) { return; }

    var types = data.types || [];
    var bins = data.bins;

    var table = byId('gene-chromosome-table');
    if (table) {
      var headRow = table.querySelector('thead tr');
      if (headRow) {
        headRow.innerHTML = '<th scope="col">Chromosome</th>'
          + types.map(function (t) { return '<th scope="col" class="mgdb-numeric">' + esc(t) + '</th>'; }).join('');
      }
      fillTable('gene-chromosome-table', bins.map(function (bin) {
        return '<tr><th scope="row">' + esc(bin.label) + '</th>'
          + types.map(function (t) {
              var v = bin.types[t] || 0;
              return '<td class="mgdb-numeric">' + num(v) + '</td>';
            }).join('')
          + '</tr>';
      }));
    }

    if (!window.MGDB || !window.MGDB.chart) { return; }

    window.MGDB.chart({
      target: 'gene-chromosome-chart',
      traces: function () {
        return types.map(function (type, index) {
          return {
            type: 'bar',
            name: type,
            x: bins.map(function (bin) { return bin.label; }),
            y: bins.map(function (bin) { return bin.types[type] || 0; }),
            marker: { color: window.MGDB.CHART_COLORS[index % window.MGDB.CHART_COLORS.length] },
            hovertemplate: '%{x}<br>' + type + '&#58; %{y:,}<extra></extra>'
          };
        });
      },
      layout: {
        barmode: 'stack',
        height: sizeChart('gene-chromosome-chart', 420),
        margin: { l: 70, r: 24, t: 12, b: 90 },
        xaxis: { tickangle: -35 },
        yaxis: { title: { text: 'Gene models' }, tickformat: ',d' },
        legend: { orientation: 'h', y: -0.32 }
      }
    });
  }

  function transcriptChart() {
    var data = readJson('gene-transcript-data');
    if (!data || !data.series || !data.series.length) { return; }

    var series = data.series;
    var cap = data.cap || 10;

    var label = function (row) {
      return row.capped ? cap + ' or more' : String(row.transcripts);
    };

    fillTable('gene-transcript-table', series.map(function (row) {
      return '<tr><th scope="row">' + esc(label(row)) + '</th>'
        + '<td class="mgdb-numeric">' + num(row.gene_models) + '</td></tr>';
    }));

    if (!window.MGDB || !window.MGDB.chart) { return; }

    window.MGDB.chart({
      target: 'gene-transcript-chart',
      traces: function () {
        return [{
          type: 'bar',
          x: series.map(label),
          y: series.map(function (r) { return r.gene_models; }),
          marker: { color: window.MGDB.CHART_COLORS[1 % window.MGDB.CHART_COLORS.length] },
          hovertemplate: '%{x} transcript(s)<br>%{y:,} gene models<extra></extra>'
        }];
      },
      layout: {
        height: sizeChart('gene-transcript-chart', 380),
        margin: { l: 70, r: 24, t: 12, b: 60 },
        xaxis: { title: { text: 'Transcripts per gene model' }, type: 'category' },
        yaxis: { title: { text: 'Gene models' }, tickformat: ',d' }
      }
    });
  }

  /* ======================================================================
     Scrollspy for the section tab bar
     ====================================================================== */

  /* The shared section-tab behaviour: a wrapping bar, aria-current, and a
     click hold released by real scrolling. The rail-scrolling version this
     replaced existed because thirteen tabs could not wrap; nine can. */
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

    var resultsSec = byId('gene-results-section');
    if (resultsSec && window.MutationObserver) {
      new window.MutationObserver(update).observe(resultsSec, {
        childList: true, subtree: true, attributes: true, attributeFilter: ['hidden']
      });
    }

    update();
  }

  /* ====================================================================== */

  function init() {
    initSearch();
    initAdvanced();
    initExampleFillers();
    initRegion();
    initToolForms();
    initDownloadAll();
    annotationChart();
    chromosomeChart();
    transcriptChart();
    buildTabs();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
