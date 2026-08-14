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
    if (byId('reference-editorial').checked) {
      params.set('editorial', '1');
    }
    if (!byId('reference-include-meeting').checked) {
      params.set('include_meeting', '0');
    }
    if (!byId('reference-include-mnl').checked) {
      params.set('include_mnl', '0');
    }
    params.set('page', page || 1);
    params.set('page_size', '20');
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
    byId('reference-editorial').checked = params.get('editorial') === '1';
    byId('reference-include-meeting').checked = params.get('include_meeting') !== '0';
    byId('reference-include-mnl').checked = params.get('include_mnl') !== '0';
    currentPage = Math.max(1, parseInt(params.get('page') || '1', 10));
    byId('reference-sort-compact').value = byId('reference-sort').value;
    byId('reference-query-clear').hidden = !byId('reference-query').value;
  }

  function updateUrl(params) {
    var clean = new URLSearchParams(params.toString());
    if (clean.get('page') === '1') clean.delete('page');
    clean.delete('page_size');
    var next = window.location.pathname + (clean.toString() ? '?' + clean.toString() : '');
    window.history.replaceState({}, '', next);
  }

  function setLoading(loading) {
    byId('reference-loading').hidden = !loading;
    byId('reference-results').hidden = loading;
    if (loading) {
      byId('reference-empty').hidden = true;
      byId('reference-error').hidden = true;
      byId('reference-pagination').innerHTML = '';
      byId('reference-results-status').textContent = 'Searching curated literature…';
    }
  }

  function loadSearch(page, scrollToResults) {
    currentPage = page || 1;
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
  }

  function renderSummary(data) {
    var summary = data.summary;
    var years = data.facets.year.map(function (row) { return parseInt(row.value, 10); })
      .filter(function (year) { return !isNaN(year); });
    byId('reference-dashboard').hidden = summary.total === 0;
    byId('reference-total-count').textContent = number(summary.total);
    byId('reference-doi-count').textContent = number(summary.with_doi);
    byId('reference-pubmed-count').textContent = number(summary.with_pubmed);
    byId('reference-year-span').textContent = years.length
      ? Math.min.apply(Math, years) + '–' + Math.max.apply(Math, years)
      : '—';

    var description = data.query.term
      ? 'Figures summarize all papers matching “' + data.query.term + ',” not only the current page.'
      : 'Figures summarize the complete curated reference collection.';
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

  function renderResults(data) {
    var results = byId('reference-results');
    var summary = data.summary;
    var start = summary.total ? (summary.page - 1) * summary.page_size + 1 : 0;
    var end = Math.min(summary.total, summary.page * summary.page_size);
    var queryText = data.query.term ? ' for “' + data.query.term + '”' : '';
    byId('reference-results-status').textContent = summary.total
      ? 'Showing ' + number(start) + '–' + number(end) + ' of ' + number(summary.total)
        + ' matches' + queryText + ' · ' + number(summary.elapsed_ms) + ' ms'
      : 'No curated references matched' + queryText + '.';
    byId('reference-empty').hidden = summary.total !== 0;
    results.hidden = summary.total === 0;
    byId('reference-select-page').parentElement.hidden = summary.total === 0;
    byId('reference-select-page').checked = false;

    results.innerHTML = data.results.map(function (row) {
      var title = row.title || row.name || 'Untitled reference';
      var citation = [row.journal, row.volume, row.pages].filter(Boolean).join(' · ');
      var links = '<a href="/data_center/reference?id=' + row.id + '">MaizeGDB record</a>';
      if (row.doi) {
        links += '<a href="https://doi.org/' + encodeURIComponent(row.doi) + '" target="_blank" rel="noopener">DOI ↗</a>';
        links += '<button class="reference-copy-id" type="button" data-copy-value="' + escapeHtml(row.doi) + '">Copy DOI</button>';
      }
      if (row.pubmed) {
        links += '<a href="https://pubmed.ncbi.nlm.nih.gov/' + encodeURIComponent(row.pubmed) + '/" target="_blank" rel="noopener">PubMed ↗</a>';
        links += '<button class="reference-copy-id" type="button" data-copy-value="' + escapeHtml(row.pubmed) + '">Copy PMID</button>';
      }
      var editorialPick = row.editorial_pick === true;
      return '<article class="reference-result-card' + (editorialPick ? ' is-editorial-pick' : '') + '" data-reference-id="' + row.id + '">'
        + '<label class="reference-result-select"><input type="checkbox" data-reference-select="' + row.id + '" aria-label="Select ' + escapeHtml(title) + '"></label>'
        + '<div><div class="reference-result-meta">'
        + (row.year ? '<span>' + row.year + '</span>' : '')
        + (row.publication_type ? '<span>' + escapeHtml(row.publication_type) + '</span>' : '')
        + (editorialPick ? '<span class="reference-editorial-badge">★ Editorial pick</span>' : '')
        + (row.doi ? '<span>DOI</span>' : '') + (row.pubmed ? '<span>PubMed</span>' : '')
        + '</div><h3><a href="/data_center/reference?id=' + row.id + '">' + escapeHtml(title) + '</a></h3>'
        + (row.authors ? '<p class="reference-result-authors">' + escapeHtml(row.authors) + '</p>' : '')
        + (citation ? '<p class="reference-result-citation">' + escapeHtml(citation) + '</p>' : '')
        + (row.abstract ? '<p class="reference-result-abstract">' + escapeHtml(row.abstract) + (row.abstract.length >= 695 ? '…' : '') + '</p>' : '')
        + '<div class="reference-result-links">' + links + '</div></div></article>';
    }).join('');

    Array.prototype.forEach.call(document.querySelectorAll('[data-reference-select]'), function (checkbox) {
      checkbox.addEventListener('change', function () {
        if (checkbox.checked) selectedIds[checkbox.getAttribute('data-reference-select')] = true;
        else delete selectedIds[checkbox.getAttribute('data-reference-select')];
        updateSelection();
      });
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-copy-value]'), function (button) {
      button.addEventListener('click', function () {
        copyText(button.getAttribute('data-copy-value'), button);
      });
    });
  }

  function renderPagination(page, pageCount) {
    var nav = byId('reference-pagination');
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
    byId('reference-year-from').value = '';
    byId('reference-year-to').value = '';
    byId('reference-journal').value = '';
    byId('reference-pub-type').value = '';
    byId('reference-identifier').value = 'all';
    byId('reference-sort').value = byId('reference-query').value ? 'relevance' : 'newest';
    byId('reference-sort-compact').value = byId('reference-sort').value;
    byId('reference-editorial').checked = false;
    byId('reference-include-meeting').checked = true;
    byId('reference-include-mnl').checked = true;
    loadSearch(1, false);
  }

  function initialize() {
    if (!byId('reference-search-form')) return;
    applyUrlState();

    byId('reference-search-form').addEventListener('submit', function (event) {
      event.preventDefault();
      loadSearch(1, true);
    });
    byId('reference-query').addEventListener('input', function () {
      byId('reference-query-clear').hidden = !byId('reference-query').value;
      window.clearTimeout(searchTimer);
      if (byId('reference-query').value.length === 0 || byId('reference-query').value.length >= 3) {
        searchTimer = window.setTimeout(function () { loadSearch(1, false); }, 500);
      }
    });
    byId('reference-query-clear').addEventListener('click', function () {
      byId('reference-query').value = '';
      byId('reference-query-clear').hidden = true;
      loadSearch(1, false);
      byId('reference-query').focus();
    });
    byId('reference-scope').addEventListener('change', function () { loadSearch(1, false); });
    ['reference-year-from', 'reference-year-to', 'reference-journal', 'reference-pub-type', 'reference-identifier',
      'reference-editorial', 'reference-include-meeting', 'reference-include-mnl']
      .forEach(function (id) { byId(id).addEventListener('change', function () { loadSearch(1, false); }); });
    byId('reference-sort').addEventListener('change', function () {
      byId('reference-sort-compact').value = byId('reference-sort').value;
      loadSearch(1, false);
    });
    byId('reference-sort-compact').addEventListener('change', function () {
      byId('reference-sort').value = byId('reference-sort-compact').value;
      loadSearch(1, false);
    });
    byId('reference-reset-filters').addEventListener('click', resetFilters);
    byId('reference-retry').addEventListener('click', function () { loadSearch(currentPage, false); });

    Array.prototype.forEach.call(document.querySelectorAll('[data-reference-example]'), function (button) {
      button.addEventListener('click', function () {
        byId('reference-query').value = button.getAttribute('data-reference-example');
        byId('reference-scope').value = button.getAttribute('data-reference-scope') || 'all';
        byId('reference-query-clear').hidden = false;
        loadSearch(1, true);
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

    loadSearch(currentPage, false);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }
}());
