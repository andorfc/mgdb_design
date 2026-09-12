/* Reference literature search (/data_center/reference).

   Search client ported from the MaizeGDB website repository. The query,
   filtering, sorting, faceting, pagination, selection, and export behaviour
   are unchanged; only the presentation layer was restyled. */

(function () {
  'use strict';

  var apiUrl = '/search/reference/reference_search_api.php';
  var currentPage = 1;
  var currentData = null;
  var currentRequest = null;
  var searchTimer = null;
  var selectedIds = {};

  /* The endpoint caps page_size at 100, so "All results" asks for that. */
  var MAX_PAGE = 100;
  var pageSize = 25;
  /* Remembered from the first unfiltered load so the scope line can tell "the
     whole collection" apart from "a search that happens to match everything". */
  var collectionTotal = null;
  var resultFilter = '';
  var sortKey = 'title';
  var sortDir = 'asc';
  var viewMode = 'card';

  function byId(id) {
    return document.getElementById(id);
  }

  function escapeHtml(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function number(value) {
    return Number(value || 0).toLocaleString('en-US');
  }

  function syncAdvancedBadge() {
    var count = 0;
    var yFrom = byId('reference-year-from');
    var yTo = byId('reference-year-to');
    var journal = byId('reference-journal');
    var pubType = byId('reference-pub-type');
    var identifier = byId('reference-identifier');
    var sort = byId('reference-sort');
    var editorial = byId('reference-editorial');
    var meeting = byId('reference-include-meeting');
    var mnl = byId('reference-include-mnl');

    if (yFrom && yFrom.value.trim() !== '') count++;
    if (yTo && yTo.value.trim() !== '') count++;
    if (journal && journal.value !== '') count++;
    if (pubType && pubType.value !== '') count++;
    if (identifier && identifier.value !== 'all' && identifier.value !== '') count++;
    if (sort && sort.value !== 'relevance' && sort.value !== 'newest') count++;
    if (editorial && editorial.checked) count++;
    if (meeting && !meeting.checked) count++;
    if (mnl && !mnl.checked) count++;

    var badge = byId('reference-advanced-count');
    if (badge) {
      badge.textContent = count;
      badge.hidden = (count === 0);
    }
  }

  function formParams(page) {
    var params = new URLSearchParams();
    var fields = [
      ['q', 'reference-query'],
      ['scope', 'reference-scope'],
      ['year_from', 'reference-year-from'],
      ['year_to', 'reference-year-to'],
      ['journal', 'reference-journal'],
      ['pub_type', 'reference-pub-type'],
      ['identifier', 'reference-identifier'],
      ['sort', 'reference-sort']
    ];

    fields.forEach(function (pair) {
      var element = byId(pair[1]);
      if (element && element.value !== '' && !(pair[0] === 'identifier' && element.value === 'all')) {
        params.set(pair[0], element.value);
      }
    });
    if (byId('reference-editorial') && byId('reference-editorial').checked) {
      params.set('editorial', '1');
    }
    if (byId('reference-include-meeting') && !byId('reference-include-meeting').checked) {
      params.set('include_meeting', '0');
    }
    if (byId('reference-include-mnl') && !byId('reference-include-mnl').checked) {
      params.set('include_mnl', '0');
    }
    params.set('page', page || 1);
    params.set('page_size', String(pageSize === 'all' ? MAX_PAGE : pageSize));
    return params;
  }

  function applyUrlState() {
    var params = new URLSearchParams(window.location.search);
    var map = {
      q: 'reference-query',
      scope: 'reference-scope',
      year_from: 'reference-year-from',
      year_to: 'reference-year-to',
      journal: 'reference-journal',
      pub_type: 'reference-pub-type',
      identifier: 'reference-identifier',
      sort: 'reference-sort'
    };
    Object.keys(map).forEach(function (key) {
      if (params.has(key) && byId(map[key])) {
        byId(map[key]).value = params.get(key);
      }
    });
    if (byId('reference-editorial')) byId('reference-editorial').checked = params.get('editorial') === '1';
    if (byId('reference-include-meeting')) byId('reference-include-meeting').checked = params.get('include_meeting') !== '0';
    if (byId('reference-include-mnl')) byId('reference-include-mnl').checked = params.get('include_mnl') !== '0';
    currentPage = Math.max(1, parseInt(params.get('page') || '1', 10));
    var clearBtn = byId('reference-query-clear');
    var queryInput = byId('reference-query');
    if (clearBtn && queryInput) {
      clearBtn.hidden = !queryInput.value;
    }
    syncAdvancedBadge();
  }

  function updateUrl(params) {
    var clean = new URLSearchParams(params.toString());
    if (clean.get('page') === '1') clean.delete('page');
    clean.delete('page_size');
    /* With nothing narrowed, 'scope' and 'sort' describe a search that is not
       being shown. Strip them so a resting URL stays clean enough to share. */
    var narrowed = NARROWING.some(function (key) { return clean.has(key); });
    if (!narrowed) {
      clean.delete('scope');
      clean.delete('sort');
    }
    var next = window.location.pathname + (clean.toString() ? '?' + clean.toString() : '');
    window.history.replaceState({}, '', next);
  }

  function setLoading(loading) {
    byId('reference-loading').hidden = !loading;
    byId('reference-results').hidden = loading;

    /* The figures below are recomputed by the same request, so they are marked
       busy while it runs. Without this the numbers change with no warning and
       it is easy to miss that they moved at all. */
    var dashboard = byId('reference-dashboard');
    if (dashboard) {
      dashboard.classList.toggle('is-updating', !!loading);
      if (loading) { dashboard.setAttribute('aria-busy', 'true'); }
      else { dashboard.removeAttribute('aria-busy'); }
    }
    if (loading) {
      byId('reference-empty').hidden = true;
      byId('reference-error').hidden = true;
      byId('reference-resting').hidden = true;
      byId('reference-pagination').innerHTML = '';
      byId('reference-results-status').textContent = 'Searching curated literature…';
    }
  }

  /* Params that actually narrow the corpus. 'scope' is always present because it
     defaults to 'all', and 'sort'/'page' do not narrow anything, so none of the
     three counts as the user having asked for something. */
  var NARROWING = ['q', 'year_from', 'year_to', 'journal', 'pub_type',
                   'identifier', 'editorial', 'include_meeting', 'include_mnl'];

  function hasSearchState() {
    var params = formParams(1);
    return NARROWING.some(function (key) { return params.has(key); }) || currentPage > 1;
  }

  /* Resting state: no query has been entered, so there are no results to show.
     The dashboard below still describes the whole collection. */
  function showResting() {
    /* No query: the results section goes away entirely, as on every other hub.
       The Metrics section stays, describing the whole collection, and its scope
       line says so. */
    var section = byId('reference-results-section');
    if (section) { section.hidden = true; }

    var results = byId('reference-results');
    results.innerHTML = '';
    results.hidden = true;
    byId('reference-empty').hidden = true;
    byId('reference-error').hidden = true;
    byId('reference-resting').hidden = false;
    byId('reference-pagination').innerHTML = '';
    byId('reference-select-page').parentElement.hidden = true;
    selectedIds = {};
    updateSelection();
  }

  /* Corpus figures and charts without the result rows. Used on a bare page load
     and whenever the user clears their way back to no query. */
  function loadDashboard() {
    var params = formParams(1);
    params.delete('page');
    params.delete('page_size');
    params.set('facets_only', '1');
    updateUrl(formParams(1));
    setLoading(true);
    byId('reference-results-status').textContent = 'Loading collection summary\u2026';

    if (currentRequest && currentRequest.abort) currentRequest.abort();
    currentRequest = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var options = currentRequest ? {signal: currentRequest.signal} : {};

    fetch(apiUrl + '?' + params.toString(), options)
      .then(function (response) {
        if (!response.ok) throw new Error('Summary failed');
        return response.json();
      })
      .then(function (data) {
        if (!data.ok) throw new Error(data.message || 'Summary failed');
        currentData = data;
        if (collectionTotal === null) { collectionTotal = data.summary.total; }
        renderSummary(data);
        renderCharts(data.facets);
        setLoading(false);
        showResting();
        byId('reference-results-status').textContent =
          number(data.summary.total) + ' curated references. Search above to see matches.';
        updateExports();
      })
      .catch(function (error) {
        if (error.name === 'AbortError') return;
        setLoading(false);
        byId('reference-results').hidden = true;
        byId('reference-resting').hidden = true;
        byId('reference-error').hidden = false;
        byId('reference-results-status').textContent = 'Collection summary unavailable.';
      });
  }

  /* Route a change in the form: a real search when the user has narrowed
     something, the resting dashboard when they have not. */
  function refresh(page, scrollToResults) {
    /* Set currentPage first: hasSearchState() reads it, and clearing a query
       while on page 3 of a previous search must still land on the dashboard. */
    currentPage = page || 1;
    if (hasSearchState()) loadSearch(currentPage, scrollToResults);
    else loadDashboard();
  }

  function loadSearch(page, scrollToResults) {
    currentPage = page || 1;
    var section = byId('reference-results-section');
    if (section) { section.hidden = false; }
    var params = formParams(currentPage);
    updateUrl(params);
    setLoading(true);

    if (currentRequest && currentRequest.abort) {
      currentRequest.abort();
    }
    currentRequest = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var options = currentRequest ? {signal: currentRequest.signal} : {};

    fetch(apiUrl + '?' + params.toString(), options)
      .then(function (response) {
        if (!response.ok) throw new Error('Search failed');
        return response.json();
      })
      .then(function (data) {
        if (!data.ok) throw new Error(data.message || 'Search failed');
        currentData = data;
        selectedIds = {};
        renderSearch(data);
        setLoading(false);
        if (scrollToResults) {
          byId('reference-results-title').scrollIntoView({behavior: 'smooth', block: 'start'});
        }
      })
      .catch(function (error) {
        if (error.name === 'AbortError') return;
        setLoading(false);
        byId('reference-results').hidden = true;
        byId('reference-error').hidden = false;
        byId('reference-results-status').textContent = 'Search unavailable.';
      });
  }

  function renderSearch(data) {
    renderSummary(data);
    renderCharts(data.facets);
    renderResults(data);
    renderPagination(data.summary.page, data.summary.page_count);
    updateExports();
    updateSelection();
    /* Re-applied last so paging and a re-sort do not silently drop a filter the
       box still shows. */
    applyResultsFilter();
  }

  function renderSummary(data) {
    var summary = data.summary;
    var years = data.facets.year.map(function (row) { return parseInt(row.value, 10); })
      .filter(function (year) { return !isNaN(year); });
    byId('reference-total-count').textContent = number(summary.total);
    byId('reference-doi-count').textContent = number(summary.with_doi);
    byId('reference-pubmed-count').textContent = number(summary.with_pubmed);
    byId('reference-year-span').textContent = years.length
      ? Math.min.apply(Math, years) + '–' + Math.max.apply(Math, years)
      : '—';

    /* The whole point of this section on this hub: the four numbers and the
       four charts are computed over the *matched set*, not the corpus and not
       the page. Say which, concretely, every time it changes. */
    var shown = data.results ? data.results.length : 0;
    var description;
    if (data.query.term || summary.total !== collectionTotal) {
      description = 'These figures cover all ' + number(summary.total) + ' matching reference'
        + (summary.total === 1 ? '' : 's')
        + (data.query.term ? ' for “' + data.query.term + '”' : ' for the current filters')
        + (shown ? ', not just the ' + number(shown) + ' listed above' : '') + '.';
    } else {
      description = 'These figures cover the whole collection — all ' + number(summary.total)
        + ' curated references. Search above and they narrow to your matches.';
    }
    byId('reference-dashboard-description').textContent = description;

    var entitySummary = byId('reference-entity-summary');
    if (data.entities && data.entities.length) {
      var labels = data.entities.map(function (entity) {
        var full = entity.entity_full_name && entity.entity_full_name !== entity.entity_name
          ? ' — ' + entity.entity_full_name : '';
        return '<strong>' + escapeHtml(entity.entity_name) + '</strong>' + escapeHtml(full)
          + ' <span>(' + escapeHtml(entity.matched_as) + ')</span>';
      });
      entitySummary.innerHTML = 'References curated to: ' + labels.join(', ');
      entitySummary.hidden = false;
    } else {
      entitySummary.hidden = true;
      entitySummary.innerHTML = '';
    }
  }

  function baseChartLayout() {
    return {
      margin: {l: 48, r: 18, t: 12, b: 44},
      paper_bgcolor: 'rgba(0,0,0,0)',
      plot_bgcolor: 'rgba(0,0,0,0)',
      font: {family: 'Arial, sans-serif', size: 10, color: '#526157'},
      hoverlabel: {bgcolor: '#ffffff', bordercolor: '#d8e1d6'},
      showlegend: false
    };
  }

  function renderCharts(facets) {
    if (!window.Plotly) return;
    var config = {displayModeBar: false, responsive: true};
    var years = facets.year.slice().sort(function (a, b) { return Number(a.value) - Number(b.value); });
    var yearLayout = baseChartLayout();
    yearLayout.xaxis = {title: '', fixedrange: true, gridcolor: '#eef2ed'};
    yearLayout.yaxis = {title: 'Papers', rangemode: 'tozero', fixedrange: true, gridcolor: '#e5ece2'};
    yearLayout.hovermode = 'x unified';
    Plotly.react('reference-year-chart', [{type: 'bar', x: years.map(function (r) { return r.value; }),
      y: years.map(function (r) { return r.count; }), marker: {color: '#4f7f2b'},
      hovertemplate: '%{x}: %{y:,} papers<extra></extra>'}], yearLayout, config);

    var journals = facets.journal.slice().sort(function (a, b) { return a.count - b.count; }).slice(-8);
    var journalLayout = baseChartLayout();
    journalLayout.margin = {l: 135, r: 16, t: 8, b: 28};
    journalLayout.xaxis = {title: 'Papers', fixedrange: true, gridcolor: '#e5ece2'};
    journalLayout.yaxis = {fixedrange: true, automargin: true};
    Plotly.react('reference-journal-chart', [{type: 'bar', orientation: 'h',
      y: journals.map(function (r) { return r.value; }), x: journals.map(function (r) { return r.count; }),
      marker: {color: '#3c6583'}, hovertemplate: '%{y}: %{x:,}<extra></extra>'}], journalLayout, config);

    renderYearCollectionChart('reference-meeting-chart', facets.meeting_year || [], '#6d8fa8', 'abstracts');
    renderYearCollectionChart('reference-mnl-chart', facets.mnl_year || [], '#7aa34d', 'articles');
  }

  function renderYearCollectionChart(elementId, rows, color, label) {
    var years = rows.slice().sort(function (a, b) { return Number(a.value) - Number(b.value); });
    var layout = baseChartLayout();
    layout.margin = {l: 45, r: 12, t: 8, b: 34};
    layout.xaxis = {fixedrange: true, gridcolor: '#eef2ed'};
    layout.yaxis = {rangemode: 'tozero', fixedrange: true, gridcolor: '#e5ece2'};
    layout.hovermode = 'x unified';
    if (!years.length) {
      layout.annotations = [{text: 'No matching records with this collection included', x: 0.5, y: 0.5,
        xref: 'paper', yref: 'paper', showarrow: false, font: {size: 10, color: '#6b776e'}}];
    }
    Plotly.react(elementId, [{type: 'bar', x: years.map(function (r) { return r.value; }),
      y: years.map(function (r) { return r.count; }), marker: {color: color},
      hovertemplate: '%{x}: %{y:,} ' + label + '<extra></extra>'}], layout,
      {displayModeBar: false, responsive: true});
  }

  function sortReferenceResults(results) {
    var list = (results || []).slice();
    list.sort(function (a, b) {
      var diff = 0;
      if (sortKey === 'title') {
        var tA = (a.title || a.name || '').toLowerCase();
        var tB = (b.title || b.name || '').toLowerCase();
        diff = tA.localeCompare(tB);
      } else if (sortKey === 'authors') {
        var auA = (a.authors || '').toLowerCase();
        var auB = (b.authors || '').toLowerCase();
        diff = auA.localeCompare(auB);
      } else if (sortKey === 'year') {
        var yA = parseInt(a.year, 10) || 0;
        var yB = parseInt(b.year, 10) || 0;
        diff = yA - yB;
      } else if (sortKey === 'journal') {
        var jA = (a.journal || '').toLowerCase();
        var jB = (b.journal || '').toLowerCase();
        diff = jA.localeCompare(jB);
      } else if (sortKey === 'identifiers') {
        var idA = (a.doi || a.pubmed || '').toLowerCase();
        var idB = (b.doi || b.pubmed || '').toLowerCase();
        diff = idA.localeCompare(idB);
      }
      return sortDir === 'desc' ? -diff : diff;
    });
    return list;
  }

  function updateSortHeaders() {
    var ths = document.querySelectorAll('#reference-results-table thead th[aria-sort]');
    Array.prototype.forEach.call(ths, function (th) {
      var btn = th.querySelector('button[data-sort-key]');
      if (!btn) return;
      var key = btn.getAttribute('data-sort-key');
      if (key === sortKey) {
        th.setAttribute('aria-sort', sortDir === 'asc' ? 'ascending' : 'descending');
      } else {
        th.setAttribute('aria-sort', 'none');
      }
    });
  }

  function setView(mode) {
    viewMode = mode;
    var btnCards = byId('reference-view-cards');
    var btnTable = byId('reference-view-table');
    var cardsView = byId('reference-cards-view');
    var tableView = byId('reference-table-view');

    if (btnCards) {
      btnCards.classList.toggle('is-active', mode === 'card');
      btnCards.setAttribute('aria-pressed', mode === 'card' ? 'true' : 'false');
    }
    if (btnTable) {
      btnTable.classList.toggle('is-active', mode === 'table');
      btnTable.setAttribute('aria-pressed', mode === 'table' ? 'true' : 'false');
    }
    if (cardsView) cardsView.hidden = (mode !== 'card');
    if (tableView) tableView.hidden = (mode !== 'table');
  }

  function renderTableView(results) {
    var tbody = byId('reference-results-body');
    if (!tbody) return;
    tbody.innerHTML = results.map(function (row) {
      var title = row.title || row.name || 'Untitled reference';
      var citationParts = [row.journal, row.volume, row.pages].filter(Boolean);
      var citation = citationParts.join(' · ');
      var editorialPick = row.editorial_pick === true;
      var citationText = [row.authors, row.year ? '(' + row.year + ')' : '', title, citation].filter(Boolean).join('. ');

      var idParts = [];
      if (row.doi) {
        idParts.push('<a href="https://doi.org/' + encodeURIComponent(row.doi) + '" target="_blank" rel="noopener">DOI: ' + escapeHtml(row.doi) + ' ↗</a>');
      }
      if (row.pubmed) {
        idParts.push('<a href="https://pubmed.ncbi.nlm.nih.gov/' + encodeURIComponent(row.pubmed) + '/" target="_blank" rel="noopener">PMID: ' + escapeHtml(row.pubmed) + ' ↗</a>');
      }
      var idsHtml = idParts.length ? idParts.join('') : '<span style="color:var(--mgdb-muted)">—</span>';

      return '<tr data-reference-id="' + row.id + '">'
        + '<td class="reference-title-cell">'
        + '<strong><a href="/data_center/reference?id=' + row.id + '">' + escapeHtml(title) + '</a></strong>'
        + (citation ? '<div class="reference-citation-text">' + escapeHtml(citation) + '</div>' : '')
        + (editorialPick ? '<div style="margin-top:4px;"><span class="reference-editorial-badge">★ Editorial pick</span></div>' : '')
        + '</td>'
        + '<td>' + escapeHtml(row.authors || '—') + '</td>'
        + '<td><span class="reference-badge reference-badge-green">' + escapeHtml(row.year || '—') + '</span></td>'
        + '<td>'
        + '<div><strong>' + escapeHtml(row.journal || '—') + '</strong></div>'
        + (row.publication_type ? '<div style="margin-top:4px;"><span class="reference-badge">' + escapeHtml(row.publication_type) + '</span></div>' : '')
        + '</td>'
        + '<td><div class="reference-id-links">' + idsHtml + '</div></td>'
        + '<td>'
        + '<div style="display:flex; flex-direction:column; gap:4px; align-items:flex-start;">'
        + '<button type="button" class="reference-copy-btn" data-copy-citation="' + escapeHtml(citationText) + '">Copy citation</button>'
        + '<a href="/data_center/reference?id=' + row.id + '" style="font-size:var(--mgdb-text-xs); color:var(--mgdb-green-dark); text-decoration:none; font-weight:600;">Record &rarr;</a>'
        + '</div>'
        + '</td>'
        + '</tr>';
    }).join('');
  }

  function renderCardView(results) {
    var container = byId('reference-cards-view');
    if (!container) return;
    container.innerHTML = results.map(function (row) {
      var title = row.title || row.name || 'Untitled reference';
      var citationParts = [row.journal, row.volume, row.pages].filter(Boolean);
      var citation = citationParts.join(' · ');
      var editorialPick = row.editorial_pick === true;
      var citationText = [row.authors, row.year ? '(' + row.year + ')' : '', title, citation].filter(Boolean).join('. ');

      var links = '<a href="/data_center/reference?id=' + row.id + '">MaizeGDB record</a>';
      if (row.doi) {
        links += '<a href="https://doi.org/' + encodeURIComponent(row.doi) + '" target="_blank" rel="noopener">DOI ↗</a>';
      }
      if (row.pubmed) {
        links += '<a href="https://pubmed.ncbi.nlm.nih.gov/' + encodeURIComponent(row.pubmed) + '/" target="_blank" rel="noopener">PubMed ↗</a>';
      }
      links += '<button class="reference-copy-btn" type="button" data-copy-citation="' + escapeHtml(citationText) + '">Copy citation</button>';
      if (row.doi) {
        links += '<button class="reference-copy-btn" type="button" data-copy-value="' + escapeHtml(row.doi) + '">Copy DOI</button>';
      }
      if (row.pubmed) {
        links += '<button class="reference-copy-btn" type="button" data-copy-value="' + escapeHtml(row.pubmed) + '">Copy PMID</button>';
      }

      return '<article class="reference-result-card is-selectable' + (editorialPick ? ' is-editorial-pick' : '') + '" data-reference-id="' + row.id + '">'
        + '<label class="reference-result-select"><input type="checkbox" data-reference-select="' + row.id + '" aria-label="Select ' + escapeHtml(title) + '"></label>'
        + '<div style="flex:1; min-width:0;">'
        + '<div class="reference-result-meta">'
        + (row.year ? '<span class="reference-badge reference-badge-green">' + row.year + '</span>' : '')
        + (row.publication_type ? '<span class="reference-badge">' + escapeHtml(row.publication_type) + '</span>' : '')
        + (editorialPick ? '<span class="reference-editorial-badge">★ Editorial pick</span>' : '')
        + (row.journal ? '<span class="reference-badge"><strong>' + escapeHtml(row.journal) + '</strong></span>' : '')
        + '</div>'
        + '<h3><a href="/data_center/reference?id=' + row.id + '">' + escapeHtml(title) + '</a></h3>'
        + (row.authors ? '<p class="reference-result-authors">' + escapeHtml(row.authors) + '</p>' : '')
        + (citation ? '<p class="reference-result-citation">' + escapeHtml(citation) + '</p>' : '')
        + (row.abstract ? '<p class="reference-result-abstract">' + escapeHtml(row.abstract) + (row.abstract.length >= 695 ? '…' : '') + '</p>' : '')
        + '<div class="reference-result-links">' + links + '</div>'
        + '</div>'
        + '</article>';
    }).join('');
  }

  function bindActions() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-copy-citation]'), function (button) {
      button.addEventListener('click', function () {
        copyText(button.getAttribute('data-copy-citation'), button);
      });
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-copy-value]'), function (button) {
      button.addEventListener('click', function () {
        copyText(button.getAttribute('data-copy-value'), button);
      });
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-reference-select]'), function (checkbox) {
      checkbox.addEventListener('change', function () {
        if (checkbox.checked) selectedIds[checkbox.getAttribute('data-reference-select')] = true;
        else delete selectedIds[checkbox.getAttribute('data-reference-select')];
        updateSelection();
      });
    });
  }

  function renderResults(data) {
    var summary = data.summary;
    var total = summary.total || 0;
    var start = total ? (summary.page - 1) * summary.page_size + 1 : 0;
    var end = Math.min(total, summary.page * summary.page_size);
    var queryText = data.query.term ? ' for “' + data.query.term + '”' : '';
    byId('reference-results-status').textContent = total
      ? 'Showing ' + number(start) + '–' + number(end) + ' of ' + number(total)
        + ' matches' + queryText + ' · ' + number(summary.elapsed_ms) + ' ms'
      : 'No curated references matched' + queryText + '.';
    byId('reference-resting').hidden = true;
    byId('reference-empty').hidden = total !== 0;

    var selectPageBox = byId('reference-select-page');
    if (selectPageBox && selectPageBox.parentElement) {
      selectPageBox.parentElement.hidden = total === 0;
      selectPageBox.checked = false;
    }

    if (total === 0) {
      if (byId('reference-cards-view')) byId('reference-cards-view').hidden = true;
      if (byId('reference-table-view')) byId('reference-table-view').hidden = true;
      return;
    }

    var sorted = sortReferenceResults(data.results || []);
    renderTableView(sorted);
    renderCardView(sorted);
    updateSortHeaders();
    setView(viewMode);
    bindActions();
  }

  /* Narrows the page already rendered across table and cards synchronously. */
  function applyResultsFilter() {
    var cardsContainer = byId('reference-cards-view');
    var tableBody = byId('reference-results-body');
    var cards = cardsContainer ? cardsContainer.querySelectorAll('.reference-result-card') : [];
    var rows = tableBody ? tableBody.querySelectorAll('tr') : [];
    var terms = resultFilter.toLowerCase().split(/\s+/).filter(Boolean);
    var shownCards = 0;
    var shownRows = 0;

    Array.prototype.forEach.call(cards, function (card) {
      var match = true;
      if (terms.length) {
        var hay = (card.textContent || '').toLowerCase();
        for (var i = 0; i < terms.length; i++) {
          if (hay.indexOf(terms[i]) === -1) { match = false; break; }
        }
      }
      card.hidden = !match;
      if (match) shownCards++;
    });

    Array.prototype.forEach.call(rows, function (row) {
      var match = true;
      if (terms.length) {
        var hay = (row.textContent || '').toLowerCase();
        for (var i = 0; i < terms.length; i++) {
          if (hay.indexOf(terms[i]) === -1) { match = false; break; }
        }
      }
      row.hidden = !match;
      if (match) shownRows++;
    });

    var filterCount = byId('reference-filter-count');
    var totalOnPage = cards.length || rows.length;
    if (filterCount) {
      if (terms.length && totalOnPage) {
        var shown = Math.max(shownCards, shownRows);
        filterCount.textContent = shown + ' of ' + totalOnPage;
      } else {
        filterCount.textContent = '';
      }
    }
  }

  function renderPagination(page, pageCount) {
    var nav = byId('reference-pagination');
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
    if (start > 2) pages.push('<button type="button" disabled>…</button>');
    for (var i = start; i <= end; i += 1) {
      pages.push('<button type="button" data-page="' + i + '" class="' + (i === page ? 'is-active' : '') + '" aria-current="' + (i === page ? 'page' : 'false') + '">' + i + '</button>');
    }
    if (end < pageCount - 1) pages.push('<button type="button" disabled>…</button>');
    if (end < pageCount) pages.push('<button type="button" data-page="' + pageCount + '">' + pageCount + '</button>');
    pages.push('<button type="button" data-page="' + Math.min(pageCount, page + 1) + '"' + (page === pageCount ? ' disabled' : '') + '>Next</button>');
    nav.innerHTML = pages.join('');
    Array.prototype.forEach.call(nav.querySelectorAll('[data-page]'), function (button) {
      button.addEventListener('click', function () { loadSearch(parseInt(button.getAttribute('data-page'), 10), true); });
    });
  }

  function updateExports() {
    var params = formParams(1);
    params.delete('page');
    params.delete('page_size');
    Array.prototype.forEach.call(document.querySelectorAll('[data-reference-export]'), function (link) {
      var exportParams = new URLSearchParams(params.toString());
      exportParams.set('format', link.getAttribute('data-reference-export'));
      link.href = apiUrl + '?' + exportParams.toString();
    });
  }

  function selectedRows() {
    if (!currentData) return [];
    return currentData.results.filter(function (row) { return selectedIds[row.id]; });
  }

  function updateSelection() {
    var count = Object.keys(selectedIds).length;
    byId('reference-selection-bar').hidden = count === 0;
    byId('reference-selection-count').textContent = count;
    var checkboxes = Array.prototype.slice.call(document.querySelectorAll('[data-reference-select]'));
    byId('reference-select-page').checked = Boolean(checkboxes.length) && checkboxes.every(function (box) { return box.checked; });
  }

  function copyText(text, button) {
    function done() {
      if (!button) return;
      var original = button.textContent;
      button.textContent = 'Copied';
      window.setTimeout(function () { button.textContent = original; }, 1200);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done);
    } else {
      var area = document.createElement('textarea');
      area.value = text;
      document.body.appendChild(area);
      area.select();
      document.execCommand('copy');
      document.body.removeChild(area);
      done();
    }
  }

  function downloadBlob(filename, type, contents) {
    var blob = new Blob([contents], {type: type});
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
  }

  function selectedExport(format, button) {
    var rows = selectedRows();
    if (!rows.length) return;
    if (format === 'doi' || format === 'pmid') {
      var field = format === 'doi' ? 'doi' : 'pubmed';
      var values = rows.map(function (row) { return row[field]; }).filter(Boolean);
      copyText(values.join('\n'), button);
      return;
    }
    var csv = [['MaizeGDB ID', 'Year', 'Title', 'Authors', 'Journal', 'DOI', 'PubMed ID']]
      .concat(rows.map(function (row) { return [row.id, row.year || '', row.title || '', row.authors || '', row.journal || '', row.doi || '', row.pubmed || '']; }))
      .map(function (line) { return line.map(function (cell) { return '"' + String(cell).replace(/"/g, '""') + '"'; }).join(','); }).join('\n');
    downloadBlob('maizegdb-selected-references.csv', 'text/csv;charset=utf-8', csv);
  }

  function resetFilters() {
    if (byId('reference-year-from')) byId('reference-year-from').value = '';
    if (byId('reference-year-to')) byId('reference-year-to').value = '';
    if (byId('reference-journal')) byId('reference-journal').value = '';
    if (byId('reference-pub-type')) byId('reference-pub-type').value = '';
    if (byId('reference-identifier')) byId('reference-identifier').value = 'all';
    if (byId('reference-sort')) byId('reference-sort').value = byId('reference-query') && byId('reference-query').value ? 'relevance' : 'newest';
    if (byId('reference-editorial')) byId('reference-editorial').checked = false;
    if (byId('reference-include-meeting')) byId('reference-include-meeting').checked = true;
    if (byId('reference-include-mnl')) byId('reference-include-mnl').checked = true;
    syncAdvancedBadge();
    refresh(1, false);
  }

  function initSortButtons() {
    var buttons = document.querySelectorAll('#reference-results-table thead th button[data-sort-key]');
    Array.prototype.forEach.call(buttons, function (button) {
      button.addEventListener('click', function () {
        var key = button.getAttribute('data-sort-key');
        if (sortKey === key) {
          sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        } else {
          sortKey = key;
          sortDir = (key === 'year' ? 'desc' : 'asc');
        }
        if (currentData && currentData.results) {
          var sorted = sortReferenceResults(currentData.results);
          renderTableView(sorted);
          renderCardView(sorted);
          updateSortHeaders();
          bindActions();
          applyResultsFilter();
        }
      });
    });
  }

  function initViewToggle() {
    var btnCards = byId('reference-view-cards');
    var btnTable = byId('reference-view-table');
    if (btnCards) {
      btnCards.addEventListener('click', function () { setView('card'); });
    }
    if (btnTable) {
      btnTable.addEventListener('click', function () { setView('table'); });
    }
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

    var results = byId('reference-results-section');
    if (results && window.MutationObserver) {
      new window.MutationObserver(update).observe(results, {
        childList: true, subtree: true, attributes: true, attributeFilter: ['hidden']
      });
    }

    update();
  }

  function initialize() {
    if (!byId('reference-search-form')) return;
    buildTabs();
    initSortButtons();
    initViewToggle();
    applyUrlState();

    byId('reference-search-form').addEventListener('submit', function (event) {
      event.preventDefault();
      refresh(1, true);
    });

    var advSubmit = byId('reference-adv-submit');
    if (advSubmit) {
      advSubmit.addEventListener('click', function (event) {
        event.preventDefault();
        refresh(1, true);
      });
    }

    byId('reference-query').addEventListener('input', function () {
      byId('reference-query-clear').hidden = !byId('reference-query').value;
      window.clearTimeout(searchTimer);
      if (byId('reference-query').value.length === 0 || byId('reference-query').value.length >= 3) {
        searchTimer = window.setTimeout(function () { refresh(1, false); }, 500);
      }
    });

    byId('reference-query-clear').addEventListener('click', function () {
      byId('reference-query').value = '';
      byId('reference-query-clear').hidden = true;
      refresh(1, false);
      byId('reference-query').focus();
    });

    byId('reference-scope').addEventListener('change', function () { refresh(1, false); });

    ['reference-year-from', 'reference-year-to', 'reference-journal', 'reference-pub-type', 'reference-identifier',
      'reference-sort', 'reference-editorial', 'reference-include-meeting', 'reference-include-mnl']
      .forEach(function (id) {
        var el = byId(id);
        if (el) {
          el.addEventListener('change', function () {
            syncAdvancedBadge();
            refresh(1, false);
          });
        }
      });

    byId('reference-page-size').addEventListener('change', function () {
      pageSize = this.value === 'all' ? 'all' : parseInt(this.value, 10) || 25;
      refresh(1, false);
    });

    byId('reference-results-filter').addEventListener('input', function () {
      resultFilter = this.value.trim();
      applyResultsFilter();
    });

    var resetBtn = byId('reference-reset-filters');
    if (resetBtn) {
      resetBtn.addEventListener('click', resetFilters);
    }

    byId('reference-retry').addEventListener('click', function () { refresh(currentPage, false); });

    Array.prototype.forEach.call(document.querySelectorAll('[data-reference-example]'), function (button) {
      button.addEventListener('click', function () {
        byId('reference-query').value = button.getAttribute('data-reference-example');
        byId('reference-scope').value = button.getAttribute('data-reference-scope') || 'all';
        byId('reference-query-clear').hidden = false;
        syncAdvancedBadge();
        refresh(1, true);
      });
    });

    byId('reference-select-page').addEventListener('change', function () {
      Array.prototype.forEach.call(document.querySelectorAll('[data-reference-select]'), function (box) {
        box.checked = byId('reference-select-page').checked;
        if (box.checked) selectedIds[box.getAttribute('data-reference-select')] = true;
        else delete selectedIds[box.getAttribute('data-reference-select')];
      });
      updateSelection();
    });

    byId('reference-selection-clear').addEventListener('click', function () {
      selectedIds = {};
      Array.prototype.forEach.call(document.querySelectorAll('[data-reference-select]'), function (box) { box.checked = false; });
      updateSelection();
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-selected-export]'), function (button) {
      button.addEventListener('click', function () { selectedExport(button.getAttribute('data-selected-export'), button); });
    });

    syncAdvancedBadge();

    /* Only run a search when the URL actually asks for one. A bare
       /data_center/reference now loads the dashboard and waits. */
    refresh(currentPage, false);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }
}());
