/* ==========================================================================
   Variation Data Hub (/data_center/variation)

   Runs the search, reveals the results section the first time one is made,
   and draws the three charts under the metrics.

   The script is emitted into <head> by Bauplan::includeScript, so it runs
   while the document is still parsing and every DOM read has to wait for
   DOMContentLoaded. Reading anything at module scope silently gets null.
   ========================================================================== */

(function () {
  'use strict';

  var API_URL = '/search/variation/variation_search_api.php';

  function defaultView() {
    return (window.innerWidth && window.innerWidth < 768) ? 'cards' : 'table';
  }

  var state = {
    term: '',
    type: 0,
    dominance: 0,
    viability: 0,
    mutagen: 0,
    phenotype: 0,
    has_stock: '',
    has_pheno: '',
    notes: '',
    sort: 'relevance',
    page: 1,
    pageSize: '25',
    scope: 'auto',
    view: defaultView(),
    total: 0,
    pageCount: 0,
    loading: false,
    searched: false
  };

  var lastRows = [];

  function byId(id) { return document.getElementById(id); }

  function esc(value) {
    if (value === null || value === undefined) { return ''; }
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function num(value) {
    return Number(value || 0).toLocaleString();
  }

  /* ======================================================================
     Reading and writing the request
     ====================================================================== */

  function searchParams(includePaging) {
    var params = new URLSearchParams();
    if (state.term) { params.set('term', state.term); }
    if (state.type) { params.set('type', state.type); }
    if (state.dominance) { params.set('dominance', state.dominance); }
    if (state.viability) { params.set('viability', state.viability); }
    if (state.mutagen) { params.set('mutagen', state.mutagen); }
    if (state.phenotype) { params.set('phenotype', state.phenotype); }
    if (state.has_stock) { params.set('has_stock', '1'); }
    if (state.has_pheno) { params.set('has_pheno', '1'); }
    if (state.notes) { params.set('notes', '1'); }
    if (state.sort) { params.set('sort', state.sort); }
    if (state.scope === 'broad') { params.set('scope', 'broad'); }
    if (includePaging) {
      params.set('page', state.page);
      params.set('page_size', state.pageSize);
    }
    return params;
  }

  function hasQuery() {
    return state.term !== '' || state.type || state.dominance || state.viability ||
           state.mutagen || state.phenotype || state.has_stock || state.has_pheno;
  }

  function readForm() {
    var value = function (id) {
      var el = byId(id);
      return el ? parseInt(el.value, 10) || 0 : 0;
    };
    var checked = function (id) {
      var el = byId(id);
      return el && el.checked ? '1' : '';
    };

    var query = byId('variation-query');
    state.term = query ? query.value.trim() : '';
    state.type = value('variation-type');
    state.dominance = value('variation-dominance');
    state.viability = value('variation-viability');
    state.mutagen = value('variation-mutagen');
    state.phenotype = value('variation-phenotype');
    state.has_stock = checked('variation-has-stock');
    state.has_pheno = checked('variation-has-pheno');
    state.notes = checked('variation-notes');

    var sort = byId('variation-sort');
    state.sort = sort ? sort.value : 'relevance';

    var size = byId('variation-page-size');
    state.pageSize = size ? size.value : '25';

    /* Curation notes are a branch of the broad tier only, so ticking the box
       widens the search rather than doing nothing visible. */
    if (state.notes === '1') { state.scope = 'broad'; }
  }

  function readUrl() {
    var params = new URLSearchParams(window.location.search);
    if (!params.toString()) { return false; }

    var setInput = function (id, key, transform) {
      if (!params.has(key)) { return false; }
      var el = byId(id);
      var raw = params.get(key);
      if (el) { el.value = transform ? transform(raw) : raw; }
      return true;
    };

    var touched = false;
    if (params.has('q') || params.has('term')) {
      var term = params.get('q') || params.get('term') || '';
      var query = byId('variation-query');
      if (query) { query.value = term; }
      touched = touched || term !== '';
    }

    ['type', 'dominance', 'viability', 'mutagen', 'phenotype'].forEach(function (key) {
      if (setInput('variation-' + key, key)) { touched = true; }
    });

    ['has_stock', 'has_pheno', 'notes'].forEach(function (key) {
      if (!params.has(key)) { return; }
      var el = byId('variation-' + key.replace(/_/g, '-'));
      if (el) { el.checked = params.get(key) === '1'; }
      if (key !== 'notes') { touched = true; }
    });

    setInput('variation-sort', 'sort');
    setInput('variation-page-size', 'page_size');

    if (params.get('scope') === 'broad') { state.scope = 'broad'; }
    if (params.has('page')) { state.page = parseInt(params.get('page'), 10) || 1; }
    if (params.has('view')) { state.view = params.get('view') === 'cards' ? 'cards' : 'table'; }

    return touched;
  }

  /* The address bar carries only what differs from how the page opens, so a
     link someone copies out of it says what they actually chose. */
  function syncUrl() {
    if (!window.history || !window.history.replaceState) { return; }
    var params = searchParams(false);
    if (state.sort === 'relevance') { params.delete('sort'); }
    if (state.page > 1) { params.set('page', state.page); }
    if (state.pageSize !== '25') { params.set('page_size', state.pageSize); }
    if (state.view !== defaultView()) { params.set('view', state.view); }
    var query = params.toString();
    window.history.replaceState({}, '', window.location.pathname + (query ? '?' + query : ''));
  }

  /* ======================================================================
     Running a search
     ====================================================================== */

  /* The results section is absent from the page until a search is made, which
     is why it is revealed rather than emptied -- an empty table with headings
     reads as a search that returned nothing. */
  function reveal() {
    var section = byId('variation-results-section');
    if (section && section.hidden) {
      section.hidden = false;
    }
  }

  function hideResults() {
    var section = byId('variation-results-section');
    if (section) { section.hidden = true; }
    var cards = byId('variation-cards-view');
    if (cards) { cards.innerHTML = ''; }
    state.searched = false;
    state.page = 1;
    lastRows = [];
    syncUrl();
  }

  function search(scrollToResults) {
    if (state.loading) { return; }
    state.loading = true;
    state.searched = true;

    reveal();

    var status = byId('variation-results-status');
    if (status) { status.textContent = 'Searching…'; }

    var url = API_URL + '?' + searchParams(true).toString();

    fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function (response) {
        if (!response.ok) { throw new Error('HTTP ' + response.status); }
        return response.json();
      })
      .then(function (data) {
        state.loading = false;
        if (!data || !data.ok) { throw new Error('search failed'); }
        render(data);
        if (scrollToResults) { scrollToSection(); }
      })
      .catch(function () {
        state.loading = false;
        renderError();
      });
  }

  function scrollToSection() {
    var section = byId('variation-results-section');
    if (!section) { return; }
    var top = section.getBoundingClientRect().top + window.scrollY;
    var margin = parseFloat(window.getComputedStyle(section).scrollMarginTop);
    if (!isFinite(margin)) { margin = 0; }
    window.scrollTo({ top: Math.max(0, top - margin), behavior: 'smooth' });
  }

  function render(data) {
    var summary = data.summary || {};
    state.total = summary.total || 0;
    state.pageCount = summary.page_count || 0;
    state.scope = summary.scope === 'broad' ? 'broad' : state.scope;

    lastRows = data.results || [];

    renderStatus(data);
    renderNote(summary);
    renderRows(lastRows);
    renderCards(lastRows);
    updateViewDisplay();
    renderPagination(summary);
    renderSortState();
    updateExports();
    updateAdvancedCount();
    applyResultFilter();
    initCopyButtons();
    syncUrl();
  }

  function updateViewDisplay() {
    var tableView = byId('variation-table-scroll');
    var cardsView = byId('variation-cards-view');
    var btnCards = byId('variation-view-cards');
    var btnTable = byId('variation-view-table');

    var isCards = state.view === 'cards';
    var hasResults = lastRows && lastRows.length > 0;

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

  function renderCards(rows) {
    var container = byId('variation-cards-view');
    if (!container) { return; }

    if (!rows.length) {
      container.innerHTML = '';
      return;
    }

    container.innerHTML = rows.map(renderCard).join('');
  }

  function renderCard(row) {
    var recordUrl = '/data_center/variation?id=' + encodeURIComponent(row.id);
    var locusUrl = row.locus_id || row.locus_name
      ? '/data_center/locus?id=' + encodeURIComponent(row.locus_id || row.locus_name)
      : '';

    var typeBadge = row.type_name
      ? '<span class="mgdb-pill">' + esc(row.type_name) + '</span>'
      : '';

    var stockBadge = '';
    if (row.stock_count > 0) {
      stockBadge = '<span class="mgdb-pill mgdb-pill-info"><a href="/data_center/stock?variation=' + row.id + '" style="color:inherit;text-decoration:none;">' + num(row.stock_count) + ' stock' + (row.stock_count === 1 ? '' : 's') + '</a></span>';
    } else if (row.prog_stock_id) {
      stockBadge = '<span class="mgdb-pill"><a href="/data_center/stock?id=' + row.prog_stock_id + '" style="color:inherit;text-decoration:none;">' + esc(row.prog_stock_name || 'Progenitor') + '</a></span>';
    }

    var synonymsHtml = row.synonyms
      ? '<span class="variation-card-synonyms">Also known as ' + esc(row.synonyms) + '</span>'
      : '';

    var metaItems = [];
    if (row.locus_name) {
      metaItems.push('<dt>Gene</dt><dd><a href="' + locusUrl + '">' + esc(row.locus_name) + '</a>' + (row.locus_full_name ? ' (' + esc(row.locus_full_name) + ')' : '') + '</dd>');
    }
    if (row.dominance_name) {
      metaItems.push('<dt>Dominance</dt><dd>' + esc(row.dominance_name) + '</dd>');
    }
    if (row.viability_name) {
      metaItems.push('<dt>Viability</dt><dd>' + esc(row.viability_name) + '</dd>');
    }
    if (row.mutagens) {
      metaItems.push('<dt>Origin / Mutagen</dt><dd>' + esc(row.mutagens) + '</dd>');
    }
    if (row.phenotypes) {
      metaItems.push('<dt>Phenotypes</dt><dd>' + esc(row.phenotypes) + '</dd>');
    }

    var metaHtml = metaItems.length ? '<dl class="variation-card-meta-list">' + metaItems.join('') + '</dl>' : '';

    var haystack = [row.name, row.synonyms, row.locus_name, row.locus_full_name, row.type_name,
                    row.dominance_name, row.viability_name, row.mutagens, row.phenotypes,
                    row.alleledescriptor].filter(Boolean).join(' ').toLowerCase();

    return '<article class="variation-result-card" data-search="' + esc(haystack) + '">' +
      '<div>' +
        '<div class="variation-card-header">' +
          '<div class="variation-card-badges">' + typeBadge + stockBadge + '</div>' +
        '</div>' +
        '<h3 class="variation-card-title"><a href="' + recordUrl + '">' + esc(row.name) + '</a></h3>' +
        synonymsHtml +
        metaHtml +
      '</div>' +
      '<div class="variation-card-actions">' +
        '<a href="' + recordUrl + '">View record &rarr;</a>' +
        '<div class="variation-card-copy-btns">' +
          '<button class="variation-copy-btn" type="button" data-copy-value="' + esc(row.name) + '">Copy Name</button>' +
          '<button class="variation-copy-btn" type="button" data-copy-value="' + esc(row.id) + '">Copy ID</button>' +
        '</div>' +
      '</div>' +
    '</article>';
  }

  function initCopyButtons() {
    Array.prototype.forEach.call(document.querySelectorAll('.variation-copy-btn'), function (btn) {
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

  function clientSortRows(key, ascending) {
    if (!lastRows || !lastRows.length) { return; }
    lastRows.sort(function (a, b) {
      var valA = '', valB = '';
      if (key === 'stocks') {
        var numA = Number(a.stock_count || 0);
        var numB = Number(b.stock_count || 0);
        return ascending ? numA - numB : numB - numA;
      } else if (key === 'dominance') {
        valA = a.dominance_name || '';
        valB = b.dominance_name || '';
      } else if (key === 'viability') {
        valA = a.viability_name || '';
        valB = b.viability_name || '';
      } else if (key === 'mutagen') {
        valA = a.mutagens || '';
        valB = b.mutagens || '';
      } else if (key === 'phenotypes') {
        valA = a.phenotypes || '';
        valB = b.phenotypes || '';
      } else if (key === 'locus') {
        valA = a.locus_name || '';
        valB = b.locus_name || '';
      } else if (key === 'type') {
        valA = a.type_name || '';
        valB = b.type_name || '';
      } else {
        valA = a.name || '';
        valB = b.name || '';
      }
      return ascending
        ? String(valA).localeCompare(String(valB), undefined, { numeric: true, sensitivity: 'base' })
        : String(valB).localeCompare(String(valA), undefined, { numeric: true, sensitivity: 'base' });
    });
    renderRows(lastRows);
    renderCards(lastRows);
    updateViewDisplay();
    applyResultFilter();
    initCopyButtons();
  }

  /* All table columns carry aria-sort so their state is announced and the design
     system renders the up/down sort arrows. */
  function renderSortState() {
    var table = byId('variation-table');
    if (!table) { return; }

    Array.prototype.forEach.call(table.querySelectorAll('th button[data-sort-key]'), function (button) {
      var key = button.getAttribute('data-sort-key');
      var th = button.closest('th');
      if (!th) { return; }
      if (state.sort === key + '-asc') {
        th.setAttribute('aria-sort', 'ascending');
      } else if (state.sort === key + '-desc') {
        th.setAttribute('aria-sort', 'descending');
      } else {
        th.setAttribute('aria-sort', 'none');
      }
    });
  }

  function renderStatus(data) {
    var status = byId('variation-results-status');
    if (!status) { return; }

    var summary = data.summary || {};
    var pageSize = summary.page_size || 25;
    var shown = (data.results || []).length;

    if (!shown) {
      status.textContent = 'No variations matched.';
      return;
    }

    var first = (summary.page - 1) * pageSize + 1;
    var last = first + shown - 1;
    var criteria = (data.criteria && data.criteria.length) ? ' ' + data.criteria.join(', ') : '';

    var text = 'Showing <strong>' + num(first) + '–' + num(last) + '</strong> of ' +
               num(summary.total) + (summary.capped ? '+' : '') + ' variations' +
               esc(criteria) + ' · ' + num(summary.elapsed_ms) + ' ms';

    if (summary.scope === 'exact') {
      text += ' · exact matches';
    } else if (summary.scope === 'broad') {
      text += ' · all fields';
    }

    status.innerHTML = text;

    /* The exact tier answers in a few milliseconds and is what people want
       most of the time, so the wider scan is offered rather than run. */
    if (summary.broader_available) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'variation-scope-button';
      button.textContent = 'Search all fields';
      button.addEventListener('click', function () {
        state.scope = 'broad';
        state.page = 1;
        search(false);
      });
      status.appendChild(button);
    }
  }

  function renderNote(summary) {
    var note = byId('variation-results-note');
    if (!note) { return; }

    /* A filter-only search is ordered off the index over the whole match, so
       only its total is capped. A term search caps the candidate scan itself,
       so what comes back is a sample -- two different things to say. */
    if (summary.capped && summary.scope === 'filter') {
      note.textContent = 'More than ' + num(summary.cap) + ' variations match these filters. The first ' +
        num(summary.cap) + ' are listed in order; add a search term to narrow it, or export the file.';
      note.hidden = false;
      return;
    }

    if (summary.capped) {
      note.textContent = 'More than ' + num(summary.cap) + ' variations match. These are ' + num(summary.cap) +
        ' of them rather than the whole set, so the ordering is not the full ranking; use a more specific term or add a filter.';
      note.hidden = false;
      return;
    }

    if (summary.page_size_label === 'all' && summary.page_count > 1) {
      note.textContent = 'All results are shown ' + num(summary.page_size) + ' rows at a time, which is what the ' +
        'browser can render without stalling. Export the TSV or CSV for the whole set.';
      note.hidden = false;
      return;
    }

    note.hidden = true;
    note.textContent = '';
  }

  function renderRows(rows) {
    var body = byId('variation-results-body');
    var empty = byId('variation-empty');
    var scroll = byId('variation-table-scroll');
    var cards = byId('variation-cards-view');
    if (!body) { return; }

    if (!rows.length) {
      body.innerHTML = '';
      if (cards) { cards.innerHTML = ''; }
      if (empty) { empty.hidden = false; }
      if (scroll) { scroll.hidden = true; }
      if (cards) { cards.hidden = true; }
      return;
    }

    if (empty) { empty.hidden = true; }
    body.innerHTML = rows.map(renderRow).join('');
  }

  function cell(value, className) {
    if (!value) { return '<td class="variation-empty-cell">—</td>'; }
    return '<td' + (className ? ' class="' + className + '"' : '') + '>' + esc(value) + '</td>';
  }

  function renderRow(row) {
    var name = '<a href="/data_center/variation?id=' + row.id + '">' + esc(row.name) + '</a>';
    if (row.synonyms) {
      name += '<span class="variation-synonyms">Also known as ' + esc(row.synonyms) + '</span>';
    }

    var gene = row.locus_name
      ? '<a href="/data_center/locus?id=' + encodeURIComponent(row.locus_id || row.locus_name) + '">' + esc(row.locus_name) + '</a>'
      : '<span class="variation-empty-cell">—</span>';

    var type = row.type_name
      ? '<span class="mgdb-pill">' + esc(row.type_name) + '</span>'
      : '<span class="variation-empty-cell">—</span>';

    var stocks = '';
    if (row.stock_count > 0) {
      stocks = '<a href="/data_center/stock?variation=' + row.id + '">' + num(row.stock_count) + '</a>';
    } else if (row.prog_stock_id) {
      stocks = '<a href="/data_center/stock?id=' + row.prog_stock_id + '">' + esc(row.prog_stock_name || 'Progenitor') + '</a>';
    } else {
      stocks = '<span class="variation-empty-cell">—</span>';
    }

    /* One normalised string per row so the filter box can match without
       re-reading eight cells per keystroke. */
    var haystack = [row.name, row.synonyms, row.locus_name, row.locus_full_name, row.type_name,
                    row.dominance_name, row.viability_name, row.mutagens, row.phenotypes,
                    row.alleledescriptor].filter(Boolean).join(' ').toLowerCase();

    return '<tr data-search="' + esc(haystack) + '">' +
      '<td class="variation-name">' + name + '</td>' +
      '<td>' + gene + '</td>' +
      '<td>' + type + '</td>' +
      cell(row.dominance_name) +
      cell(row.viability_name) +
      cell(row.mutagens, 'variation-mutagens') +
      cell(row.phenotypes, 'variation-phenotypes') +
      '<td>' + stocks + '</td>' +
      '</tr>';
  }

  function renderPagination(summary) {
    var nav = byId('variation-pagination');
    if (!nav) { return; }

    nav.innerHTML = '';
    var pages = summary.page_count || 0;
    if (pages <= 1) { return; }

    var current = summary.page || 1;
    var html = '';

    html += pageButton(current - 1, '‹ Previous', current <= 1, false);

    var first = Math.max(1, current - 3);
    var last = Math.min(pages, first + 6);
    first = Math.max(1, Math.min(first, last - 6));

    if (first > 1) {
      html += pageButton(1, '1', false, current === 1);
      if (first > 2) { html += '<span class="variation-page-gap">…</span>'; }
    }

    for (var p = first; p <= last; p++) {
      html += pageButton(p, String(p), false, p === current);
    }

    if (last < pages) {
      if (last < pages - 1) { html += '<span class="variation-page-gap">…</span>'; }
      html += pageButton(pages, String(pages), false, current === pages);
    }

    html += pageButton(current + 1, 'Next ›', current >= pages, false);
    nav.innerHTML = html;

    Array.prototype.forEach.call(nav.querySelectorAll('button[data-page]'), function (button) {
      button.addEventListener('click', function () {
        var target = parseInt(button.getAttribute('data-page'), 10);
        if (target && target !== state.page) {
          state.page = target;
          search(true);
        }
      });
    });
  }

  function pageButton(page, label, disabled, current) {
    return '<button type="button" data-page="' + page + '"' +
      (disabled ? ' disabled' : '') +
      (current ? ' aria-current="page"' : '') +
      '>' + label + '</button>';
  }

  function renderError() {
    var status = byId('variation-results-status');
    var body = byId('variation-results-body');
    if (body) { body.innerHTML = ''; }
    if (status) {
      status.textContent = 'The search could not be completed. Try again, or narrow the query if it was a very broad one.';
    }
  }

  function updateExports() {
    var params = searchParams(false);
    var tsv = byId('variation-export-tsv');

    if (tsv) {
      params.set('format', 'tsv');
      tsv.href = API_URL + '?' + params.toString();
    }
  }

  /* The count on the Advanced search summary is the only thing that says a
     filter is set while the panel is closed. */
  function updateAdvancedCount() {
    var badge = byId('variation-advanced-count');
    if (!badge) { return; }

    var active = 0;
    ['type', 'dominance', 'viability', 'mutagen', 'phenotype'].forEach(function (key) {
      if (state[key]) { active++; }
    });
    if (state.has_stock) { active++; }
    if (state.has_pheno) { active++; }
    if (state.notes) { active++; }

    badge.textContent = active + ' active';
    badge.hidden = active === 0;
  }

  /* ======================================================================
     Filtering what is already on the page

     This narrows the rows in front of the reader without another round trip.
     It cannot see past the current page, so the status line says how many of
     the loaded rows are showing rather than pretending to filter the match.
     ====================================================================== */

  function applyResultFilter() {
    var input = byId('variation-results-filter');
    var body = byId('variation-results-body');
    var cardsContainer = byId('variation-cards-view');
    if (!input) { return; }

    var query = input.value.trim().toLowerCase();
    var visible = 0;
    var total = lastRows.length;

    if (body) {
      var rows = body.rows;
      for (var i = 0; i < rows.length; i++) {
        var match = query === '' || (rows[i].getAttribute('data-search') || '').indexOf(query) !== -1;
        rows[i].hidden = !match;
        rows[i].classList.toggle('is-alt', match && visible % 2 === 1);
        if (match) { visible++; }
      }
    }

    if (cardsContainer) {
      var cards = cardsContainer.querySelectorAll('.variation-result-card');
      for (var j = 0; j < cards.length; j++) {
        var cardMatch = query === '' || (cards[j].getAttribute('data-search') || '').indexOf(query) !== -1;
        cards[j].hidden = !cardMatch;
      }
    }

    var note = byId('variation-filter-count');
    if (note) {
      note.textContent = query === '' ? '' : visible + ' of ' + total + ' shown';
    }
  }

  /* ======================================================================
     Charts
     ====================================================================== */

  function readSeries(id) {
    var el = byId(id);
    if (!el) { return null; }
    var raw = el.getAttribute('data-series');
    if (!raw) { return null; }
    try {
      var parsed = JSON.parse(raw);
      return (parsed && parsed.labels && parsed.labels.length) ? parsed : null;
    } catch (error) {
      return null;
    }
  }

  /* .mgdb-chart is a fixed 320px in the design system, so passing a height to
     Plotly alone leaves a tall chart squashed into the box. One variable feeds
     both the element and the layout. */
  function sizeChart(id, height) {
    var el = byId(id);
    if (el) { el.style.height = height + 'px'; }
    return height;
  }

  /* Category labels are automargined, so one long phenotype name can take most
     of a narrow chart's width. Truncating the tick and keeping the full string
     for the hover holds the plot area open. */
  function shorten(label, limit) {
    var text = String(label);
    return text.length > limit ? text.slice(0, limit - 1) + '…' : text;
  }

  function barChart(id, series, options) {
    if (!series || !window.MGDB || !window.MGDB.chart) { return; }

    var settings = options || {};
    var height = sizeChart(id, Math.max(300, series.labels.length * 30 + 90));
    var ticks = series.labels.map(function (label) { return shorten(label, settings.labelLimit || 34); });

    window.MGDB.chart({
      target: id,
      traces: function () {
        return [{
          type: 'bar',
          orientation: 'h',
          x: series.values,
          y: ticks,
          customdata: series.labels,
          /* A leading non-breaking space is the only padding Plotly offers for
             outside bar text: without it the number sits 3px from the bar end
             and reads as touching it. It has to be \u00A0 rather than a plain
             space -- SVG collapses leading whitespace, so a space measures as
             no padding at all. */
          text: series.values.map(function (value) { return '\u00A0' + Number(value).toLocaleString(); }),
          /* Outside rather than auto: on a log axis the short bars are only a
             few pixels wide, and `auto` answers that by rotating the number
             ninety degrees inside them, which is unreadable. textangle pins
             it flat and cliponaxis lets the longest bar's label sit in the
             right margin reserved for it below. */
          textposition: 'outside',
          textangle: 0,
          cliponaxis: false,
          marker: { color: settings.color || '#285d46' },
          hovertemplate: '%{customdata}<br>%{x:,} variations<extra></extra>'
        }];
      },
      layout: {
        height: height,
        margin: { l: 10, r: 76, t: 8, b: 44 },
        bargap: 0.28,
        xaxis: {
          type: settings.log ? 'log' : 'linear',
          title: { text: settings.log ? 'Variations (logarithmic)' : 'Variations' },
          /* One tick per decade. Plotly's default on a log axis adds a minor
             tick at 2, so the row reads "100 2 1000 2 10k 2 100k". */
          dtick: settings.log ? 1 : undefined,
          automargin: true
        },
        /* The gap between a category name and its bar is the shared tick
           standoff in mgdb-modern.js now; the two trailing spaces that used to
           stand in for it also leaked into the hover text. */
        yaxis: { type: 'category', automargin: true }
      }
    });

    /* A chart drawn while its container has no width falls back to Plotly's
       700px default, which then escapes the box and stretches the document.
       MGDB.chart already redraws on window resize; this catches the container
       changing width on its own -- a section revealed, a details panel opened,
       the results table arriving above it. */
    if (window.ResizeObserver) {
      var el = byId(id);
      var lastWidth = 0;
      new window.ResizeObserver(function () {
        var width = el.clientWidth;
        if (!width || width === lastWidth) { return; }
        lastWidth = width;
        if (window.Plotly && window.Plotly.Plots && el.querySelector('.main-svg')) {
          window.Plotly.Plots.resize(el);
        }
      }).observe(el);
    }
  }

  function initCharts() {
    barChart('variation-type-chart', readSeries('variation-type-chart'), { log: true, color: '#285d46', labelLimit: 28 });
    barChart('variation-mutagen-chart', readSeries('variation-mutagen-chart'), { log: true, color: '#8a5a0f', labelLimit: 28 });
    barChart('variation-phenotype-chart', readSeries('variation-phenotype-chart'), { log: false, color: '#1a5b7a', labelLimit: 52 });
  }

  /* ======================================================================
     Section tabs

     Driven by scroll, an IntersectionObserver and resize together, because no
     single trigger fires everywhere; the results section arrives after a
     search and moves everything below it, so that is watched too. The line the
     spy measures against is read back from the section's own scroll-margin, so
     clicking a tab cannot mark the section above the one it jumped to.
     ====================================================================== */

  function initScrollspy() {
    var nav = document.querySelector('.mgdb-variation-page .mgdb-section-tabs');
    if (!nav) { return; }

    var links = nav.querySelectorAll('a[href^="#"]');
    if (!links.length) { return; }

    var entries = [];
    Array.prototype.forEach.call(links, function (link) {
      var target = byId(link.getAttribute('href').slice(1));
      if (target) { entries.push({ link: link, target: target }); }
    });
    if (!entries.length) { return; }

    var pinned = null;
    var pinnedAt = 0;

    function select(entry) {
      entries.forEach(function (item) {
        var current = item === entry;
        item.link.classList.toggle('is-current', current);
        if (current) {
          item.link.setAttribute('aria-current', 'true');
        } else {
          item.link.removeAttribute('aria-current');
        }
      });
    }

    function currentLine() {
      var margin = parseFloat(window.getComputedStyle(entries[0].target).scrollMarginTop);
      if (!isFinite(margin)) { margin = 0; }
      return Math.max(nav.getBoundingClientRect().height + 8, margin + 4);
    }

    function update() {
      if (pinned) {
        if (Math.abs(window.scrollY - pinnedAt) < 24) { return; }
        pinned = null;
      }

      var line = currentLine();
      var current = entries[0];
      entries.forEach(function (entry) {
        if (entry.target.hidden) { return; }
        if (entry.target.getBoundingClientRect().top <= line) { current = entry; }
      });

      var doc = document.documentElement;
      if (window.innerHeight + window.scrollY >= doc.scrollHeight - 4) {
        current = entries[entries.length - 1];
      }

      select(current);
    }

    entries.forEach(function (entry) {
      entry.link.addEventListener('click', function () {
        select(entry);
        pinned = entry;
        pinnedAt = window.scrollY;
        window.setTimeout(function () { pinnedAt = window.scrollY; }, 700);
      });
    });

    var scheduled = false;
    function schedule() {
      if (scheduled) { return; }
      scheduled = true;
      window.setTimeout(function () { scheduled = false; update(); }, 100);
    }

    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule);

    if (window.IntersectionObserver) {
      var observer = new window.IntersectionObserver(schedule, { threshold: 0, rootMargin: '0px 0px -60% 0px' });
      entries.forEach(function (entry) { observer.observe(entry.target); });
    }

    /* A search replaces the table and reveals a whole section, which moves
       every section under it. */
    if (window.MutationObserver) {
      var results = byId('variation-results-section');
      if (results) {
        new window.MutationObserver(schedule).observe(results, {
          childList: true, subtree: true, attributes: true, attributeFilter: ['hidden']
        });
      }
    }

    update();
  }

  /* ======================================================================
     Wiring
     ====================================================================== */

  function restart(scrollToResults) {
    readForm();
    updateAdvancedCount();
    if (!hasQuery()) {
      hideResults();
      return;
    }
    state.page = 1;
    search(scrollToResults);
  }

  function init() {
    var form = byId('variation-search-form');
    var query = byId('variation-query');
    var clear = byId('variation-query-clear');

    if (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        /* A new query starts from the exact tier again; otherwise a reader who
           widened one search silently widens every one after it. */
        state.scope = 'auto';
        restart(true);
      });
    }

    if (query) {
      query.addEventListener('input', function () {
        if (clear) { clear.hidden = query.value === ''; }
      });
      if (clear) { clear.hidden = query.value === ''; }
    }

    if (clear && query) {
      clear.addEventListener('click', function () {
        query.value = '';
        clear.hidden = true;
        query.focus();
      });
    }

    /* Changing a facet re-runs the search rather than waiting for the button,
       because the panel is where a reader iterates. Clearing the last one puts
       the page back to how it opened rather than listing the whole corpus. */
    var onFacetChange = function () {
      readForm();
      updateAdvancedCount();
      if (!hasQuery()) {
        hideResults();
        return;
      }
      state.page = 1;
      search(false);
    };

    ['variation-type', 'variation-dominance', 'variation-viability', 'variation-mutagen',
     'variation-phenotype', 'variation-sort', 'variation-has-stock', 'variation-has-pheno',
     'variation-notes'].forEach(function (id) {
      var el = byId(id);
      if (el) { el.addEventListener('change', onFacetChange); }
    });

    var size = byId('variation-page-size');
    if (size) {
      size.addEventListener('change', function () {
        readForm();
        state.page = 1;
        search(false);
      });
    }

    /* Column headings: clicking toggles ascending / descending with up/down arrows.
       Server-backed sorts update the select and query; other columns sort rows client-side. */
    Array.prototype.forEach.call(document.querySelectorAll('#variation-table th button[data-sort-key]'), function (button) {
      button.addEventListener('click', function () {
        var key = button.getAttribute('data-sort-key');
        var next = state.sort === key + '-asc' ? key + '-desc' : key + '-asc';
        state.sort = next;

        var select = byId('variation-sort');
        if (select) {
          var matching = select.querySelector('option[value="' + next + '"]');
          if (matching) {
            select.value = next;
            readForm();
            state.page = 1;
            search(false);
            return;
          }
        }

        clientSortRows(key, next.endsWith('-asc'));
        renderSortState();
      });
    });

    var btnCards = byId('variation-view-cards');
    var btnTable = byId('variation-view-table');
    if (btnCards) {
      btnCards.addEventListener('click', function () {
        state.view = 'cards';
        updateViewDisplay();
        syncUrl();
      });
    }
    if (btnTable) {
      btnTable.addEventListener('click', function () {
        state.view = 'table';
        updateViewDisplay();
        syncUrl();
      });
    }

    var filter = byId('variation-results-filter');
    if (filter) {
      var debounced = window.MGDB && window.MGDB.debounce
        ? window.MGDB.debounce(applyResultFilter, 120)
        : applyResultFilter;
      filter.addEventListener('input', debounced);
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-variation-example]'), function (button) {
      button.addEventListener('click', function () {
        if (!query) { return; }
        query.value = button.getAttribute('data-variation-example');
        if (clear) { clear.hidden = false; }
        state.scope = 'auto';
        restart(true);
      });
    });

    var resetButtons = ['variation-advanced-reset', 'variation-empty-reset'];
    resetButtons.forEach(function (id) {
      var el = byId(id);
      if (!el) { return; }
      el.addEventListener('click', function () {
        ['variation-type', 'variation-dominance', 'variation-viability',
         'variation-mutagen', 'variation-phenotype'].forEach(function (selectId) {
          var select = byId(selectId);
          if (select) { select.value = '0'; }
        });
        ['variation-has-stock', 'variation-has-pheno', 'variation-notes'].forEach(function (checkId) {
          var check = byId(checkId);
          if (check) { check.checked = false; }
        });
        if (id === 'variation-empty-reset' && query) {
          query.value = '';
          if (clear) { clear.hidden = true; }
        }
        state.scope = 'auto';
        restart(false);
      });
    });

    /* A URL that already carries a query -- a bookmark, or one of the browse
       tiles -- runs its search on load. A bare URL leaves the results section
       hidden until the reader asks for something. */
    var fromUrl = readUrl();
    readForm();
    updateAdvancedCount();
    updateViewDisplay();
    if (fromUrl) {
      search(false);
    }

    initCharts();
    initScrollspy();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
