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
  var form, termInput, assembly, pageSize, results, table, scope, pager, tsvLink, hits;
  var index = null, resources = [], chartRows = [];

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
   * Pathway search
   * ------------------------------------------------------------------ */

  var state = { term: '', assembly: '', page: 1, pageSize: 25, total: 0, pages: 0 };

  function query(extra) {
    var p = new URLSearchParams();
    if (state.term) { p.set('term', state.term); }
    if (state.assembly) { p.set('assembly', state.assembly); }
    p.set('page', String(state.page));
    p.set('page_size', String(state.pageSize));
    if (extra) { Object.keys(extra).forEach(function (k) { p.set(k, extra[k]); }); }
    return p.toString();
  }

  function renderRows(rows) {
    var body = table.tBodies[0];
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="5" class="mgdb-empty">No pathway matches that term.</td></tr>';
      return;
    }
    var html = '';
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      html += '<tr>'
            + '<th scope="row"><a href="' + esc(r.url) + '" target="_blank" rel="noopener">'
            + richName(r.name_html || r.name) + ' <span aria-hidden="true">&nearr;</span></a></th>'
            + '<td class="mgdb-sequence"><a href="' + esc(r.metacyc_url) + '" target="_blank" rel="noopener">'
            + esc(r.id) + ' <span aria-hidden="true">&nearr;</span></a></td>'
            + '<td>' + esc((r.assemblies || []).join(', ')) + '</td>'
            + '<td class="mgdb-numeric">' + num(r.gene_models) + '</td>'
            + '<td class="mgdb-numeric">' + num(r.proteins) + '</td>'
            + '</tr>';
    }
    body.innerHTML = html;
  }

  /* What the reader searched by, said plainly. A gene model that returns four
     pathways otherwise looks like a name search that went strange. */
  var MATCHED = {
    pathway_id:          'matching that CornCyc pathway ID',
    pathway_id_and_name: 'matching that CornCyc pathway ID or name',
    gene_model:          'assigned to that gene model',
    pathway_name:        'whose name matches that term'
  };

  function renderScope(summary) {
    if (!summary) { scope.textContent = ''; return; }
    var what = MATCHED[summary.matched_by] || 'in the collection';
    var first = summary.total === 0 ? 0 : (summary.page - 1) * summary.page_size + 1;
    var last = Math.min(summary.total, summary.page * summary.page_size);
    scope.textContent = summary.total === 0
      ? 'No pathways ' + what + '.'
      : num(first) + '–' + num(last) + ' of ' + num(summary.total) + ' pathways ' + what + '.';
  }

  function renderPager(summary) {
    if (!summary || summary.page_count <= 1) { pager.innerHTML = ''; return; }
    pager.innerHTML =
        '<button type="button" data-step="-1"' + (summary.page <= 1 ? ' disabled' : '') + '>Previous</button>'
      + '<span class="mp-pager-status">Page ' + num(summary.page) + ' of ' + num(summary.page_count) + '</span>'
      + '<button type="button" data-step="1"' + (summary.page >= summary.page_count ? ' disabled' : '') + '>Next</button>';
  }

  function run() {
    results.hidden = false;
    results.setAttribute('aria-busy', 'true');

    var url = ENDPOINT + '?' + query();
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        results.setAttribute('aria-busy', 'false');
        if (!data || !data.ok) {
          table.tBodies[0].innerHTML =
            '<tr><td colspan="5" class="mgdb-empty">' + esc((data && data.message) || 'The search could not be completed.') + '</td></tr>';
          scope.textContent = '';
          pager.innerHTML = '';
          return;
        }
        state.total = data.summary.total;
        state.pages = data.summary.page_count;
        renderRows(data.results || []);
        renderScope(data.summary);
        renderPager(data.summary);
        /* The export carries the current filters and no pagination, so the
           file is the whole matched set rather than the page on screen. */
        tsvLink.href = ENDPOINT + '?' + query({ format: 'tsv' });
        if (window.MGDB && MGDB.announce) {
          MGDB.announce(scope.textContent);
        }
      })
      .catch(function () {
        results.setAttribute('aria-busy', 'false');
        table.tBodies[0].innerHTML =
          '<tr><td colspan="5" class="mgdb-empty">The search could not be reached.</td></tr>';
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
   * Wiring
   * ------------------------------------------------------------------ */

  function submit() {
    /* Read from the DOM, not from cached state: the browser can restore an
       input itself (autofill, bfcache) without firing `change`, and the form
       would then show a filter the query omits. */
    state.term = termInput ? termInput.value.trim() : '';
    state.assembly = assembly ? assembly.value : '';
    state.pageSize = pageSize ? parseInt(pageSize.value, 10) || 25 : 25;
    state.page = 1;
    renderResourceHits(state.term);
    run();
  }

  function init() {
    form      = document.getElementById('mp-search-form');
    termInput = document.getElementById('mp-term');
    assembly  = document.getElementById('mp-assembly');
    pageSize  = document.getElementById('mp-page-size');
    results   = document.getElementById('mp-results');
    table     = document.getElementById('mp-results-table');
    scope     = document.getElementById('mp-results-scope');
    pager     = document.getElementById('mp-pager');
    tsvLink   = document.getElementById('mp-results-tsv');
    hits      = document.getElementById('mp-resource-hits');

    index     = readJson('mp-search-index');
    resources = (index && index.resources) || [];
    chartRows = readJson('mp-chart-data') || [];

    if (form) {
      form.addEventListener('submit', function (e) { e.preventDefault(); submit(); });
    }

    Array.prototype.forEach.call(document.querySelectorAll('.mp-example'), function (btn) {
      btn.addEventListener('click', function () {
        if (termInput) { termInput.value = btn.getAttribute('data-term') || ''; }
        submit();
      });
    });

    if (pager) {
      pager.addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-step]');
        if (!btn || btn.disabled) { return; }
        state.page = Math.min(Math.max(1, state.page + parseInt(btn.getAttribute('data-step'), 10)), state.pages || 1);
        run();
        results.scrollIntoView({ behavior: 'auto', block: 'start' });
      });
    }

    if (assembly) { assembly.addEventListener('change', submit); }
    if (pageSize) { pageSize.addEventListener('change', submit); }

    fillAssemblyTable();
    drawChart();

    /* The sticky section tabs. Shared, not a private copy: the bar is styled
       by the shell and every page used to carry its own spy, which is how
       eleven of them shipped without one. `watch` is the results section --
       unhiding it moves every section below. */
    if (window.MGDB && MGDB.sectionTabs) {
      MGDB.sectionTabs({ watch: '#mp-results' });
    }

    /* Sorting needs no call here: mgdb-modern.js wires every
       table[data-sortable] on load, and re-reads the rows on each click, so the
       results table stays sortable after the search replaces its body. */

    /* A term in the URL runs the search on load, so a result page is linkable. */
    var initial = new URLSearchParams(window.location.search).get('term');
    if (initial && termInput) {
      termInput.value = initial;
      submit();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
