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

  /* ── Search ─────────────────────────────────────────────────────────────── */

  function updateAddress(query) {
    if (!window.history || !window.history.replaceState) { return; }
    var url = new URL(window.location.href);
    if (query) { url.searchParams.set('q', query); }
    else { url.searchParams.delete('q'); }
    window.history.replaceState({}, '', url.pathname + url.search + url.hash);
  }

  function executeEmbeddedScript(responseText) {
    var scripts = responseText.match(/<script[^>]*>[\s\S]*?<\/script>/gi) || [];
    scripts.forEach(function (script) {
      var code = script.replace(/^<script[^>]*>/i, '').replace(/<\/script>$/i, '');
      if (code.trim()) { window.eval(code); }
    });
  }

  function runSearch(form) {
    var input = form.querySelector('input[name="q"]');
    var results = byId('ssr-search-results');
    var status = byId('ssr-search-status');
    var limitInput = byId('limit_val');
    var query = input.value.trim();

    if (!query) {
      if (status) { status.textContent = 'Enter an SSR name, synonym, or repeat motif before searching.'; }
      input.focus();
      return;
    }

    /* The endpoint clamps the limit itself, but clamping here too keeps the
       field showing the number the request actually used. */
    var maximum = parseInt(limitInput.max, 10);
    var limit = parseInt(limitInput.value, 10);
    if (!isFinite(limit) || limit < 1) { limit = 1; }
    if (isFinite(maximum) && limit > maximum) { limit = maximum; }
    limitInput.value = limit;

    if (status) { status.textContent = 'Searching the archived SSR collection.'; }
    results.setAttribute('aria-busy', 'true');
    results.innerHTML = '<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span><span>Searching archived SSR records&hellip;</span></div>';
    updateAddress(query);

    window.main_div_name = results.id;
    /* The term goes over raw. jQuery.post form-encodes the data object, so an
       encodeURI() here would be a second encoding. This endpoint happens to
       urldecode what it receives, which hid the double encoding -- the two
       Overgo endpoints do not, and there the same line silently emptied every
       anchored and wildcard search. Sending it raw is correct against both. */
    window.jQuery.post('/search/ssr/ssr_results.php', {
      term: query,
      search_limit: limit,
      div_name: results.id
    }).done(function (data, textStatus, xhr) {
      var response = xhr.responseText || data || '';
      /* A search that matches exactly one record answers with a redirect
         instruction rather than a results table, and the reply has a leading
         space before `javascript:`. */
      var redirect = response.match(/^\s*javascript:(?:parent\.)?(?:document|location)\.?\w*\s*=\s*['"]([^'"]+)['"]/i);
      if (redirect) {
        window.location.assign(redirect[1]);
        return;
      }

      results.innerHTML = response;
      results.setAttribute('aria-busy', 'false');
      if (status) {
        status.textContent = /no records matching|no matches|there are no/i.test(response)
          ? 'No matching archived SSR records were found.'
          : 'Search complete. Matching archived SSR records are shown below.';
      }
      executeEmbeddedScript(response);
      results.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        block: 'nearest'
      });
    }).fail(function () {
      results.setAttribute('aria-busy', 'false');
      results.innerHTML = '<div class="mgdb-message mgdb-message-error" role="alert"><div><strong>Search unavailable.</strong> The archived SSR collection could not be queried. Please try again.</div></div>';
      if (status) { status.textContent = 'The SSR search could not be completed.'; }
    });
  }

  function initSearch() {
    var form = byId('ssr-search-form');
    if (!form) { return; }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      runSearch(form);
    });

    form.addEventListener('reset', function () {
      window.setTimeout(function () {
        var results = byId('ssr-search-results');
        var status = byId('ssr-search-status');
        if (results) {
          results.innerHTML = '';
          results.removeAttribute('aria-busy');
        }
        if (status) { status.textContent = ''; }
        updateAddress('');
      }, 0);
    });

    Array.prototype.forEach.call(form.querySelectorAll('[data-ssr-example]'), function (button) {
      button.addEventListener('click', function () {
        form.querySelector('input[name="q"]').value = button.getAttribute('data-ssr-example');
        runSearch(form);
      });
    });

    var query = new URLSearchParams(window.location.search).get('q');
    if (query) {
      form.querySelector('input[name="q"]').value = query;
      runSearch(form);
    }
  }

  /* ── Section tabs ───────────────────────────────────────────────────────── */

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

  /* ── Repeat motifs by unit length figure ────────────────────────────────── */

  /* The census is rendered server side into #ssr-chart-data by the same GROUP
     BY that fills the metric cards, so this draws without a request of its
     own. */
  function initFigure() {
    var data = readJson('ssr-chart-data');
    if (!data || !data.bars || !data.bars.length) { return; }

    var bars = data.bars;

    var table = byId('ssr-unit-table');
    if (table) {
      var body = table.querySelector('tbody');
      if (body) {
        body.innerHTML = bars.map(function (bar) {
          return '<tr><td>' + esc(bar.label) + '</td>'
               + '<td>' + esc(bar.unit) + '</td>'
               + '<td class="mgdb-numeric">' + number(bar.count) + '</td></tr>';
        }).join('');
      }
    }

    if (!window.MGDB || !window.MGDB.chart) { return; }

    /* Plotly stacks horizontal bars bottom-up, so the array is reversed to put
       the largest group at the top, matching the table. */
    var ordered = bars.slice().reverse();
    var el = byId('ssr-unit-chart');
    if (!el) { return; }
    var height = Math.max(280, ordered.length * 42 + 110);
    el.style.height = height + 'px';

    /* Margins are sized from the figure, not fixed. MGDB.chart re-runs
       Plotly.Plots.resize on a window resize, which rescales the figure but
       keeps the margins it was drawn with, so a desktop gutter would survive
       onto a phone and squeeze the plot to nothing. "Tetranucleotide" is 15
       characters, so the narrow layout shortens the tick text rather than
       growing the gutter. */
    var NARROW = 560;
    var fullLabels = ordered.map(function (b) { return b.label; });
    /* The narrow labels come from the unit rather than from the name, because
       shortening the names leaves "Longer than six" untouched -- and it is the
       longest of them, so automargin gave it a 120px gutter out of a 259px
       figure and the plot had 129px left. "2 nt" and "7+ nt" carry the same
       meaning under a heading that already says "by unit length". */
    var shortLabels = ordered.map(function (b) {
      return String(b.unit).replace(' bp and up', '+ nt').replace(' bp', ' nt');
    });
    function metrics() {
      var width = el.getBoundingClientRect().width;
      var narrow = width > 0 && width < NARROW;
      return {
        narrow: narrow,
        margin: narrow ? { l: 74, r: 16, t: 8, b: 44 } : { l: 138, r: 72, t: 8, b: 44 },
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
        /* Keyed on the full labels always. Plotly pins a category axis's
           values on first draw, so restyling `y` with a shortened set adds
           new categories rather than renaming the existing ones, and the
           figure keeps whichever labels it was born with when the window
           crosses the breakpoint. The axis's ticktext is swapped instead. */
        y: fullLabels,
        x: ordered.map(function (b) { return b.count; }),
        customdata: ordered.map(function (b) { return b.unit; }),
        marker: { color: '#285d46' },
        /* A non-breaking space: SVG collapses a plain leading one, leaving the
           label flush against the end of its bar. */
        text: ordered.map(function (b) { return '\u00A0' + number(b.count); }),
        textposition: m.narrow ? 'none' : 'outside',
        cliponaxis: false,
        hovertemplate: '%{y}<br>Repeat unit %{customdata}<br>%{x:,} records<extra></extra>'
      }],
      layout: {
        height: height,
        showlegend: false,
        margin: m.margin,
        xaxis: { title: 'Records', zeroline: false, tickformat: ',d', nticks: m.nticks },
        yaxis: { automargin: true, tickmode: 'array', tickvals: fullLabels, ticktext: m.labels }
      }
    });

    if (window.Plotly && window.Plotly.relayout) {
      var lastNarrow = m.narrow;
      var timer = null;
      window.addEventListener('resize', function () {
        if (timer) { window.clearTimeout(timer); }
        timer = window.setTimeout(function () {
          var next = metrics();
          if (next.narrow === lastNarrow) { return; }
          lastNarrow = next.narrow;
          window.Plotly.relayout(el, {
            margin: next.margin,
            'xaxis.nticks': next.nticks,
            'yaxis.ticktext': next.labels
          });
          window.Plotly.restyle(el, { textposition: next.narrow ? 'none' : 'outside' });
        }, 180);
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    initSearch();
    buildTabs();
    initFigure();
  });
})();
