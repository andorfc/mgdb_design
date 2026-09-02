/* QTL Data Hub JavaScript (/data_center/qtl)
 * Handles search submissions, dropdown filters, live results rendering,
 * example chips, trait shortcuts, and scrollspy navigation tabs.
 */

(function () {
  'use strict';

  var API_URL = '/search/qtl/qtl_search_api.php';
  /* The endpoint caps page_size at QTL_MAX_RESULTS (200), so "All results"
     asks for that. */
  var MAX_PAGE = 200;
  var lastQuery = '';

  var state = {
    page: 1,
    pageSize: 25,
    filter: '',
    searched: false,
    lastData: null
  };

  function readJson(id) {
    var el = byId(id);
    if (!el) { return null; }
    try { return JSON.parse(el.textContent || 'null'); }
    catch (error) { return null; }
  }

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

  /* ── Search Form & AJAX ─────────────────────────────────────────────────── */

  function initSearchForm() {
    var form = byId('qtl-search-form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      state.page = 1;
      runSearch();
    });

    var resetBtn = byId('qtl-reset-btn');
    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        setTimeout(function () {
          runSearch();
        }, 10);
      });
    }

    // Auto-search on dropdown filter changes
    var selects = form.querySelectorAll('select');
    selects.forEach(function (sel) {
      sel.addEventListener('change', function () {
        runSearch();
      });
    });
  }

  function initExamples() {
    var exampleBtns = document.querySelectorAll('.qtl-example-btn');
    exampleBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var termInput = byId('qtl-search-term');
        if (termInput) {
          termInput.value = btn.dataset.term || '';
          runSearch();
          termInput.focus();
        }
      });
    });

    var traitShortcuts = document.querySelectorAll('.qtl-trait-shortcut');
    traitShortcuts.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var termInput = byId('qtl-search-term');
        if (termInput) {
          termInput.value = btn.dataset.term || '';
          runSearch();
          var searchSection = byId('qtl-search-panel');
          if (searchSection) {
            searchSection.scrollIntoView({ behavior: 'smooth' });
          }
        }
      });
    });

    var parentShortcuts = document.querySelectorAll('.qtl-parent-shortcut');
    parentShortcuts.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var termInput = byId('qtl-search-term');
        if (termInput) {
          termInput.value = btn.dataset.term || '';
          runSearch();
          var searchSection = byId('qtl-search-panel');
          if (searchSection) {
            searchSection.scrollIntoView({ behavior: 'smooth' });
          }
        }
      });
    });
  }

  function buildParams() {
    var params = new URLSearchParams();
    var term = (byId('qtl-search-term') && byId('qtl-search-term').value.trim()) || '';
    var trait = (byId('qtl-filter-trait') && byId('qtl-filter-trait').value) || '';
    var parent = (byId('qtl-filter-parent') && byId('qtl-filter-parent').value) || '';

    if (term) params.set('term', term);
    if (trait) params.set('trait', trait);
    if (parent) params.set('parent', parent);

    return params;
  }

  /* The export is the whole matched set, so it carries the filters but never
     the page. */
  function exportQuery(params) {
    var out = new URLSearchParams(params.toString());
    out.delete('page');
    out.delete('page_size');
    return out.toString();
  }

  function runSearch() {
    var params = buildParams();
    params.set('page', state.page);
    params.set('page_size', state.pageSize === 'all' ? MAX_PAGE : state.pageSize);

    /* The results section is hidden until there is something to show. */
    var section = byId('qtl-results-section');
    if (section) { section.hidden = false; }
    state.searched = true;

    var statusEl = byId('qtl-results-status');
    var notesEl = byId('qtl-notes');
    var resultsEl = byId('qtl-results');
    var emptyEl = byId('qtl-empty');
    var exportLink = byId('qtl-export-tsv');

    notesEl.innerHTML = '';
    emptyEl.hidden = true;
    exportLink.hidden = true;

    resultsEl.innerHTML = '<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span>Searching QTL analyses&hellip;</div>';
    statusEl.textContent = 'Searching…';

    lastQuery = params.toString();

    fetch(API_URL + '?' + lastQuery)
      .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
      .then(function (wrap) {
        if (!wrap.ok || !wrap.data.ok) {
          var msg = (wrap.data && (wrap.data.message || wrap.data.detail)) || 'The search could not be completed.';
          resultsEl.innerHTML = '';
          notesEl.innerHTML = '<div class="mgdb-message mgdb-message-error" role="alert">' + esc(msg) + '</div>';
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
    var statusEl = byId('qtl-results-status');
    var resultsEl = byId('qtl-results');
    var emptyEl = byId('qtl-empty');
    var exportLink = byId('qtl-export-tsv');

    var total = data.summary && data.summary.total ? data.summary.total : 0;
    var returned = data.summary && data.summary.returned ? data.summary.returned : 0;
    var elapsed = data.summary && data.summary.elapsed_ms != null ? data.summary.elapsed_ms : null;

    if (!total || !data.results || !data.results.length) {
      resultsEl.innerHTML = '';
      emptyEl.hidden = false;
      statusEl.textContent = 'No QTL analyses matched your query.';
      return;
    }

    emptyEl.hidden = true;

    var summary = data.summary || {};
    var size = summary.page_size || returned;
    var page = summary.page || 1;
    var start = (page - 1) * size + 1;
    var end = start + returned - 1;
    var timing = elapsed != null ? ' (' + number(elapsed) + ' ms)' : '';

    if (state.pageSize === 'all' && total > returned) {
      /* "All results" is capped by the endpoint, and saying so is better than
         a count that quietly stops short. */
      statusEl.textContent = 'Showing the first ' + number(returned) + ' of ' + number(total)
        + ' QTL analyses, which is as many as the search returns at once.' + timing;
    } else {
      statusEl.textContent = 'Showing ' + number(start) + '–' + number(end) + ' of '
        + number(total) + ' QTL analys' + (total === 1 ? 'is' : 'es') + '.' + timing;
    }

    resultsEl.innerHTML = buildTableHtml(data.results);
    exportLink.href = API_URL + '?' + exportQuery(buildParams()) + '&format=tsv';
    exportLink.hidden = false;

    renderPagination(page, summary.page_count || 0);

    state.lastData = data;
    /* Re-applied last so paging does not silently drop a filter the box still
       shows. */
    applyResultsFilter();
  }

  function renderPagination(page, pageCount) {
    var nav = byId('qtl-pagination');
    if (!nav) { return; }
    if (state.pageSize === 'all' || pageCount <= 1) { nav.innerHTML = ''; return; }

    var pages = [];
    var start = Math.max(1, page - 2);
    var end = Math.min(pageCount, page + 2);

    pages.push('<button type="button" data-page="' + Math.max(1, page - 1) + '"'
      + (page === 1 ? ' disabled' : '') + '>Previous</button>');
    if (start > 1) { pages.push('<button type="button" data-page="1">1</button>'); }
    if (start > 2) { pages.push('<button type="button" disabled>&hellip;</button>'); }
    for (var i = start; i <= end; i += 1) {
      pages.push('<button type="button" data-page="' + i + '"'
        + (i === page ? ' class="is-active" aria-current="page"' : '') + '>' + i + '</button>');
    }
    if (end < pageCount - 1) { pages.push('<button type="button" disabled>&hellip;</button>'); }
    if (end < pageCount) { pages.push('<button type="button" data-page="' + pageCount + '">' + pageCount + '</button>'); }
    pages.push('<button type="button" data-page="' + Math.min(pageCount, page + 1) + '"'
      + (page === pageCount ? ' disabled' : '') + '>Next</button>');

    nav.innerHTML = pages.join('');
    Array.prototype.forEach.call(nav.querySelectorAll('button[data-page]'), function (btn) {
      btn.addEventListener('click', function () {
        state.page = parseInt(btn.getAttribute('data-page'), 10) || 1;
        runSearch();
        var section = byId('qtl-results-section');
        if (section && section.scrollIntoView) { section.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
      });
    });
  }

  /* Narrows the page already rendered. The search pages server side, so this
     filters what is on screen and the status line says so. */
  function applyResultsFilter() {
    var container = byId('qtl-results');
    if (!container) { return; }

    var rows = container.querySelectorAll('tbody tr');
    var terms = state.filter.toLowerCase().split(/\s+/).filter(Boolean);
    var shown = 0;

    Array.prototype.forEach.call(rows, function (row) {
      var match = true;
      if (terms.length) {
        var hay = (row.textContent || '').toLowerCase();
        for (var i = 0; i < terms.length; i++) {
          if (hay.indexOf(terms[i]) === -1) { match = false; break; }
        }
      }
      row.hidden = !match;
      if (match) { shown++; }
    });

    if (terms.length) {
      var statusEl = byId('qtl-results-status');
      var total = state.lastData && state.lastData.summary ? state.lastData.summary.total : 0;
      if (statusEl) {
        statusEl.textContent = shown === 0
          ? 'Nothing on this page matches the filter “' + state.filter + '”. '
            + number(total) + ' QTL analyses matched the search.'
          : 'Showing ' + number(shown) + ' of the ' + number(rows.length)
            + ' analyses on this page matching “' + state.filter + '”, out of '
            + number(total) + ' matched by the search.';
      }
    }
  }

  function buildTableHtml(results) {
    var rows = results.map(function (item) {
      return '<tr>'
        + buildNameCell(item)
        + buildTraitCell(item)
        + buildParentsCell(item)
        + buildExpCell(item)
        + buildDesignCell(item)
        + '</tr>';
    }).join('');

    return '<div class="mgdb-table-scroll" tabindex="0">'
      + '<table class="mgdb-table qtl-table">'
      + '<caption>Matching QTL analyses<span class="mgdb-muted">' + number(results.length) + ' shown</span></caption>'
      + '<thead><tr>'
      + '<th scope="col">Analysis Symbol</th>'
      + '<th scope="col">Trait Evaluated</th>'
      + '<th scope="col">Mapping Parents</th>'
      + '<th scope="col">Experiment Study</th>'
      + '<th scope="col">Design &amp; Detections</th>'
      + '</tr></thead>'
      + '<tbody>' + rows + '</tbody>'
      + '</table></div>';
  }

  function buildNameCell(item) {
    var name = item.name ? esc(item.name) : '(unnamed)';
    var link = item.url ? '<a href="' + esc(item.url) + '"><strong>' + name + '</strong></a>' : '<strong>' + name + '</strong>';
    return '<td scope="row" class="qtl-name-cell">' + link + '</td>';
  }

  function buildTraitCell(item) {
    var trait = item.trait_name ? esc(item.trait_name) : 'Unspecified';
    return '<td><span class="mgdb-pill mgdb-pill-ok">' + trait + '</span></td>';
  }

  function buildParentsCell(item) {
    var parents = item.parents || [];
    if (!parents.length) {
      return '<td class="mgdb-muted">&mdash;</td>';
    }
    var cross = parents.map(esc).join(' &times; ');
    return '<td><span class="qtl-parents-badge">' + cross + '</span></td>';
  }

  function buildExpCell(item) {
    if (!item.experiment_name) {
      return '<td class="mgdb-muted">&mdash;</td>';
    }
    var link = item.exp_id ? '<a href="/data_center/qtl_exp?id=' + item.exp_id + '">' + esc(item.experiment_name) + '</a>' : esc(item.experiment_name);
    return '<td>' + link + '</td>';
  }

  function buildDesignCell(item) {
    var parts = [];
    if (item.qtl_count > 0) {
      parts.push('<strong>' + number(item.qtl_count) + ' QTL loci mapped</strong>');
    }
    if (item.method) {
      var shortMethod = item.method.length > 80 ? item.method.slice(0, 80) + '…' : item.method;
      parts.push('<span class="qtl-desc">' + esc(shortMethod) + '</span>');
    }
    if (!parts.length) {
      return '<td class="mgdb-muted">&mdash;</td>';
    }
    return '<td><ul class="qtl-attr-list"><li>' + parts.join('<br>') + '</li></ul></td>';
  }

  /* ── Section Navigation Tabs & Scrollspy ────────────────────────────────── */

  /* Sticky section tabs, driven by scroll, IntersectionObserver and resize
     together: no single trigger fires everywhere, and the results section
     appears and disappears under the bar as searches run. */
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

    var results = byId('qtl-results-section');
    if (results && window.MutationObserver) {
      new window.MutationObserver(update).observe(results, {
        childList: true, subtree: true, attributes: true, attributeFilter: ['hidden']
      });
    }

    update();
  }


  /* ── Analyses by trait figure ───────────────────────────────────────────── */

  /* The census is rendered server side into #qtl-chart-data by the same GROUP
     BY that fills the trait filter, so this draws without a request of its
     own. */
  function initFigure() {
    var data = readJson('qtl-chart-data');
    if (!data || !data.bars || !data.bars.length) { return; }

    var bars = data.bars;

    var table = byId('qtl-trait-table');
    if (table) {
      var body = table.querySelector('tbody');
      if (body) {
        body.innerHTML = bars.map(function (bar) {
          return '<tr><td>' + esc(bar.label) + '</td>'
               + '<td class="mgdb-numeric">' + number(bar.count) + '</td></tr>';
        }).join('');
      }
    }

    if (!window.MGDB || !window.MGDB.chart) { return; }

    /* Plotly stacks horizontal bars bottom-up, so the array is reversed to put
       the most studied trait at the top, matching the table. */
    var ordered = bars.slice().reverse();
    var el = byId('qtl-trait-chart');
    if (!el) { return; }
    var height = Math.max(320, ordered.length * 40 + 110);
    el.style.height = height + 'px';

    /* Margins are sized from the figure, not fixed. Trait names run long --
       "anthesis-silking interval" is 25 characters -- so the desktop gutter is
       generous and the narrow one shortens the tick text instead of growing,
       which would leave no plot at all on a phone. */
    var NARROW = 560;
    var fullLabels = ordered.map(function (b) { return b.label; });
    var shortLabels = fullLabels.map(function (l) {
      return l.length > 16 ? l.slice(0, 15).replace(/[\s-]+$/, '') + '…' : l;
    });
    function metrics() {
      var width = el.getBoundingClientRect().width;
      var narrow = width > 0 && width < NARROW;
      return {
        narrow: narrow,
        margin: narrow ? { l: 104, r: 16, t: 8, b: 44 } : { l: 190, r: 64, t: 8, b: 44 },
        nticks: narrow ? 3 : 0,
        labels: narrow ? shortLabels : fullLabels
      };
    }
    var m = metrics();

    window.MGDB.chart({
      target: el,
      traces: [{
        type: 'bar',
        orientation: 'h',
        y: m.labels,
        x: ordered.map(function (b) { return b.count; }),
        customdata: fullLabels,
        /* The rolled-up tail is not a trait you can search, so it is drawn in a
           muted tone to read as a summary rather than another category. */
        marker: {
          color: ordered.map(function (b) { return b.id ? '#285d46' : '#9aa8a0'; })
        },
        /* A non-breaking space: SVG collapses a plain leading one, leaving the
           label flush against the end of its bar. */
        text: ordered.map(function (b) { return '\u00A0' + number(b.count); }),
        textposition: m.narrow ? 'none' : 'outside',
        cliponaxis: false,
        hovertemplate: '%{customdata}<br>%{x:,} analyses<extra></extra>'
      }],
      layout: {
        height: height,
        showlegend: false,
        margin: m.margin,
        xaxis: { title: 'Analyses', zeroline: false, tickformat: ',d', nticks: m.nticks },
        yaxis: { automargin: true }
      }
    });

    /* MGDB.chart re-runs Plotly.Plots.resize on a window resize, which rescales
       the figure but keeps the margins it was drawn with. Crossing the
       breakpoint has to relayout. */
    if (window.Plotly && window.Plotly.relayout) {
      var lastNarrow = m.narrow;
      var timer = null;
      window.addEventListener('resize', function () {
        if (timer) { window.clearTimeout(timer); }
        timer = window.setTimeout(function () {
          var next = metrics();
          if (next.narrow === lastNarrow) { return; }
          lastNarrow = next.narrow;
          window.Plotly.relayout(el, { margin: next.margin, 'xaxis.nticks': next.nticks });
          window.Plotly.restyle(el, {
            textposition: next.narrow ? 'none' : 'outside',
            y: [next.labels]
          });
        }, 180);
      });
    }

    if (!window.MutationObserver) { return; }

    /* Selecting a bar searches that trait. Plotly only gains its event emitter
       once it has drawn, so wait for the draw rather than guessing a delay. */
    var attached = false;
    var observer = new window.MutationObserver(function () {
      if (attached || typeof el.on !== 'function') { return; }
      attached = true;
      observer.disconnect();
      el.on('plotly_click', function (event) {
        if (!event || !event.points || !event.points.length) { return; }
        var match = ordered[event.points[0].pointIndex];
        if (!match || !match.id) { return; }

        var traitSelect = byId('qtl-filter-trait');
        if (traitSelect) { traitSelect.value = String(match.id); }
        var termInput = byId('qtl-search-term');
        if (termInput) { termInput.value = ''; }
        var adv = document.querySelector('.qtl-adv');
        if (adv) { adv.open = true; }
        state.page = 1;
        runSearch();
        var section = byId('qtl-results-section');
        if (section && section.scrollIntoView) { section.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
      });
    });
    observer.observe(el, { childList: true, subtree: true });
  }

  function initResultControls() {
    var sizeSelect = byId('qtl-page-size');
    if (sizeSelect) {
      sizeSelect.addEventListener('change', function () {
        state.pageSize = this.value === 'all' ? 'all' : parseInt(this.value, 10) || 25;
        state.page = 1;
        if (state.searched) { runSearch(); }
      });
    }

    var filterInput = byId('qtl-results-filter');
    if (filterInput) {
      filterInput.addEventListener('input', function () {
        state.filter = this.value.trim();
        if (state.filter === '' && state.lastData) {
          /* Re-render rather than un-hiding: the status line has to go back to
             what the search said, not what the filter said. */
          renderResults(state.lastData);
          return;
        }
        applyResultsFilter();
      });
    }

    var advReset = byId('qtl-adv-reset');
    if (advReset) {
      advReset.addEventListener('click', function () {
        var trait = byId('qtl-filter-trait');
        var parent = byId('qtl-filter-parent');
        if (trait) { trait.value = ''; }
        if (parent) { parent.value = ''; }
        state.page = 1;
        if (state.searched) { runSearch(); }
      });
    }
  }

  function init() {
    initSectionTabs();
    initSearchForm();
    initExamples();
    initResultControls();
    initFigure();

    // Check URL parameters
    var urlParams = new URLSearchParams(window.location.search);
    var hasQuery = false;
    if (urlParams.has('term') || urlParams.has('qtl_term')) {
      var termVal = urlParams.get('term') || urlParams.get('qtl_term');
      var termInput = byId('qtl-search-term');
      if (termInput && termVal) {
        termInput.value = termVal;
        hasQuery = true;
      }
    }
    if (urlParams.has('trait')) {
      var traitSelect = byId('qtl-filter-trait');
      if (traitSelect) {
        traitSelect.value = urlParams.get('trait');
        hasQuery = true;
      }
    }

    if (hasQuery) {
      runSearch();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
