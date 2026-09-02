(function () {
  'use strict';

  function byId(id) { return document.getElementById(id); }

  function esc(str) {
    if (!str && str !== 0) { return ''; }
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function number(n) { return Number(n || 0).toLocaleString(); }

  function readJson(id) {
    var el = byId(id);
    if (!el) { return null; }
    try { return JSON.parse(el.textContent || 'null'); }
    catch (error) { return null; }
  }

  function updateAddress(query) {
    if (!window.history || !window.history.replaceState) return;
    var url = new URL(window.location.href);
    if (query) url.searchParams.set('q', query);
    else url.searchParams.delete('q');
    window.history.replaceState({}, '', url.pathname + url.search + url.hash);
  }

  function executeEmbeddedScript(responseText) {
    var scripts = responseText.match(/<script[^>]*>[\s\S]*?<\/script>/gi) || [];
    scripts.forEach(function (script) {
      var code = script.replace(/^<script[^>]*>/i, '').replace(/<\/script>$/i, '');
      if (code.trim()) window.eval(code);
    });
  }

  function runSearch(form) {
    var input = form.querySelector('input[name="q"]');
    var results = document.getElementById('est-search-results');
    var status = document.getElementById('est-search-status');
    var limitInput = document.getElementById('limit_val');
    var query = input.value.trim();
    var maximum = parseInt(limitInput.max, 10);
    var limit = parseInt(limitInput.value, 10);

    if (!query) {
      status.textContent = 'Enter an EST identifier or search pattern before searching.';
      input.focus();
      return;
    }

    if (!Number.isFinite(limit) || limit < 1) limit = 1;
    if (Number.isFinite(maximum) && limit > maximum) limit = maximum;
    limitInput.value = limit;

    status.textContent = 'Searching MaizeGDB expressed sequence tag records.';
    results.setAttribute('aria-busy', 'true');
    results.innerHTML = '<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span><span>Searching EST records&hellip;</span></div>';
    updateAddress(query);

    window.main_div_name = results.id;
    window.jQuery.post('/search/est/est_results.php', {
      term: encodeURI(query),
      search_limit: limit,
      div_name: results.id
    }).done(function (data, textStatus, xhr) {
      var response = xhr.responseText || data || '';
      var redirect = response.match(/^\s*javascript:document\.location\s*=\s*['"]([^'"]+)['"]/i);

      if (redirect) {
        window.location.assign(redirect[1]);
        return;
      }

      results.innerHTML = response;
      results.setAttribute('aria-busy', 'false');
      status.textContent = /no records matching|no matches/i.test(response)
        ? 'No matching EST records were found.'
        : 'Search complete. Matching EST records are shown below.';
      executeEmbeddedScript(response);
      results.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        block: 'nearest'
      });
    }).fail(function () {
      results.setAttribute('aria-busy', 'false');
      results.innerHTML = '<div class="mgdb-message mgdb-message-error" role="alert"><div><strong>Search unavailable.</strong> The EST collection could not be queried. Please try again.</div></div>';
      status.textContent = 'The EST search could not be completed.';
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('est-search-form');
    if (!form) return;

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      runSearch(form);
    });

    form.addEventListener('reset', function () {
      window.setTimeout(function () {
        var results = document.getElementById('est-search-results');
        results.innerHTML = '';
        results.removeAttribute('aria-busy');
        document.getElementById('est-search-status').textContent = '';
        updateAddress('');
      }, 0);
    });

    form.querySelectorAll('[data-est-example]').forEach(function (button) {
      button.addEventListener('click', function () {
        form.querySelector('input[name="q"]').value = button.getAttribute('data-est-example');
        runSearch(form);
      });
    });

    var query = new URLSearchParams(window.location.search).get('q');
    if (query) {
      form.querySelector('input[name="q"]').value = query;
      runSearch(form);
    }
  });

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

        update();
  }
  /* ── Mapped ESTs by chromosome figure ───────────────────────────────────────────── */

  /* The census is rendered server side into #est-chart-data by the same GROUP
     BY that fills the metric cards, so this draws without a request of its
     own. */
  function initFigure() {
    var data = readJson('est-chart-data');
    if (!data || !data.bars || !data.bars.length) { return; }

    var bars = data.bars;

    var table = byId('est-chr-table');
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
       the largest type at the top, matching the table. */
    var ordered = bars.slice().reverse();
    var el = byId('est-chr-chart');
    if (!el) { return; }
    var height = Math.max(320, ordered.length * 40 + 110);
    el.style.height = height + 'px';

    /* Margins are sized from the figure, not fixed. Chromosome labels are short --
       "Chromosome 10" is 13 characters -- so the desktop gutter is
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
        marker: { color: '#285d46' },
        /* A non-breaking space: SVG collapses a plain leading one, leaving the
           label flush against the end of its bar. */
        text: ordered.map(function (b) { return '\u00A0' + number(b.count); }),
        textposition: m.narrow ? 'none' : 'outside',
        cliponaxis: false,
        hovertemplate: '%{customdata}<br>%{x:,} mapped ESTs<extra></extra>'
      }],
      layout: {
        height: height,
        showlegend: false,
        margin: m.margin,
        xaxis: { title: 'Mapped ESTs', zeroline: false, tickformat: ',d', nticks: m.nticks },
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

  }

  document.addEventListener('DOMContentLoaded', function () {
    buildTabs();
    initFigure();
  });
})();
