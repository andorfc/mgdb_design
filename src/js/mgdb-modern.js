/* ==========================================================================
   MaizeGDB Modern — shared behavior
   --------------------------------------------------------------------------
   Companion to /css/mgdb-modern.css. No dependencies: the legacy jQuery in the shell
   is deliberately not used here.

   Everything is progressive enhancement. If this file fails to load, pages
   still render their server-side content, forms still submit, and links still
   work — only the client-side filtering and charts are lost.

   Public surface (window.MGDB):
     MGDB.debounce(fn, wait)
     MGDB.escapeHtml(value)
     MGDB.announce(message)
     MGDB.request(url, options)   — fetch wrapper that cancels stale requests
     MGDB.filterList(config)      — client-side search + filter + live count
     MGDB.sortTable(table)        — accessible column sorting
     MGDB.chart(config)           — lazy, responsive, accessible Plotly charts
     MGDB.CHART_COLORS            — colour-blind-safe qualitative palette
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB || {};

  /* ------------------------------------------------------------------------
     Utilities
     ------------------------------------------------------------------------ */

  function debounce(fn, wait) {
    var timer = null;
    return function () {
      var context = this;
      var args = arguments;
      window.clearTimeout(timer);
      timer = window.setTimeout(function () { fn.apply(context, args); }, wait || 200);
    };
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function normalize(value) {
    return String(value == null ? '' : value).toLowerCase().replace(/\s+/g, ' ').trim();
  }

  function prefersReducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  /* A single polite live region, reused for anything that needs announcing.
     Kept out of the tab order and off-screen. */
  var liveRegion = null;

  function announce(message) {
    if (!liveRegion) {
      liveRegion = document.createElement('div');
      liveRegion.className = 'mgdb-visually-hidden';
      liveRegion.setAttribute('role', 'status');
      liveRegion.setAttribute('aria-live', 'polite');
      liveRegion.setAttribute('aria-atomic', 'true');
      document.body.appendChild(liveRegion);
    }
    // Clearing first forces assistive technology to re-read an identical message.
    liveRegion.textContent = '';
    window.setTimeout(function () { liveRegion.textContent = message; }, 60);
  }

  /* ------------------------------------------------------------------------
     Request helper

     Keyed by name so a newer request for the same purpose aborts the one in
     flight. Prevents an older, slower response from overwriting newer results.
     ------------------------------------------------------------------------ */

  var inFlight = {};

  function request(url, options) {
    options = options || {};
    var key = options.key || url;

    if (inFlight[key] && inFlight[key].abort) {
      inFlight[key].abort();
    }

    if (!window.fetch || !window.AbortController) {
      return Promise.reject(new Error('unsupported'));
    }

    var controller = new window.AbortController();
    inFlight[key] = controller;

    return window.fetch(url, {
      signal: controller.signal,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('Request failed with status ' + response.status);
      }
      return response.json();
    }).then(function (data) {
      if (inFlight[key] === controller) { delete inFlight[key]; }
      return data;
    }).catch(function (error) {
      if (inFlight[key] === controller) { delete inFlight[key]; }
      throw error;
    });
  }

  /* ------------------------------------------------------------------------
     URL state

     Search terms and filters are mirrored into the query string so results can
     be linked and shared, and so the back button behaves sensibly.
     ------------------------------------------------------------------------ */

  function readUrlState(keys) {
    var state = {};
    if (!window.URLSearchParams) { return state; }
    var params = new window.URLSearchParams(window.location.search);
    keys.forEach(function (key) {
      if (params.has(key)) { state[key] = params.get(key); }
    });
    return state;
  }

  function writeUrlState(state, defaults) {
    if (!window.URLSearchParams || !window.history || !window.history.replaceState) { return; }
    var params = new window.URLSearchParams(window.location.search);
    Object.keys(state).forEach(function (key) {
      var value = state[key];
      if (!value || value === (defaults || {})[key]) { params.delete(key); }
      else { params.set(key, value); }
    });
    var query = params.toString();
    window.history.replaceState(null, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash);
  }

  /* ------------------------------------------------------------------------
     filterList — client-side search and filtering over a rendered list

     The list is rendered server-side, so it is complete and indexable before
     this runs. Filtering only hides and shows existing nodes.

     config:
       items        (required) NodeList or array of elements to filter
       input        text input element
       chips        array/NodeList of filter buttons carrying data-filter
       count        element that receives the result count
       empty        element shown when nothing matches
       reset        button that clears the search and filters
       matchOn      function(element) -> searchable string. Defaults to
                    the element's data-search attribute, then textContent.
       filterOn     function(element, filterValue) -> boolean
       noun         plural noun used in the count, e.g. "meetings"
       urlKeys      { query: 'q', filter: 'period' } to enable URL sync
       onChange     function(visibleCount, total)
     ------------------------------------------------------------------------ */

  function filterList(config) {
    if (!config || !config.items) { return null; }

    var items = Array.prototype.slice.call(config.items);
    if (!items.length) { return null; }

    var input = config.input || null;
    var chips = config.chips ? Array.prototype.slice.call(config.chips) : [];
    var countEl = config.count || null;
    var emptyEl = config.empty || null;
    var resetEl = config.reset || null;
    var noun = config.noun || 'results';
    var urlKeys = config.urlKeys || null;

    var matchOn = config.matchOn || function (el) {
      return el.getAttribute('data-search') || el.textContent || '';
    };
    var filterOn = config.filterOn || function (el, value) {
      return value === 'all' || el.getAttribute('data-filter') === value;
    };

    // Cache the searchable text once rather than re-reading the DOM per keystroke.
    var haystack = items.map(matchOn).map(normalize);

    var state = { query: '', filter: 'all' };

    if (urlKeys) {
      var fromUrl = readUrlState([urlKeys.query, urlKeys.filter].filter(Boolean));
      if (urlKeys.query && fromUrl[urlKeys.query]) { state.query = fromUrl[urlKeys.query]; }
      if (urlKeys.filter && fromUrl[urlKeys.filter]) { state.filter = fromUrl[urlKeys.filter]; }
    }

    function syncUrl() {
      if (!urlKeys) { return; }
      var payload = {};
      if (urlKeys.query) { payload[urlKeys.query] = state.query; }
      if (urlKeys.filter) { payload[urlKeys.filter] = state.filter; }
      writeUrlState(payload, urlKeys.filter ? (function (d) { d[urlKeys.filter] = 'all'; return d; })({}) : {});
    }

    function apply(announceResult) {
      var needle = normalize(state.query);
      var visible = 0;

      items.forEach(function (el, index) {
        var matchesText = !needle || haystack[index].indexOf(needle) !== -1;
        var matchesFilter = filterOn(el, state.filter);
        var show = matchesText && matchesFilter;
        el.hidden = !show;
        if (show) { visible += 1; }
      });

      /* Grouped, because every other number the design system prints is.
         The first list long enough to notice was /ssrreports, which announced
         "2034 records shown" under a metric card reading "2,034". */
      var message = visible === items.length
        ? items.length.toLocaleString() + ' ' + noun + ' shown'
        : visible.toLocaleString() + ' of ' + items.length.toLocaleString() + ' ' + noun + ' shown';

      if (countEl) { countEl.textContent = message; }
      if (emptyEl) { emptyEl.hidden = visible !== 0; }
      if (resetEl) { resetEl.hidden = !state.query && state.filter === 'all'; }

      chips.forEach(function (chip) {
        chip.setAttribute('aria-pressed', chip.getAttribute('data-filter') === state.filter ? 'true' : 'false');
      });

      // The count element is already aria-live; only announce explicitly when
      // the change came from a filter button, which has no live region of its own.
      if (announceResult) { announce(message); }
      if (typeof config.onChange === 'function') { config.onChange(visible, items.length); }
    }

    if (input) {
      input.value = state.query;
      input.addEventListener('input', debounce(function () {
        state.query = input.value;
        apply(false);
        syncUrl();
      }, 200));

      // Enter must not submit and reload; filtering is already live.
      input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
          event.preventDefault();
          state.query = input.value;
          apply(true);
          syncUrl();
        }
      });
    }

    chips.forEach(function (chip) {
      chip.addEventListener('click', function () {
        state.filter = chip.getAttribute('data-filter') || 'all';
        apply(true);
        syncUrl();
      });
    });

    if (resetEl) {
      resetEl.addEventListener('click', function () {
        state.query = '';
        state.filter = 'all';
        if (input) { input.value = ''; }
        apply(true);
        syncUrl();
        if (input) { input.focus(); }
      });
    }

    apply(false);

    return {
      refresh: function () { apply(false); },
      state: state
    };
  }

  /* ------------------------------------------------------------------------
     sortTable — accessible column sorting

     Expects <th> elements carrying data-sort ("text" or "number") wrapping a
     <button>. aria-sort is maintained on the <th> so the state is exposed.
     ------------------------------------------------------------------------ */

  function sortTable(table) {
    if (!table) { return; }
    var tbody = table.tBodies[0];
    if (!tbody) { return; }
    var headers = Array.prototype.slice.call(table.querySelectorAll('th[data-sort]'));
    if (!headers.length) { return; }

    headers.forEach(function (th, columnIndex) {
      if (!th.getAttribute('aria-sort')) { th.setAttribute('aria-sort', 'none'); }

      var button = th.querySelector('button');
      if (!button) {
        button = document.createElement('button');
        button.type = 'button';
        button.innerHTML = th.innerHTML;
        th.innerHTML = '';
        th.appendChild(button);
      }

      button.addEventListener('click', function () {
        var ascending = th.getAttribute('aria-sort') !== 'ascending';
        var type = th.getAttribute('data-sort');
        var index = Array.prototype.indexOf.call(th.parentNode.children, th);
        var rows = Array.prototype.slice.call(tbody.rows);

        rows.sort(function (a, b) {
          var aCell = a.cells[index];
          var bCell = b.cells[index];
          var aValue = aCell ? (aCell.getAttribute('data-value') || aCell.textContent) : '';
          var bValue = bCell ? (bCell.getAttribute('data-value') || bCell.textContent) : '';

          if (type === 'number') {
            var aNum = parseFloat(String(aValue).replace(/[^0-9.eE+-]/g, ''));
            var bNum = parseFloat(String(bValue).replace(/[^0-9.eE+-]/g, ''));
            // Missing values always sort last, regardless of direction, so
            // "not reported" is never mistaken for zero.
            if (isNaN(aNum) && isNaN(bNum)) { return 0; }
            if (isNaN(aNum)) { return 1; }
            if (isNaN(bNum)) { return -1; }
            return ascending ? aNum - bNum : bNum - aNum;
          }

          var comparison = String(aValue).trim().localeCompare(String(bValue).trim(), undefined, {
            numeric: true, sensitivity: 'base'
          });
          return ascending ? comparison : -comparison;
        });

        rows.forEach(function (row) { tbody.appendChild(row); });

        headers.forEach(function (other) { other.setAttribute('aria-sort', 'none'); });
        th.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
        announce(
          (button.textContent || 'Column').trim() +
          ', sorted ' + (ascending ? 'ascending' : 'descending') +
          ', ' + rows.length + ' rows'
        );
      });
      void columnIndex;
    });
  }

  /* ------------------------------------------------------------------------
     Charts

     Okabe-Ito qualitative palette: distinguishable under all common forms of
     colour vision deficiency. Charts must also vary marker symbol or dash
     pattern so colour is never the only encoding.
     ------------------------------------------------------------------------ */

  var CHART_COLORS = ['#D55E00', '#0072B2', '#E69F00', '#009E73', '#CC79A7', '#56B4E9', '#7A5195'];
  var CHART_SYMBOLS = ['circle', 'square', 'diamond', 'triangle-up', 'cross', 'x'];
  var CHART_DASHES = ['solid', 'dash', 'dot', 'dashdot'];

  var BASE_LAYOUT = {
    font: { family: 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif', size: 13, color: '#1f2723' },
    paper_bgcolor: '#ffffff',
    plot_bgcolor: '#ffffff',
    margin: { l: 60, r: 20, t: 12, b: 56 },
    hovermode: 'closest',
    colorway: CHART_COLORS,
    /* Above the plot, anchored to its top edge, rather than below it.

       `y` is in paper coordinates -- a fraction of the *plot* height -- so the
       old `y: -0.22` moved further from the axis the taller the chart got: on a
       320px figure it landed inside the 56px bottom margin and sat on the tick
       labels, and on a 700px one it was drawn ~140px below a 44px margin,
       outside the paper, bleeding over the figcaption. Above the plot there is
       no axis furniture to collide with, and placeLegend() below keeps a
       band open for it. */
    legend: { orientation: 'h', x: 0, xanchor: 'left', y: 1, yanchor: 'bottom', font: { size: 13 } },
    /* `ticks: 'outside'` with a transparent tick colour is the standoff: it
       reserves ticklen pixels between a tick label and the plot without drawing
       a mark. automargin keeps a label from being cut off, but it makes the
       margin exactly as wide as the text, so the longest category name ends up
       one pixel from the first bar and reads as running into it. Measured at
       1px on all three /data_center/variation figures before this.

       The y axis gets the larger gap because it carries the category names on a
       horizontal bar chart, which is where the two collide. */
    xaxis: { gridcolor: '#ece7dd', zerolinecolor: '#dcd8cf', automargin: true,
             ticks: 'outside', ticklen: 6, tickcolor: 'rgba(0,0,0,0)' },
    yaxis: { gridcolor: '#ece7dd', zerolinecolor: '#dcd8cf', automargin: true,
             ticks: 'outside', ticklen: 10, tickcolor: 'rgba(0,0,0,0)' }
  };

  var BASE_CONFIG = {
    displayModeBar: false,
    responsive: true,
    // Locks the chart to the page's own scroll behavior rather than zooming.
    scrollZoom: false
  };

  /* One row of 13px legend text plus breathing room. A legend that wraps to two
     rows is measured for after the draw rather than guessed at here. */
  var LEGEND_BAND = 34;

  /* Will Plotly actually draw a legend? It shows one by default only when there
     is more than one trace, so a single-series chart must not have a band
     reserved for a legend that never appears. */
  function willShowLegend(layout, traces) {
    if (layout.showlegend === false) { return false; }
    if (layout.showlegend === true) { return true; }
    return traces.length > 1;
  }

  /* Put the legend above the plot and keep a band open for it.

     The placement is normalised rather than merged, so a page cannot move the
     legend into the plot by passing its own `y`. That is not tidiness: `y` is
     in paper coordinates -- a fraction of the *plot* height -- so any fixed
     value drifts as a chart gets taller. A `y: -0.22` legend sat on the tick
     labels of a 320px figure and was drawn a hundred and forty pixels below a
     700px one, outside the paper entirely, bleeding over the figcaption.
     Anchored to the top of the plot there is no axis furniture to collide with
     and the offset cannot drift, because the anchor moves with the plot.

     A figure that genuinely needs the legend somewhere else passes
     `legendManual: true` and takes responsibility for it. */
  function placeLegend(layout, traces, manual) {
    if (!willShowLegend(layout, traces)) {
      // Say so explicitly, so a one-trace chart cannot acquire a legend later
      // from a trace that happens to set showlegend on itself.
      layout.showlegend = false;
      return layout;
    }
    if (manual) { return layout; }

    layout.legend = Object.assign({}, layout.legend, {
      orientation: 'h',
      x: 0,
      xanchor: 'left',
      y: 1,
      yanchor: 'bottom'
    });
    layout.margin = layout.margin || {};
    layout.margin.t = Math.max(layout.margin.t || 0, LEGEND_BAND);

    return layout;
  }

  /* Check the drawn figure rather than trusting the reservation.

     With the legend anchored to the top of the plot it cannot overlap the plot
     by construction, so there is exactly one way this goes wrong: the legend
     wraps to more rows than the reserved band is tall and is clipped at the top
     of the figure. That is measurable from the legend and the figure alone --
     no plot rectangle needed, which matters because the obvious DOM handle for
     one is wrong. `.cartesianlayer` is a <g> whose bounding box spans the whole
     SVG, not the plotting area, so an earlier version of this check read a 30px
     overlap on a figure that had none and grew the top margin every pass.

     Growing the top margin is safe because the legend is anchored to the plot:
     the plot moves down and the legend moves with it, so one correction
     settles. Bounded anyway -- at most two passes, and never more than a third
     of the figure.
     -------------------------------------------------------------------- */
  function fitLegend(target, layout, attempt) {
    if (!window.Plotly || !window.Plotly.relayout) { return; }
    if ((attempt || 0) >= 2) { return; }
    if (layout.showlegend === false || layout.legendManual) { return; }

    var legend = target.querySelector('g.legend');
    if (!legend) { return; }

    var legendBox, figureBox;
    try {
      legendBox = legend.getBoundingClientRect();
      figureBox = target.getBoundingClientRect();
    } catch (error) { return; }
    if (!legendBox.height || !figureBox.height) { return; }

    var shortfall = Math.ceil(figureBox.top - legendBox.top);
    if (shortfall < 2) { return; }

    var margin = layout.margin || {};
    var wanted = Math.min((margin.t || 0) + shortfall + 6, Math.floor(figureBox.height / 3));
    if (wanted <= (margin.t || 0)) { return; }

    margin.t = wanted;
    layout.margin = margin;
    window.Plotly.relayout(target, { 'margin.t': wanted }).then(function () {
      fitLegend(target, layout, (attempt || 0) + 1);
    }).catch(function () { /* the figure is drawn either way */ });
  }

  function mergeLayout(custom) {
    var layout = JSON.parse(JSON.stringify(BASE_LAYOUT));
    Object.keys(custom || {}).forEach(function (key) {
      if (custom[key] && typeof custom[key] === 'object' && !Array.isArray(custom[key]) && layout[key]) {
        Object.keys(custom[key]).forEach(function (inner) { layout[key][inner] = custom[key][inner]; });
      } else {
        layout[key] = custom[key];
      }
    });
    return layout;
  }

  /* Renders a Plotly figure into config.target, lazily.

     config:
       target      (required) element id or element
       traces      (required) array, or a function returning one
       layout      Plotly layout overrides, merged over BASE_LAYOUT
       config      Plotly config overrides
       fallback    message shown if Plotly is unavailable
       legendManual  keep the layout's own legend position instead of the
                     shared one above the plot; the figure then owns the
                     margins too

     The element is expected to already contain a .mgdb-chart-fallback child and
     to carry role="img" plus an aria-label describing the chart. The visible
     text interpretation and data table live in the surrounding <figure>, so an
     assistive-technology user never depends on the canvas. */
  function chart(config) {
    if (!config || !config.target) { return; }

    var target = typeof config.target === 'string' ? document.getElementById(config.target) : config.target;
    if (!target) { return; }

    function fail(message) {
      var fallback = target.querySelector('.mgdb-chart-fallback');
      if (fallback) { fallback.textContent = message; }
    }

    function render() {
      if (!window.Plotly) {
        fail(config.fallback || 'This chart could not be displayed. The underlying values are listed in the data table below.');
        return;
      }

      var traces;
      try {
        traces = typeof config.traces === 'function' ? config.traces() : config.traces;
      } catch (error) {
        fail('This chart could not be displayed. The underlying values are listed in the data table below.');
        return;
      }

      if (!traces || !traces.length) {
        fail('No data is available for this chart.');
        return;
      }

      var layout = placeLegend(mergeLayout(config.layout), traces, config.legendManual);
      var plotConfig = Object.assign({}, BASE_CONFIG, config.config || {});
      if (prefersReducedMotion()) { layout.transition = { duration: 0 }; }

      target.textContent = '';

      window.Plotly.newPlot(target, traces, layout, plotConfig).then(function () {
        // Plotly's generated SVG is decorative here; role="img" plus the
        // aria-label on the container is what assistive technology reads.
        var svg = target.querySelector('.main-svg');
        if (svg) { svg.setAttribute('aria-hidden', 'true'); }
        fitLegend(target, layout, 0);
        if (window.Plotly.Plots && window.Plotly.Plots.resize) {
          window.addEventListener('resize', debounce(function () {
            window.Plotly.Plots.resize(target);
            // A narrower figure wraps the legend onto more rows.
            fitLegend(target, layout, 0);
          }, 150));
        }
      }).catch(function () {
        fail('This chart could not be displayed. The underlying values are listed in the data table below.');
      });
    }

    // Render exactly once, whichever trigger gets there first.
    var rendered = false;
    function renderOnce() {
      if (rendered) { return; }
      rendered = true;
      render();
    }

    function nearViewport() {
      var rect = target.getBoundingClientRect();
      var height = window.innerHeight || document.documentElement.clientHeight;
      return rect.top < height + 200 && rect.bottom > -200;
    }

    // Charts already on screen are drawn immediately; deferring them only
    // delays the content the reader came for.
    if (nearViewport()) {
      renderOnce();
      return;
    }

    if (!window.IntersectionObserver) {
      renderOnce();
      return;
    }

    var observer = new window.IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          observer.disconnect();
          renderOnce();
        }
      });
    }, { rootMargin: '200px' });
    observer.observe(target);

    // Safety net. IntersectionObserver does not fire in every environment
    // (some headless and embedded browsers never deliver entries), and a chart
    // that silently never renders is worse than one drawn slightly early.
    // A scroll listener plus a bounded timeout both fall back to rendering.
    var onScroll = function () {
      if (nearViewport()) {
        window.removeEventListener('scroll', onScroll);
        observer.disconnect();
        renderOnce();
      }
    };
    window.addEventListener('scroll', onScroll, { passive: true });

    window.setTimeout(function () {
      window.removeEventListener('scroll', onScroll);
      observer.disconnect();
      renderOnce();
    }, 3000);
  }

  /* ------------------------------------------------------------------------
     Automatic wiring
     ------------------------------------------------------------------------ */

  function init() {
    // Wide tables scroll in their own container; make that container reachable
    // by keyboard, as a scrollable region needs to be focusable.
    Array.prototype.forEach.call(document.querySelectorAll('.mgdb-table-scroll'), function (region) {
      if (region.scrollWidth > region.clientWidth) {
        if (!region.hasAttribute('tabindex')) { region.setAttribute('tabindex', '0'); }
        if (!region.hasAttribute('role')) { region.setAttribute('role', 'region'); }
        if (!region.hasAttribute('aria-label')) {
          var caption = region.querySelector('caption');
          region.setAttribute('aria-label', (caption ? caption.textContent.trim() : 'Data table') + ' (scrollable)');
        }
      }
    });

    Array.prototype.forEach.call(document.querySelectorAll('table[data-sortable]'), sortTable);

    initCopyButtons();
  }

  /* Copy citation / Copy DOI on a reference card. Bound here rather than in each
     page script, so every page that renders include/references_lib.php markup
     gets the behaviour without asking for it. */
  function initCopyButtons() {
    Array.prototype.forEach.call(document.querySelectorAll('.mgdb-ref-copy'), function (button) {
      if (button.hasAttribute('data-copy-bound')) { return; }
      button.setAttribute('data-copy-bound', '');
      button.addEventListener('click', function () {
        var value = button.getAttribute('data-copy-value');
        if (!value) {
          var source = document.getElementById(button.getAttribute('data-copy-target') || '');
          value = source ? source.textContent.trim() : '';
        }
        if (value) { copyToClipboard(value, button); }
      });
    });
  }

  function copyToClipboard(text, button) {
    var original = button.textContent;
    function done() {
      button.textContent = 'Copied';
      window.setTimeout(function () { button.textContent = original; }, 1600);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      // A rejection here is not rare -- an insecure context, a denied
      // permission, or a document that does not have focus all reject -- so it
      // falls through to the older path rather than leaving the button dead.
      navigator.clipboard.writeText(text).then(done).catch(function () { legacyCopy(text, done); });
      return;
    }

    legacyCopy(text, done);
  }

  function legacyCopy(text, done) {
    var area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'absolute';
    area.style.left = '-9999px';
    document.body.appendChild(area);
    area.select();
    try { document.execCommand('copy'); done(); } catch (error) { /* nothing to do */ }
    document.body.removeChild(area);
  }

  /* ======================================================================
     Section tabs (scrollspy)

     `.mgdb-section-tabs` is markup the shell styles; the behaviour was never
     shared, so twenty pages each carried their own copy of this and eleven
     shipped without one. Those bars highlighted whatever the template marked
     `is-current` and never changed -- and nothing errored, so the fault was
     invisible until someone scrolled. This is that behaviour, once.

     Deliberately NOT auto-wired from init(): the pages that already have their
     own copy would then run two spies over the same bar, and the two would
     fight over the click hold. Opt in with MGDB.sectionTabs().

     Driven by scroll, IntersectionObserver and resize together, because no one
     trigger fires in every case. Pass `watch` (an element, or a selector) for a
     region whose height changes -- a results panel that unhides moves every
     section below it.

     Returns the update function, so a caller with its own reason to re-measure
     can call it.
     ====================================================================== */

  function sectionTabs(options) {
    var opts = options || {};
    var bar = typeof opts.bar === 'string' ? document.querySelector(opts.bar)
            : (opts.bar || document.querySelector('.mgdb-section-tabs'));
    if (!bar) { return function () {}; }

    var links = bar.querySelectorAll('a');
    if (!links.length) { return function () {}; }

    var pairs = [];
    Array.prototype.forEach.call(links, function (tab) {
      var href = tab.getAttribute('href') || '';
      if (href.charAt(0) !== '#') { return; }
      var section = document.getElementById(href.slice(1));
      if (section) { pairs.push({ tab: tab, section: section }); }
    });
    if (!pairs.length) { return function () {}; }

    var heldSection = null;
    var heldAtY = 0;

    function mark(section) {
      pairs.forEach(function (pair) {
        var current = pair.section === section;
        pair.tab.classList.toggle('is-current', current);
        if (current) { pair.tab.setAttribute('aria-current', 'true'); }
        else { pair.tab.removeAttribute('aria-current'); }
      });
    }

    /* The line to measure against is the section's own scroll-margin-top, read
       back from CSS rather than repeated here, so a clicked tab and the spy
       agree by construction even when the bar wraps to a second row. */
    function triggerLine() {
      var barHeight = bar.getBoundingClientRect().height;
      var margin = parseFloat(window.getComputedStyle(pairs[0].section).scrollMarginTop) || 0;
      return Math.max(barHeight + 8, margin + 4);
    }

    function update() {
      /* A click marks its own tab at once; hold that until the reader really
         scrolls, or a smooth scroll drags the highlight through every section
         on the way down. */
      if (heldSection) {
        if (Math.abs(window.scrollY - heldAtY) < 4) { return; }
        heldSection = null;
      }

      var line = triggerLine();
      var current = pairs[0];

      pairs.forEach(function (pair) {
        if (pair.section.hasAttribute('hidden')) { return; }
        if (pair.section.getBoundingClientRect().top <= line) { current = pair; }
      });

      /* At the foot of the document the last section may never reach the line,
         so it would otherwise be unreachable. */
      if ((window.innerHeight + window.scrollY) >= (document.body.scrollHeight - 2)) {
        current = pairs[pairs.length - 1];
      }

      mark(current.section);
    }

    pairs.forEach(function (pair) {
      pair.tab.addEventListener('click', function () {
        mark(pair.section);
        heldSection = pair.section;
        heldAtY = window.scrollY;
      });
    });

    window.addEventListener('scroll', debounce(update, 50), { passive: true });
    window.addEventListener('resize', update);

    if (window.IntersectionObserver) {
      var observer = new window.IntersectionObserver(function () { update(); },
        { rootMargin: '-20% 0px -60% 0px' });
      pairs.forEach(function (pair) { observer.observe(pair.section); });
    }

    var watched = typeof opts.watch === 'string' ? document.querySelector(opts.watch) : opts.watch;
    if (watched && window.MutationObserver) {
      new window.MutationObserver(update).observe(watched, {
        childList: true, subtree: true, attributes: true, attributeFilter: ['hidden']
      });
    }

    /* A fragment arrival -- /maize_history#history-classic-reads from the
       Community menu -- is scrolled by the browser around the time this runs,
       and whether that scroll reaches the listener above depends on when the
       document settles. Re-measure once after load so the bar cannot sit on
       the first tab while the reader is already halfway down the page. */
    if (document.readyState !== 'complete') {
      window.addEventListener('load', function () { window.setTimeout(update, 0); });
    }

    update();
    return update;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  MGDB.debounce = debounce;
  MGDB.escapeHtml = escapeHtml;
  MGDB.normalize = normalize;
  MGDB.announce = announce;
  MGDB.request = request;
  MGDB.filterList = filterList;
  MGDB.sortTable = sortTable;
  MGDB.sectionTabs = sectionTabs;
  MGDB.chart = chart;
  MGDB.mergeLayout = mergeLayout;
  MGDB.CHART_COLORS = CHART_COLORS;
  MGDB.CHART_SYMBOLS = CHART_SYMBOLS;
  MGDB.CHART_DASHES = CHART_DASHES;
  MGDB.prefersReducedMotion = prefersReducedMotion;
  MGDB.initCopyButtons = initCopyButtons;

  window.MGDB = MGDB;
})(window, document);
