/* file: js/mgdb-metabolic-pathways.js
 *
 * The Metabolic Pathways Data Hub: pathway search, the assembly figure, and
 * the resource matches that sit under the search box.
 *
 * Two kinds of answer, kept apart on purpose. A term can name a pathway (the
 * results table, from the server) or an external database (a short list under
 * the search box, from the index inlined in the page). Mixing the second into
 * the first would make the result count describe two different things.
 */
(function () {
  'use strict';

  var ENDPOINT = '/search/metabolic_pathway/metabolic_pathway_search_api.php';

  /* Filled by init(). The shell emits page scripts in <head>, so at parse time
     none of these elements exist yet -- and because every use below is guarded,
     reading them here failed silently rather than erroring: the figure and the
     search simply never appeared. */
  var form, termInput, queryClear, assembly, pageSize, resultsSection, resultsContainer,
      tableView, cardsView, table, statusEl, pager, tsvLink, hits, filterInput, filterCount,
      advAccordion, advCount, advSubmit, advReset, emptyEl, emptyReset, viewCardsBtn, viewTableBtn;
  var index = null, resources = [], chartRows = [];
  var lastResults = [];
  var lastSummary = null;

  var esc = (window.MGDB && MGDB.escapeHtml) ? MGDB.escapeHtml : function (s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  };

  function num(n) {
    return (typeof n === 'number' ? n : 0).toLocaleString('en-US');
  }

  function readJson(id) {
    var el = document.getElementById(id);
    if (!el) { return null; }
    try { return JSON.parse(el.textContent || el.innerText || 'null'); }
    catch (e) { return null; }
  }

  /* MetaCyc's own inline markup travels with a pathway name -- <i>, <sub>,
     <sup> and four more. The server escaped the name and restored exactly
     those tags, so the string arriving here is already safe as HTML; escaping
     it a second time would print the tags at the reader. Everything else in a
     row is escaped normally. */
  var RICH = /^(?:[^<>]|<\/?(?:i|em|sub|sup|small|b|strong)>)*$/i;

  function richName(name) {
    return RICH.test(name) ? name : esc(name);
  }

  /* ------------------------------------------------------------------ *
   * Pathway search state
   * ------------------------------------------------------------------ */

  var state = {
    term: '',
    assembly: '',
    page: 1,
    pageSize: 25,
    sortKey: 'name',
    sortDir: 'asc',
    view: 'table',
    filter: '',
    searched: false,
    total: 0,
    pages: 0
  };

  function query(extra) {
    var p = new URLSearchParams();
    if (state.term) { p.set('term', state.term); }
    if (state.assembly) { p.set('assembly', state.assembly); }
    p.set('page', state.pageSize === 'all' ? '1' : String(state.page));
    p.set('page_size', state.pageSize === 'all' ? '600' : String(state.pageSize));
    if (extra) { Object.keys(extra).forEach(function (k) { p.set(k, extra[k]); }); }
    return p.toString();
  }

  /* ------------------------------------------------------------------ *
   * Sorting
   * ------------------------------------------------------------------ */

  function getHeaderAriaSort(colKey) {
    if (state.sortKey === colKey) {
      return state.sortDir === 'desc' ? 'descending' : 'ascending';
    }
    return 'none';
  }

  function sortPathwayResults(results) {
    if (!results || !results.length) return [];
    var key = state.sortKey || 'name';
    var dir = state.sortDir === 'desc' ? -1 : 1;

    return results.slice().sort(function (a, b) {
      if (key === 'gene_models') {
        var aG = Number(a.gene_models) || 0;
        var bG = Number(b.gene_models) || 0;
        if (aG !== bG) return (aG - bG) * dir;
      } else if (key === 'proteins') {
        var aP = Number(a.proteins) || 0;
        var bP = Number(b.proteins) || 0;
        if (aP !== bP) return (aP - bP) * dir;
      } else if (key === 'id') {
        var aId = String(a.id || '').trim().toLowerCase();
        var bId = String(b.id || '').trim().toLowerCase();
        var cmpId = aId.localeCompare(bId, undefined, { numeric: true });
        if (cmpId !== 0) return cmpId * dir;
      } else if (key === 'assemblies') {
        var aAsm = (a.assemblies || []).join(', ').toLowerCase();
        var bAsm = (b.assemblies || []).join(', ').toLowerCase();
        var cmpAsm = aAsm.localeCompare(bAsm);
        if (cmpAsm !== 0) return cmpAsm * dir;
      }
      var aName = String(a.name || '').trim().toLowerCase();
      var bName = String(b.name || '').trim().toLowerCase();
      return aName.localeCompare(bName, undefined, { numeric: true }) * dir;
    });
  }

  function initSortButtons() {
    if (!table) return;
    Array.prototype.forEach.call(table.querySelectorAll('thead th button[data-sort-key]'), function (btn) {
      btn.addEventListener('click', function () {
        var key = btn.getAttribute('data-sort-key');
        if (state.sortKey === key) {
          state.sortDir = (state.sortDir === 'asc') ? 'desc' : 'asc';
        } else {
          state.sortKey = key;
          state.sortDir = (key === 'gene_models' || key === 'proteins') ? 'desc' : 'asc';
        }
        updateSortHeaders();
        renderResults();
        applyResultsFilter();
      });
    });
  }

  function updateSortHeaders() {
    if (!table) return;
    Array.prototype.forEach.call(table.querySelectorAll('thead th[aria-sort]'), function (th) {
      var btn = th.querySelector('button[data-sort-key]');
      if (!btn) return;
      var key = btn.getAttribute('data-sort-key');
      th.setAttribute('aria-sort', getHeaderAriaSort(key));
    });
  }

  /* ------------------------------------------------------------------ *
   * Results rendering
   * ------------------------------------------------------------------ */

  function renderResults() {
    if (!lastResults || !lastResults.length) {
      if (emptyEl) emptyEl.hidden = false;
      if (tableView) tableView.hidden = true;
      if (cardsView) cardsView.hidden = true;
      return;
    }
    if (emptyEl) emptyEl.hidden = true;

    var sorted = sortPathwayResults(lastResults);

    renderTableView(sorted);
    renderCardsView(sorted);
    updateViewDisplay();
  }

  function renderTableView(rows) {
    if (!table || !table.tBodies[0]) return;
    var body = table.tBodies[0];
    var html = '';
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      var asmPills = (r.assemblies || []).map(function (a) {
        return '<span class="mgdb-pill mgdb-pill-sm">' + esc(a) + '</span>';
      }).join(' ');

      html += '<tr>'
            + '<th scope="row"><strong><a href="' + esc(r.url) + '" target="_blank" rel="noopener">'
            + richName(r.name_html || r.name) + ' <span aria-hidden="true">&nearr;</span></a></strong></th>'
            + '<td class="mgdb-sequence"><a href="' + esc(r.metacyc_url) + '" target="_blank" rel="noopener">'
            + esc(r.id) + ' <span aria-hidden="true">&nearr;</span></a></td>'
            + '<td>' + (asmPills || '<span class="mgdb-muted">—</span>') + '</td>'
            + '<td class="mgdb-numeric">' + num(r.gene_models) + '</td>'
            + '<td class="mgdb-numeric">' + num(r.proteins) + '</td>'
            + '</tr>';
    }
    body.innerHTML = html;
  }

  function renderCardsView(rows) {
    if (!cardsView) return;
    var html = '';
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      var asmPills = (r.assemblies || []).map(function (a) {
        return '<span class="mgdb-pill mgdb-pill-sm">' + esc(a) + '</span>';
      }).join(' ');

      html += '<article class="mp-pathway-card">'
            + '  <div>'
            + '    <div class="mp-pathway-card-header">'
            + '      <span class="mgdb-pill mgdb-pill-ok">' + esc(r.id) + '</span>'
            +        asmPills
            + '    </div>'
            + '    <h3 class="mp-pathway-card-title"><a href="' + esc(r.url) + '" target="_blank" rel="noopener">'
            +        richName(r.name_html || r.name) + ' <span aria-hidden="true">&nearr;</span></a></h3>'
            + '    <div class="mp-pathway-card-meta">'
            + '      <span><strong>' + num(r.gene_models) + '</strong> gene models</span> &bull; '
            + '      <span><strong>' + num(r.proteins) + '</strong> enzymes</span>'
            + '    </div>'
            + '  </div>'
            + '  <div class="mp-pathway-card-footer">'
            + '    <a href="' + esc(r.metacyc_url) + '" target="_blank" rel="noopener">MetaCyc entry &nearr;</a>'
            + '    <a class="mgdb-button mgdb-button-quiet" href="' + esc(r.url) + '" target="_blank" rel="noopener">PlantCyc &rarr;</a>'
            + '  </div>'
            + '</article>';
    }
    cardsView.innerHTML = html;
  }

  function updateViewDisplay() {
    var isTable = (state.view === 'table');
    if (tableView) tableView.hidden = !isTable;
    if (cardsView) cardsView.hidden = isTable;
    if (resultsContainer) {
      resultsContainer.className = 'mp-results-container mp-view-' + state.view;
    }
    if (viewCardsBtn) {
      viewCardsBtn.classList.toggle('is-active', !isTable);
      viewCardsBtn.setAttribute('aria-pressed', !isTable ? 'true' : 'false');
    }
    if (viewTableBtn) {
      viewTableBtn.classList.toggle('is-active', isTable);
      viewTableBtn.setAttribute('aria-pressed', isTable ? 'true' : 'false');
    }
  }

  /* ------------------------------------------------------------------ *
   * Filter within results
   * ------------------------------------------------------------------ */

  function applyResultsFilter() {
    var terms = state.filter.toLowerCase().split(/\s+/).filter(Boolean);
    var tableRows = table ? table.querySelectorAll('tbody tr') : [];
    var cards = cardsView ? cardsView.querySelectorAll('.mp-pathway-card') : [];
    var shown = 0;

    function checkItem(el) {
      if (!terms.length) {
        el.hidden = false;
        return true;
      }
      var text = (el.textContent || '').toLowerCase();
      for (var i = 0; i < terms.length; i++) {
        if (text.indexOf(terms[i]) === -1) {
          el.hidden = true;
          return false;
        }
      }
      el.hidden = false;
      return true;
    }

    Array.prototype.forEach.call(tableRows, function (row) {
      if (checkItem(row)) shown++;
    });
    Array.prototype.forEach.call(cards, function (card) {
      checkItem(card);
    });

    if (filterCount) {
      filterCount.textContent = terms.length ? shown + ' match' + (shown === 1 ? '' : 'es') : '';
    }
    updateStatusText();
  }

  /* ------------------------------------------------------------------ *
   * Scope & Status
   * ------------------------------------------------------------------ */

  var MATCHED = {
    pathway_id:          'matching that CornCyc pathway ID',
    pathway_id_and_name: 'matching that CornCyc pathway ID or name',
    gene_model:          'assigned to that gene model',
    pathway_name:        'whose name matches that term'
  };

  function updateStatusText() {
    if (!statusEl) return;
    if (!lastSummary || lastSummary.total === 0) {
      statusEl.textContent = 'No matching pathways found.';
      return;
    }
    var what = MATCHED[lastSummary.matched_by] || 'in the collection';
    var first = state.pageSize === 'all' ? 1 : (lastSummary.page - 1) * lastSummary.page_size + 1;
    var last = state.pageSize === 'all' ? lastSummary.total : Math.min(lastSummary.total, lastSummary.page * lastSummary.page_size);

    if (state.filter) {
      var count = filterCount && filterCount.textContent ? filterCount.textContent : '0 matches';
      statusEl.innerHTML = 'Showing <strong>' + count + '</strong> on this page matching “' + esc(state.filter)
        + '”, out of ' + num(lastSummary.total) + ' pathways ' + what + '.';
    } else if (state.pageSize === 'all') {
      statusEl.innerHTML = 'Showing all ' + num(lastSummary.total) + ' matching pathways ' + what + '. (' + num(lastSummary.elapsed_ms) + ' ms)';
    } else {
      statusEl.innerHTML = 'Showing ' + num(first) + '–' + num(last) + ' of ' + num(lastSummary.total)
        + ' pathways ' + what + '. (' + num(lastSummary.elapsed_ms) + ' ms)';
    }
  }

  function renderPagination(summary) {
    if (!pager) return;

    if (state.pageSize === 'all' || !summary || summary.page_count <= 1) {
      pager.innerHTML = '';
      return;
    }

    var totalPages = summary.page_count;
    var curPage = summary.page;
    var html = '';

    html += '<button class="mp-page-btn" type="button" data-page="' + (curPage - 1) + '" ' + (curPage === 1 ? 'disabled' : '') + '>&larr; Prev</button>';

    var pages = [];
    pages.push(1);
    if (curPage > 3) pages.push('...');
    for (var p = Math.max(2, curPage - 1); p <= Math.min(totalPages - 1, curPage + 1); p++) {
      pages.push(p);
    }
    if (curPage < totalPages - 2) pages.push('...');
    if (totalPages > 1) pages.push(totalPages);

    pages.forEach(function (p) {
      if (p === '...') {
        html += '<span class="mp-page-ellipsis">&hellip;</span>';
      } else {
        html += '<button class="mp-page-btn ' + (p === curPage ? 'is-active' : '') + '" type="button" data-page="' + p + '">' + p + '</button>';
      }
    });

    html += '<button class="mp-page-btn" type="button" data-page="' + (curPage + 1) + '" ' + (curPage === totalPages ? 'disabled' : '') + '>Next &rarr;</button>';

    pager.innerHTML = html;

    pager.querySelectorAll('button[data-page]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var page = parseInt(this.getAttribute('data-page'), 10);
        if (page && page !== state.page && page >= 1 && page <= totalPages) {
          state.page = page;
          run(true);
        }
      });
    });
  }

  function run(scrollToResults) {
    if (resultsSection) {
      resultsSection.hidden = false;
      resultsSection.setAttribute('aria-busy', 'true');
    }

    var url = ENDPOINT + '?' + query();
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (resultsSection) resultsSection.setAttribute('aria-busy', 'false');
        if (!data || !data.ok) {
          lastResults = [];
          lastSummary = null;
          renderResults();
          if (statusEl) statusEl.textContent = (data && data.message) || 'The search could not be completed.';
          if (pager) pager.innerHTML = '';
          return;
        }
        state.searched = true;
        state.total = data.summary.total;
        state.pages = data.summary.page_count;
        lastResults = data.results || [];
        lastSummary = data.summary;

        renderResults();
        applyResultsFilter();
        renderPagination(data.summary);
        updateStatusText();

        if (tsvLink) {
          tsvLink.href = ENDPOINT + '?' + query({ format: 'tsv' });
        }
        if (window.MGDB && MGDB.announce && statusEl) {
          MGDB.announce(statusEl.textContent);
        }
        if (scrollToResults && resultsSection) {
          resultsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      })
      .catch(function (err) {
        console.error('Pathway search error', err);
        if (resultsSection) resultsSection.setAttribute('aria-busy', 'false');
        lastResults = [];
        lastSummary = null;
        renderResults();
        if (statusEl) statusEl.textContent = 'The search could not be reached.';
        if (pager) pager.innerHTML = '';
      });
  }

  /* ------------------------------------------------------------------ *
   * Resource matches
   * ------------------------------------------------------------------ */

  function normalize(s) {
    return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
  }

  function renderResourceHits(term) {
    if (!hits) { return; }
    var needle = normalize(term);
    if (!needle) { hits.hidden = true; hits.innerHTML = ''; return; }

    var found = resources.filter(function (r) {
      var hay = normalize([r.name, r.provider, r.summary, (r.keywords || []).join(' ')].join(' '));
      return hay.indexOf(needle) !== -1;
    });

    if (!found.length) { hits.hidden = true; hits.innerHTML = ''; return; }

    var html = '<strong>' + num(found.length) + ' '
             + (found.length === 1 ? 'database on this page matches' : 'databases on this page match')
             + ' &ldquo;' + esc(term) + '&rdquo;</strong><ul>';
    found.forEach(function (r) {
      html += '<li><a href="' + esc(r.url) + '" target="_blank" rel="noopener">'
            + esc(r.name) + ' <span aria-hidden="true">&nearr;</span></a>'
            + ' &mdash; ' + esc(r.section) + '</li>';
    });
    hits.innerHTML = html + '</ul>';
    hits.hidden = false;
  }

  /* ------------------------------------------------------------------ *
   * The assembly figure
   * ------------------------------------------------------------------ */

  function fillAssemblyTable() {
    var body = document.getElementById('mp-assembly-rows');
    if (!body) { return; }
    var html = '';
    chartRows.forEach(function (r) {
      html += '<tr><th scope="row">' + esc(r.assembly) + '</th>'
            + '<td class="mgdb-numeric">' + num(r.pathways) + '</td>'
            + '<td class="mgdb-numeric">' + num(r.gene_models) + '</td>'
            + '<td class="mgdb-numeric">' + num(r.proteins) + '</td></tr>';
    });
    body.innerHTML = html;
  }

  /* Margins are computed from the element's own width rather than fixed:
     MGDB.chart re-runs Plotly's resize on a viewport change, which rescales
     the figure but keeps the margins it was drawn with, so a desktop gutter
     would survive onto a phone and squeeze the plot to nothing. */
  function chartMetrics(el) {
    var w = el.getBoundingClientRect().width;
    var narrow = w > 0 && w < 560;
    return {
      narrow: narrow,
      margin: narrow ? { l: 84, r: 16, t: 8, b: 44 } : { l: 132, r: 72, t: 8, b: 44 },
      tickformat: narrow ? '~s' : ',d',
      nticks: narrow ? 3 : 0
    };
  }

  function drawChart() {
    var el = document.getElementById('mp-assembly-chart');
    if (!el || !chartRows.length || !window.MGDB || !MGDB.chart) { return; }

    /* One row per assembly per series, so the element height and the Plotly
       height come from the same number -- .mgdb-chart is otherwise a fixed
       320px and a taller figure would be clipped. The floor is the sheet's own
       min-height: a smaller number loses to it and the two disagree, which is
       how the element ended up 304px tall around a 320px figure. */
    var height = Math.max(320, chartRows.length * 2 * 46 + 120);
    el.style.height = height + 'px';

    var labels = chartRows.map(function (r) { return r.assembly; });
    var short  = chartRows.map(function (r) { return r.short; });
    var m = chartMetrics(el);

    MGDB.chart({
      target: el,
      traces: [
        { type: 'bar', orientation: 'h', name: 'Gene models',
          y: labels, x: chartRows.map(function (r) { return r.gene_models; }),
          hovertemplate: '%{y}<br>%{x:,} gene models<extra></extra>' },
        { type: 'bar', orientation: 'h', name: 'Pathways',
          y: labels, x: chartRows.map(function (r) { return r.pathways; }),
          hovertemplate: '%{y}<br>%{x:,} pathways<extra></extra>' }
      ],
      layout: {
        height: height,
        barmode: 'group',
        margin: m.margin,
        /* Plotly pins a category axis's values on the first draw, so the bars
           are keyed on the full assembly names and the short forms are swapped
           in as ticktext. Restyling `y` would create new categories instead. */
        yaxis: { automargin: false, tickmode: 'array', tickvals: labels,
                 ticktext: m.narrow ? short : labels },
        xaxis: { title: { text: 'Count' }, tickformat: m.tickformat, nticks: m.nticks }
      }
    });

    /* Relayout only when the breakpoint is actually crossed, so an ordinary
       resize does not redraw. */
    var wasNarrow = m.narrow;
    window.addEventListener('resize', function () {
      var next = chartMetrics(el);
      if (next.narrow === wasNarrow) { return; }
      wasNarrow = next.narrow;
      if (window.Plotly) {
        Plotly.relayout(el, {
          margin: next.margin,
          'xaxis.tickformat': next.tickformat,
          'xaxis.nticks': next.nticks,
          'yaxis.ticktext': next.narrow ? short : labels
        });
      }
    });
  }

  /* ------------------------------------------------------------------ *
   * Form & Control Helpers
   * ------------------------------------------------------------------ */

  function updateClearBtn() {
    if (!queryClear || !termInput) return;
    queryClear.hidden = (termInput.value.length === 0);
  }

  function syncAdvancedBadge() {
    if (!advCount) return;
    var count = 0;
    if (assembly && assembly.value && assembly.value !== '') count++;
    advCount.textContent = count ? count + ' active' : '';
    advCount.hidden = !count;
  }

  function submit(scroll) {
    state.term = termInput ? termInput.value.trim() : '';
    state.assembly = assembly ? assembly.value : '';
    state.pageSize = pageSize ? pageSize.value : 25;
    state.page = 1;
    updateClearBtn();
    syncAdvancedBadge();
    renderResourceHits(state.term);
    run(scroll !== false);
  }

  /* ------------------------------------------------------------------ *
   * Initialization
   * ------------------------------------------------------------------ */

  function init() {
    form             = document.getElementById('mp-search-form');
    termInput        = document.getElementById('mp-term');
    queryClear       = document.getElementById('mp-query-clear');
    assembly         = document.getElementById('mp-assembly');
    pageSize         = document.getElementById('mp-page-size');
    resultsSection   = document.getElementById('mp-results-section');
    resultsContainer = document.getElementById('mp-results-container');
    tableView        = document.getElementById('mp-table-view');
    cardsView        = document.getElementById('mp-cards-view');
    table            = document.getElementById('mp-results-table');
    statusEl         = document.getElementById('mp-results-status');
    pager            = document.getElementById('mp-pager');
    tsvLink          = document.getElementById('mp-results-tsv');
    hits             = document.getElementById('mp-resource-hits');
    filterInput      = document.getElementById('mp-results-filter');
    filterCount      = document.getElementById('mp-filter-count');
    advAccordion     = document.getElementById('mp-adv');
    advCount         = document.getElementById('mp-advanced-count');
    advSubmit        = document.getElementById('mp-adv-submit');
    advReset         = document.getElementById('mp-adv-reset');
    emptyEl          = document.getElementById('mp-empty');
    emptyReset       = document.getElementById('mp-empty-reset');
    viewCardsBtn     = document.getElementById('mp-view-cards');
    viewTableBtn     = document.getElementById('mp-view-table');

    index     = readJson('mp-search-index');
    resources = (index && index.resources) || [];
    chartRows = readJson('mp-chart-data') || [];

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        submit(true);
      });
    }

    if (termInput) {
      termInput.addEventListener('input', function () {
        updateClearBtn();
        renderResourceHits(this.value.trim());
      });
    }

    if (queryClear) {
      queryClear.addEventListener('click', function () {
        if (termInput) {
          termInput.value = '';
          termInput.focus();
        }
        updateClearBtn();
        state.term = '';
        renderResourceHits('');
        if (state.searched) {
          state.page = 1;
          run(false);
        }
      });
    }

    Array.prototype.forEach.call(document.querySelectorAll('.mp-example-btn'), function (btn) {
      btn.addEventListener('click', function () {
        var t = btn.getAttribute('data-term') || '';
        if (termInput) {
          termInput.value = t;
        }
        submit(true);
      });
    });

    if (assembly) {
      assembly.addEventListener('change', function () {
        syncAdvancedBadge();
        submit(true);
      });
    }

    if (pageSize) {
      pageSize.addEventListener('change', function () {
        state.pageSize = this.value;
        state.page = 1;
        run(false);
      });
    }

    if (filterInput) {
      filterInput.addEventListener('input', function () {
        state.filter = this.value.trim();
        applyResultsFilter();
      });
    }

    if (viewCardsBtn) {
      viewCardsBtn.addEventListener('click', function () {
        state.view = 'card';
        updateViewDisplay();
      });
    }

    if (viewTableBtn) {
      viewTableBtn.addEventListener('click', function () {
        state.view = 'table';
        updateViewDisplay();
      });
    }

    if (advSubmit) {
      advSubmit.addEventListener('click', function (e) {
        e.preventDefault();
        submit(true);
      });
    }

    if (advReset) {
      advReset.addEventListener('click', function () {
        if (assembly) assembly.value = '';
        syncAdvancedBadge();
        state.assembly = '';
        state.page = 1;
        if (state.searched) {
          run(false);
        }
      });
    }

    if (emptyReset) {
      emptyReset.addEventListener('click', function () {
        if (termInput) termInput.value = '';
        if (assembly) assembly.value = '';
        if (filterInput) filterInput.value = '';
        state.term = '';
        state.assembly = '';
        state.filter = '';
        state.page = 1;
        updateClearBtn();
        syncAdvancedBadge();
        submit(false);
      });
    }

    initSortButtons();
    fillAssemblyTable();
    drawChart();

    if (window.MGDB && MGDB.sectionTabs) {
      MGDB.sectionTabs({ watch: '#mp-results-section' });
    }

    var initial = new URLSearchParams(window.location.search).get('term');
    if (initial && termInput) {
      termInput.value = initial;
      submit(false);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
