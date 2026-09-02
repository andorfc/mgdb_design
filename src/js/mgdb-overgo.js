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

  /* The two searches answer at different endpoints and take different input,
     but they are one search bar: the mode select decides which endpoint the
     form posts to and what the field is allowed to contain. */
  var MODES = {
    overgo: {
      label: 'Overgo probe name',
      placeholder: 'si486',
      submit: 'Search probes',
      hint: 'A name is matched anywhere unless you anchor it: <code>^</code> starts with, <code>$</code> ends with, <code>%</code> is a wildcard.',
      examples: 'overgo-name-examples'
    },
    overgo_seq: {
      label: 'Nucleotide sequence',
      placeholder: 'ACGTGC',
      submit: 'Search sequences',
      hint: 'Up to 25 bases of A, C, G or T. Matches are exact, in either orientation, including reverse complements. Spaces are removed before searching, and only the Unigene-Overgo collection carries searchable sequences.',
      examples: 'overgo-sequence-examples'
    }
  };

  function currentMode() {
    var select = byId('overgo-mode');
    var value = select ? select.value : 'overgo';
    return MODES[value] ? value : 'overgo';
  }

  function applyMode() {
    var mode = currentMode();
    var spec = MODES[mode];
    var panel = document.querySelector('.overgo-search-panel');
    var input = byId('overgo_term');
    var label = byId('overgo-query-label');
    var hint = byId('overgo-query-hint');
    var submit = document.querySelector('.overgo-search-submit');
    var error = byId('overgo-sequence-error');

    if (label) { label.textContent = spec.label; }
    if (hint) { hint.innerHTML = spec.hint; }
    if (submit) { submit.textContent = spec.submit; }
    if (input) {
      input.placeholder = spec.placeholder;
      input.removeAttribute('aria-invalid');
      /* maxlength only belongs on the sequence search; a name can be longer. */
      if (mode === 'overgo_seq') { input.setAttribute('maxlength', '25'); }
      else { input.removeAttribute('maxlength'); }
    }
    if (error) { error.hidden = true; }
    if (panel) { panel.classList.toggle('overgo-sequence-mode', mode === 'overgo_seq'); }

    Object.keys(MODES).forEach(function (key) {
      var block = byId(MODES[key].examples);
      if (block) { block.hidden = key !== mode; }
    });
  }

  function cleanSequence(value) {
    return value.replace(/\s+/g, '').toUpperCase();
  }

  function updateAddress(mode, query) {
    if (!window.history || !window.history.replaceState) { return; }
    var url = new URL(window.location.href);
    url.searchParams.set('mode', mode === 'overgo_seq' ? 'sequence' : 'name');
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

  function runSearch() {
    var form = byId('overgo-search-form');
    var input = byId('overgo_term');
    var results = byId('overgo-search-results');
    var status = byId('overgo-search-status');
    var error = byId('overgo-sequence-error');
    var mirror = byId('ovterm');
    if (!form || !input || !results) { return; }

    var mode = currentMode();
    var query = input.value.trim();

    if (mode === 'overgo_seq') {
      query = cleanSequence(query);
      input.value = query;
      var valid = /^[ACGT]{1,25}$/.test(query);
      input.setAttribute('aria-invalid', valid ? 'false' : 'true');
      if (error) { error.hidden = valid; }
      if (!valid) {
        if (status) { status.textContent = 'Sequence search needs 1 to 25 bases using A, C, G, or T.'; }
        input.focus();
        return;
      }
    } else if (!query) {
      input.focus();
      return;
    }

    /* Every legacy results page carries a script that calls back into
       getSearchData() for the next page, and the sequence one reads the term
       out of #ovterm rather than being handed it. Mirroring the cleaned query
       into a hidden field keeps pagination working past page two, and keeps it
       pinned to the search that produced the results rather than to whatever
       has since been typed in the visible field. */
    if (mirror) { mirror.value = query; }

    if (status) { status.textContent = 'Searching the archived collection.'; }
    results.setAttribute('aria-busy', 'true');
    results.innerHTML = '<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span><span>Searching archived Overgo records&hellip;</span></div>';
    updateAddress(mode, query);

    window.main_div_name = results.id;
    /* The term goes over raw. jQuery.post form-encodes the data object, so an
       encodeURI() here would be a second encoding -- and unlike est_results.php
       and bac_results.php, the two overgo endpoints do not urldecode what they
       receive. That double encoding turned every anchored or wildcard search
       into a literal: `^CL10` reached the database as `%5ECL10` and matched
       nothing, which is what the search hint had been advertising. The legacy
       pagination in js/search.js still encodes, so page two of such a search
       is still wrong -- recorded as AD-046. */
    window.jQuery.post('/search/' + mode + '/' + mode + '_results.php', {
      term: query,
      search_limit: byId('limit_val') ? byId('limit_val').value : '',
      div_name: results.id
    }).done(function (data, textStatus, xhr) {
      var response = xhr.responseText || data || '';
      /* A search that matches exactly one record answers with a redirect
         instruction rather than a results table. */
      var redirect = response.match(/^\s*javascript:(?:parent\.)?(?:document|location)\.?\w*\s*=\s*['"]([^'"]+)['"]/i);
      if (redirect) {
        window.location.assign(redirect[1]);
        return;
      }

      results.innerHTML = response;
      results.setAttribute('aria-busy', 'false');
      if (status) {
        status.textContent = /no records|no overgo/i.test(response)
          ? 'No matching archived records were found.'
          : 'Search complete. Matching archived records are shown below.';
      }
      executeEmbeddedScript(response);
      results.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        block: 'nearest'
      });
    }).fail(function () {
      results.setAttribute('aria-busy', 'false');
      results.innerHTML = '<div class="mgdb-message mgdb-message-error" role="alert"><div><strong>Search unavailable.</strong> The archived collection could not be queried. Please try again.</div></div>';
      if (status) { status.textContent = 'The search could not be completed.'; }
    });
  }

  function initSearch() {
    var form = byId('overgo-search-form');
    if (!form) { return; }

    applyMode();

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      runSearch();
    });

    form.addEventListener('reset', function () {
      window.setTimeout(function () {
        var results = byId('overgo-search-results');
        var input = byId('overgo_term');
        var status = byId('overgo-search-status');
        var mirror = byId('ovterm');
        if (results) {
          results.innerHTML = '';
          results.removeAttribute('aria-busy');
        }
        if (status) { status.textContent = ''; }
        if (input) { input.removeAttribute('aria-invalid'); }
        if (mirror) { mirror.value = ''; }
        applyMode();
        updateAddress(currentMode(), '');
      }, 0);
    });

    var select = byId('overgo-mode');
    if (select) { select.addEventListener('change', applyMode); }

    Array.prototype.forEach.call(form.querySelectorAll('[data-overgo-example]'), function (button) {
      button.addEventListener('click', function () {
        var input = byId('overgo_term');
        if (!input) { return; }
        input.value = button.getAttribute('data-overgo-example');
        runSearch();
      });
    });

    var params = new URLSearchParams(window.location.search);
    var query = params.get('q');
    if (!query) { return; }
    if (select && params.get('mode') === 'sequence') {
      select.value = 'overgo_seq';
      applyMode();
    }
    var input = byId('overgo_term');
    if (input) {
      input.value = query;
      runSearch();
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

  /* ── Records by name family figure ──────────────────────────────────────── */

  /* The census is rendered server side into #overgo-chart-data by the same
     GROUP BY that fills the metric cards, so this draws without a request of
     its own. */
  function initFigure() {
    var data = readJson('overgo-chart-data');
    if (!data || !data.bars || !data.bars.length) { return; }

    var bars = data.bars;

    var table = byId('overgo-family-table');
    if (table) {
      var body = table.querySelector('tbody');
      if (body) {
        body.innerHTML = bars.map(function (bar) {
          return '<tr><td><code>' + esc(bar.label) + '</code></td>'
               + '<td>' + esc(bar.collection) + '</td>'
               + '<td class="mgdb-numeric">' + number(bar.count) + '</td></tr>';
        }).join('');
      }
    }

    if (!window.MGDB || !window.MGDB.chart) { return; }

    /* Plotly stacks horizontal bars bottom-up, so the array is reversed to put
       the largest family at the top, matching the table. */
    var ordered = bars.slice().reverse();
    var el = byId('overgo-family-chart');
    if (!el) { return; }
    var height = Math.max(260, ordered.length * 44 + 110);
    el.style.height = height + 'px';

    /* Margins are sized from the figure, not fixed. MGDB.chart re-runs
       Plotly.Plots.resize on a window resize, which rescales the figure but
       keeps the margins it was drawn with, so a desktop gutter would survive
       onto a phone and squeeze the plot to nothing. The family names are three
       characters, so even the desktop gutter is modest. */
    var NARROW = 560;
    function metrics() {
      var width = el.getBoundingClientRect().width;
      var narrow = width > 0 && width < NARROW;
      return {
        narrow: narrow,
        margin: narrow ? { l: 58, r: 16, t: 8, b: 44 } : { l: 78, r: 72, t: 8, b: 44 },
        nticks: narrow ? 3 : 0
      };
    }
    var m = metrics();

    /* One tone per collection, so the two libraries read apart without a
       legend the five short labels do not need. */
    var COLORS = { 'Unigene-Overgo': '#285d46', 'Overgo': '#a96919' };

    window.MGDB.chart({
      target: el,
      traces: [{
        type: 'bar',
        orientation: 'h',
        y: ordered.map(function (b) { return b.label; }),
        x: ordered.map(function (b) { return b.count; }),
        customdata: ordered.map(function (b) { return b.collection; }),
        marker: { color: ordered.map(function (b) { return COLORS[b.collection] || '#1a5b7a'; }) },
        /* A non-breaking space: SVG collapses a plain leading one, leaving the
           label flush against the end of its bar. */
        text: ordered.map(function (b) { return '\u00A0' + number(b.count); }),
        textposition: m.narrow ? 'none' : 'outside',
        cliponaxis: false,
        hovertemplate: '%{y}<br>%{customdata}<br>%{x:,} records<extra></extra>'
      }],
      layout: {
        height: height,
        showlegend: false,
        margin: m.margin,
        xaxis: { title: 'Records', zeroline: false, tickformat: ',d', nticks: m.nticks },
        yaxis: { automargin: true }
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
          window.Plotly.relayout(el, { margin: next.margin, 'xaxis.nticks': next.nticks });
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
