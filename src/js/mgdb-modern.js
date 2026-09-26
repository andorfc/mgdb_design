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
     MGDB.chart(config)           — lazy, responsive, accessible Plotly charts;
                                    returns a promise for the drawn element
     MGDB.loadPlotly()            — Plotly on demand; a promise for window.Plotly
     MGDB.whenNear(el, fn)        — fn once el is within 200px of the viewport
     MGDB.CHART_COLORS            — colour-blind-safe qualitative palette
     MGDB.typeahead(input, opts)  — suggestions under a search field; also
                                    any input or textarea[data-suggest] on load
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

  /* ------------------------------------------------------------------------
     Plotly, fetched when a figure is about to be drawn

     Plotly is 4.6 MB -- 1.3 MB compressed -- and every chart page used to
     load it in <head>, where it held up the first paint of forty-odd pages,
     the gene record among them, whether or not the reader ever reached a
     chart. On the gene record the one figure sits at the foot of Metrics.

     Nothing loads it up front now. MGDB.chart() asks for it when its figure
     comes within reach of the viewport, every figure on the page shares the
     one download, and a figure within a screen and a half of view starts the
     download early, so a reader scrolling down meets a drawn chart rather
     than a loading line.

     A page that draws with Plotly itself, outside MGDB.chart(), asks the
     same way and gets the same copy:

       MGDB.loadPlotly().then(function (Plotly) { Plotly.react(el, ...); });

     A page that still includes Plotly in its <head> is not affected: the
     promise resolves at once with the copy already there.

     Two builds are served from this site, byte-identical to Plotly's own
     npm releases of 2.35.2, and each file's name carries its version, so a
     new release is a new file and a new path here. A page gets the cartesian
     build: 1.36 MB against 4.56 MB for the full one (441 KB against 1.29 MB
     compressed), and from scrolling to a figure to seeing it drawn that was
     3.7 s against 8.0 s on a slow phone connection, 0.48 s against 1.35 s on
     a fast one (the gene record's Metrics, 2026-09-25). It carries every
     type the site draws -- bar, scatter, heatmap, box and pie, with histogram,
     contour, violin, image and ternary besides. The full build adds the WebGL,
     3D, polar, map and hierarchy types, and the two pages that draw scattergl,
     the BLAST results and Expression Tools, ask for it from their controllers
     with <meta name="mgdb-plotly" content="full">.

     A type the cartesian build lacks is not an error: Plotly draws it as a
     plain SVG scatter and says nothing, which for a scattergl figure of a few
     thousand points is a slow page rather than a broken one. MGDB.chart()
     warns in the console when a figure comes out as another type than it
     asked for, so the missing <meta> is found by whoever adds the figure.
     ------------------------------------------------------------------------ */

  var PLOTLY_BUILDS = {
    cartesian: '/js/lib/plotly/plotly-cartesian-2.35.2.min.js',
    full: '/js/lib/plotly/plotly-2.35.2.min.js'
  };
  var plotlyPromise = null;

  function plotlySrc() {
    var meta = document.querySelector('meta[name="mgdb-plotly"]');
    var build = meta ? meta.getAttribute('content') : '';
    return PLOTLY_BUILDS[build] || PLOTLY_BUILDS.cartesian;
  }

  function loadPlotly() {
    if (window.Plotly) { return Promise.resolve(window.Plotly); }
    if (plotlyPromise) { return plotlyPromise; }

    plotlyPromise = new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      var src = plotlySrc();

      function failed() {
        // Forgotten, so the next figure to come into view tries again: one
        // dropped connection on a phone should not blank every chart on the
        // page for good.
        plotlyPromise = null;
        if (script.parentNode) { script.parentNode.removeChild(script); }
        reject(new Error('Plotly could not be loaded from ' + src));
      }

      script.src = src;
      script.async = true;
      script.onload = function () {
        if (window.Plotly) { resolve(window.Plotly); } else { failed(); }
      };
      script.onerror = failed;
      document.head.appendChild(script);
    });
    return plotlyPromise;
  }

  /* One observer per page starts the download a screen and a half ahead of
     the first figure, then stands down. A figure with no box -- in a hidden
     section -- never intersects, so it starts nothing. */
  var prefetchObserver = null;

  function prefetchPlotly(target) {
    if (window.Plotly || plotlyPromise || !window.IntersectionObserver) { return; }
    if (!prefetchObserver) {
      prefetchObserver = new window.IntersectionObserver(function (entries) {
        for (var i = 0; i < entries.length; i++) {
          if (entries[i].isIntersecting) {
            prefetchObserver.disconnect();
            loadPlotly().catch(function () { /* the figure says so when it draws */ });
            return;
          }
        }
      }, { rootMargin: '150% 0px' });
    }
    prefetchObserver.observe(target);
  }

  /* A box of zero size means the element is not rendered: display: none, a
     hidden section, a closed <details>. */
  function hasBox(el) {
    var rect = el.getBoundingClientRect();
    return rect.width > 0 || rect.height > 0;
  }

  /* Calls fn once, when target comes within NEAR pixels of the viewport.

     An element with no box is near nothing, so a figure in a hidden tab
     waits until the tab is shown -- which is also when it can be drawn at its
     real width. Plotly measures its container as it draws, and a figure drawn
     while hidden took Plotly's own 700px default and overflowed its column.

     IntersectionObserver does the watching and a scroll listener backs it up.
     The observer does not report in every environment -- some headless and
     embedded browsers never deliver an entry -- so if it has said nothing at
     all after three seconds, fn runs anyway: a figure that silently never
     appears is worse than one drawn early. A working observer reports every
     target once as soon as it starts watching, in view or not, so silence is
     the signal. In an ordinary browser this fallback never fires, and that is
     what keeps Plotly from being fetched for a figure nobody scrolls to. The
     old fallback drew every figure after three seconds regardless, which
     would have fetched Plotly on every chart page three seconds in. */
  var NEAR = 200;

  function whenNear(target, fn) {
    var done = false;
    var heard = false;
    var observer = null;

    function near() {
      if (!hasBox(target)) { return false; }
      var rect = target.getBoundingClientRect();
      var height = window.innerHeight || document.documentElement.clientHeight;
      return rect.top < height + NEAR && rect.bottom > -NEAR;
    }

    function onScroll() { if (near()) { finish(); } }

    function finish() {
      if (done) { return; }
      done = true;
      window.removeEventListener('scroll', onScroll);
      if (observer) { observer.disconnect(); }
      fn();
    }

    // Figures already on screen are drawn at once; deferring them only delays
    // what the reader came for.
    if (near() || !window.IntersectionObserver) { finish(); return; }

    observer = new window.IntersectionObserver(function (entries) {
      heard = true;
      entries.forEach(function (entry) { if (entry.isIntersecting) { finish(); } });
    }, { rootMargin: NEAR + 'px' });
    observer.observe(target);
    window.addEventListener('scroll', onScroll, { passive: true });

    (function fallback() {
      window.setTimeout(function () {
        if (done || heard) { return; }
        // A background tab is not rendered, so its observer cannot report
        // yet. Wait for the tab to be shown rather than draw unseen.
        if (document.visibilityState === 'hidden') {
          document.addEventListener('visibilitychange', function shown() {
            if (document.visibilityState === 'hidden') { return; }
            document.removeEventListener('visibilitychange', shown);
            fallback();
          });
          return;
        }
        finish();
      }, 3000);
    })();
  }

  /* A trace whose type this page's Plotly build does not carry is drawn as
     a plain scatter without a word from Plotly. See the note at loadPlotly. */
  function warnSubstitutedTypes(target, traces) {
    if (!window.console || !target._fullData) { return; }
    target._fullData.forEach(function (full) {
      var asked = traces[full.index] && traces[full.index].type;
      if (asked && full.type !== asked) {
        window.console.warn('MGDB.chart: ' + (target.id || 'a figure') + ' asked for "' + asked +
          '", which this page\'s Plotly build does not carry, and was drawn as "' + full.type +
          '". A page that needs it declares <meta name="mgdb-plotly" content="full">.');
      }
    });
  }

  /* Renders a Plotly figure into config.target, lazily.

     config:
       target      (required) element id or element
       traces      (required) array, or a function returning one
       layout      Plotly layout overrides, merged over BASE_LAYOUT
       config      Plotly config overrides
       fallback    message shown if Plotly cannot be loaded
       legendManual  keep the layout's own legend position instead of the
                     shared one above the plot; the figure then owns the
                     margins too

     Returns a promise for the drawn element, or for null if the figure could
     not be drawn. It stays pending until the figure comes into view, so
     anything that needs a drawn figure -- a relayout on resize, a click
     handler -- belongs in its then(), not after the call: Plotly is not on the
     page until the first figure is about to be drawn. Calling chart() again
     on the same element supersedes a draw still waiting.

     The element is expected to already contain a .mgdb-chart-fallback child and
     to carry role="img" plus an aria-label describing the chart. The visible
     text interpretation and data table live in the surrounding <figure>, so an
     assistive-technology user never depends on the canvas. */
  function chart(config) {
    if (!config || !config.target) { return Promise.resolve(null); }

    var target = typeof config.target === 'string' ? document.getElementById(config.target) : config.target;
    if (!target) { return Promise.resolve(null); }

    var UNAVAILABLE = 'This chart could not be displayed. The underlying values are listed in the data table below.';
    var generation = target.mgdbChartGeneration = (target.mgdbChartGeneration || 0) + 1;

    function fail(message) {
      var fallback = target.querySelector('.mgdb-chart-fallback');
      if (fallback) { fallback.textContent = message; }
      return null;
    }

    function render(Plotly) {
      var traces;
      try {
        traces = typeof config.traces === 'function' ? config.traces() : config.traces;
      } catch (error) {
        return fail(UNAVAILABLE);
      }

      if (!traces || !traces.length) {
        return fail('No data is available for this chart.');
      }

      var layout = placeLegend(mergeLayout(config.layout), traces, config.legendManual);
      var plotConfig = Object.assign({}, BASE_CONFIG, config.config || {});
      if (prefersReducedMotion()) { layout.transition = { duration: 0 }; }

      target.textContent = '';

      return Plotly.newPlot(target, traces, layout, plotConfig).then(function () {
        // Plotly's generated SVG is decorative here; role="img" plus the
        // aria-label on the container is what assistive technology reads.
        var svg = target.querySelector('.main-svg');
        if (svg) { svg.setAttribute('aria-hidden', 'true'); }
        warnSubstitutedTypes(target, traces);
        fitLegend(target, layout, 0);
        if (Plotly.Plots && Plotly.Plots.resize) {
          window.addEventListener('resize', debounce(function () {
            Plotly.Plots.resize(target);
            // A narrower figure wraps the legend onto more rows.
            fitLegend(target, layout, 0);
          }, 150));
        }
        return target;
      }, function () {
        return fail(UNAVAILABLE);
      });
    }

    var drawn = new Promise(function (resolve) {
      function draw(Plotly) {
        if (target.mgdbChartGeneration !== generation) { resolve(null); return; }
        // Hidden while Plotly was on its way -- a tab changed, a panel
        // closed. Wait until it can be seen, or it is drawn 700px wide.
        // Without an observer whenNear() cannot wait, so draw as before.
        if (!hasBox(target) && window.IntersectionObserver) {
          whenNear(target, function () { draw(Plotly); });
          return;
        }
        resolve(render(Plotly));
      }

      whenNear(target, function () {
        loadPlotly().then(draw, function () {
          resolve(target.mgdbChartGeneration === generation ? fail(config.fallback || UNAVAILABLE) : null);
        });
      });
    });

    prefetchPlotly(target);
    return drawn;
  }

  /* ------------------------------------------------------------------------
     Automatic wiring
     ------------------------------------------------------------------------ */

  /* ------------------------------------------------------------------------
     Where a tab jump lands, measured rather than declared

     A section anchored from the sticky tab bar has to clear it, and until now
     every page said how tall that bar was in CSS. Forty-odd page stylesheets
     carried a ladder of media queries for it, each measured once by hand, and
     the shell carried its own two-step ladder underneath them. Every one of
     those numbers is a claim that goes stale silently: the bar still looks
     right, the page still scrolls, only the landing position is wrong -- and a
     renamed tab, a longer label, a different font or a data-driven tab count
     invalidates it with nothing to show.

     So the bar is measured here and its height written to --mgdb-tab-offset,
     which mgdb-modern.css, mgdb-hub.css and mgdb-record.css all read back. 8px
     is the clearance those ladders already used (65 = 57 + 8, 113 = 105 + 8).

     Re-measured whenever the bar's own box changes -- a wrap at a new width, a
     web font arriving, page zoom, or a page script revealing another tab --
     rather than on window resize alone, which misses all but the first.

     A bar that is not sticky is not in the way, so those sections only want a
     little air: /genetic_variation drops the sticky position below 640px and
     had written that case into its ladder by hand as 16px.
     ------------------------------------------------------------------------ */

  var TAB_CLEARANCE = 8;

  function pageRootOf(el) {
    for (var node = el; node; node = node.parentElement) {
      if (node.classList && node.classList.contains('mgdb-page')) { return node; }
    }
    return null;
  }

  function syncTabOffset(bar) {
    bar = bar || document.querySelector('.mgdb-section-tabs');
    if (!bar) { return; }
    var page = pageRootOf(bar);
    if (!page) { return; }

    var sticky = window.getComputedStyle(bar).position === 'sticky';
    var height = bar.hasAttribute('hidden') ? 0 : bar.getBoundingClientRect().height;
    var offset = (sticky && height > 0) ? height + TAB_CLEARANCE : TAB_CLEARANCE * 2;
    page.style.setProperty('--mgdb-tab-offset', Math.round(offset) + 'px');
  }

  /* Whether the reader has done anything yet, tracked from the moment this file
     runs. Only their INPUT counts: the browser moves scrollY by itself for a
     fragment and again as content above the viewport settles, so reading scroll
     position would call that "the reader took over". */
  var userMoved = false;
  ['wheel', 'touchstart', 'keydown', 'mousedown'].forEach(function (evt) {
    window.addEventListener(evt, function () { userMoved = true; }, { passive: true, capture: true });
  });

  /* A page reached at #section -- /maize_history#history-classic-reads from the
     Community menu, /contribute_data#genomic from other pages -- is scrolled by
     the browser while it parses, using whatever scroll-margin is in force then.
     That is the CSS fallback, because the bar has not been measured yet. So the
     jump is re-applied once the real measure is in, and only while the reader
     has not touched anything. */
  function reapplyHash() {
    if (userMoved) { return; }
    var id = (window.location.hash || '').slice(1);
    if (!id) { return; }
    var target;
    try { target = document.getElementById(id); } catch (e) { return; }
    if (!target || target.hasAttribute('hidden') || !target.getBoundingClientRect().height) { return; }
    try { target.scrollIntoView({ block: 'start', behavior: 'auto' }); }
    catch (e) { target.scrollIntoView(true); }
  }

  function watchTabOffset(bar) {
    bar = bar || document.querySelector('.mgdb-section-tabs');
    if (!bar || bar.hasAttribute('data-offset-watched')) { syncTabOffset(bar); return; }
    bar.setAttribute('data-offset-watched', '');

    syncTabOffset(bar);
    reapplyHash();
    if (document.readyState !== 'complete') {
      window.addEventListener('load', function () {
        syncTabOffset(bar);
        window.setTimeout(reapplyHash, 0);
      });
    }
    if (window.ResizeObserver) {
      new window.ResizeObserver(function () { syncTabOffset(bar); }).observe(bar);
    }
    /* Kept even with a ResizeObserver: a bar can keep its height across a
       breakpoint that turns the sticky position off. */
    window.addEventListener('resize', debounce(function () { syncTabOffset(bar); }, 100));
    if (document.fonts && document.fonts.ready && document.fonts.ready.then) {
      document.fonts.ready.then(function () { syncTabOffset(bar); });
    }
  }

  /* ------------------------------------------------------------------------
     Typeahead

     Suggestions under a search field as the reader types, in the Expression
     Tools style (geneInput in js/mgdb-exptools-core.js): a plain list under
     the field, an identifier in mono, a name in bold, one muted line of
     context.

     Opt in with one attribute; the rest are optional:

       <input data-suggest="stock">         a scope of the suggestion index
         data-suggest-pick="navigate"       open the record instead of searching
         data-suggest-submit="#button"      what to press when there is no form
         data-suggest-anchor=".control"     align to this ancestor, not the field
         data-suggest-paused                no suggestions while present
         data-suggest-list                  a list box (a textarea of ids): suggest
                                            for the entry under the caret, and a
                                            pick replaces that entry and starts a
                                            new line instead of submitting

     or from a page script:

       MGDB.typeahead(input, { scope: 'gene', onPick: function (item, input) { … } });
       MGDB.typeahead(input, { source: function (qn) { return items; }, min: 1 });

     `source` answers from data the page already holds (a function of the
     normalised text, returning items or a promise of them) instead of the
     index; items have the index's shape: {v, id?, name?, text?, meta?, url?}.

     A pick puts the suggestion's value in the field and submits the form, so
     the page's own search runs exactly as though the reader had typed it. The
     index only suggests what that search finds (include/suggest_lib.php), which
     is what makes filling the field safe.

     Speed: an answer the page already holds is shown with no request -- the
     same text again, or longer text when the list for a shorter one was
     complete, narrowed here with the server's own rank (taKey). Anything else
     is asked for TA_DELAY after the last keystroke, cancels whatever is still
     in flight, and keeps the previous list up, dimmed, until it lands.
     ------------------------------------------------------------------------ */

  var TA_ENDPOINT = '/search/suggest/suggest_api.php';
  var TA_DELAY = 40;
  var TA_LIMIT = 10;
  var TA_MIN = 2;
  var TA_CACHE_MAX = 300;
  var taCache = {};
  var taCacheOrder = [];
  var taCount = 0;

  function taNorm(value) {
    return String(value == null ? '' : value).toLowerCase().replace(/\s+/g, ' ').trim();
  }

  /* Words of two or more characters, the first eight -- what suggestSearch()
     asks the full-text table for. Only used on ASCII text. */
  function taWords(qn) {
    var seen = {};
    var out = [];
    qn.split(/[^a-z0-9]+/).forEach(function (w) {
      if (w && !seen[w]) { seen[w] = true; out.push(w); }
    });
    return out.filter(function (w) { return w.length >= 2; }).slice(0, 8);
  }

  /* UTF-8 length, which is what PHP's strlen() gives the server's rank. */
  function taBytes(text) {
    var n = 0;
    for (var i = 0; i < text.length; i++) {
      var c = text.charCodeAt(i);
      n += c < 0x80 ? 1 : c < 0x800 ? 2 : (c >= 0xd800 && c <= 0xdbff) ? 2 : (c >= 0xdc00 && c <= 0xdfff) ? 2 : 3;
    }
    return n;
  }

  /* The rank, exactly as suggestKey() in include/suggest_lib.php. */
  function taKey(tier, kind, cls, len, rec) {
    var group = tier === 2 ? 2 : (kind >= 2 ? 1 : 0);
    var k = (group * 4 + tier) * 4 + kind;
    k = k * 16 + (15 - Math.max(0, Math.min(15, cls)));
    k = k * 1024 + Math.max(0, Math.min(1023, len));
    return k * 134217728 + rec;
  }

  function taStartsWith(text, prefix) {
    return text.lastIndexOf(prefix, 0) === 0;
  }

  /* The list for `qn`, from the complete list for a shorter text `entry.q`,
     or null when that cannot be done exactly. Every record `qn` matches is
     then among the entry's items: a term that starts with qn starts with the
     shorter text, and a record holding every word of qn holds every word of
     the shorter text -- provided each of those words is the start of one of
     qn's, which is checked. */
  function taNarrow(entry, qn, limit) {
    var words = entry.words ? taWords(qn) : [];
    if (words.length) {
      var before = taWords(entry.q);
      var covered = before.length && before.every(function (w) {
        return words.some(function (x) { return taStartsWith(x, w); });
      });
      if (!covered) { return null; }
    }
    var ranked = [];
    for (var i = 0; i < entry.items.length; i++) {
      var item = entry.items[i];
      if (item.x || !item.k) { return null; }
      var best = Infinity;
      var bestTerm = null;
      var bestKind = 3;
      for (var j = 0; j < item.k.length; j++) {
        var term = item.k[j][0];
        if (taStartsWith(term, qn)) {
          var key = taKey(term === qn ? 0 : 1, item.k[j][1], item.c, taBytes(term), item.n);
          if (key < best) { best = key; bestTerm = term; bestKind = item.k[j][1]; }
        }
      }
      if (words.length && item.w) {
        var have = item.w.split(' ');
        var all = words.every(function (w) {
          return have.some(function (h) { return taStartsWith(h, w); });
        });
        var wordKey = taKey(2, 3, 0, 1023, item.n);
        if (all && wordKey < best) { best = wordKey; bestTerm = null; bestKind = 3; }
      }
      if (best < Infinity) {
        /* Name the synonym it now matches on, as the server would. */
        var copy = {};
        Object.keys(item).forEach(function (key) { copy[key] = item[key]; });
        delete copy.match;
        if (bestTerm !== null && bestKind > 0 && bestTerm !== taNorm(item.v)) {
          copy.match = item.match && taNorm(item.match) === bestTerm ? item.match : bestTerm;
        }
        ranked.push({ key: best, item: copy });
      }
    }
    ranked.sort(function (a, b) { return a.key - b.key; });
    return ranked.slice(0, limit).map(function (r) { return r.item; });
  }

  /* The muted line: the synonym a record was matched on when the reader
     would not otherwise see it, the record's context, and how many records
     one value stands for. */
  function taMetaLine(item) {
    var parts = [];
    /* A merged list (scope=all) says what kind of record each row is. */
    if (item.type) { parts.push(item.type); }
    if (item.match) {
      var seen = [item.v, item.name, item.text, item.meta].join(' ').toLowerCase();
      if (seen.indexOf(String(item.match).toLowerCase()) === -1) { parts.push(item.match); }
    }
    if (item.meta) { parts.push(item.meta); }
    if (item.dups > 1) { parts.push(item.dups + ' records'); }
    return parts.join(' \u00b7 ');
  }

  /* What the reader sees as the search box. Several hubs draw the border on
     a wrapper that also holds a magnifier or a clear button, with a bare
     input inside it; the list lines up with that wrapper, not the input. */
  function taBox(input) {
    function bordered(el) {
      var style = window.getComputedStyle(el);
      return parseFloat(style.borderTopWidth) > 0 && style.borderTopStyle !== 'none' &&
             parseFloat(style.borderLeftWidth) > 0;
    }
    if (bordered(input)) { return null; }
    var el = input.parentElement;
    for (var depth = 0; el && depth < 2; depth++, el = el.parentElement) {
      /* A single-line control: an input can be 19px of text inside a 44px box.
         A textarea's box is as tall as the textarea, give or take padding. */
      var fits = input.tagName === 'TEXTAREA' ? el.offsetHeight <= input.offsetHeight + 32
                                              : el.offsetHeight <= Math.max(input.offsetHeight + 16, 72);
      if (bordered(el) && fits) { return el; }
    }
    return null;
  }

  function taCacheGet(key) {
    return Object.prototype.hasOwnProperty.call(taCache, key) ? taCache[key] : null;
  }

  function taCachePut(key, entry) {
    if (!Object.prototype.hasOwnProperty.call(taCache, key)) {
      taCacheOrder.push(key);
      if (taCacheOrder.length > TA_CACHE_MAX) { delete taCache[taCacheOrder.shift()]; }
    }
    taCache[key] = entry;
  }

  function typeahead(input, options) {
    options = options || {};
    if (!input || input.getAttribute('data-typeahead') === 'on') { return null; }
    var scope = options.scope || input.getAttribute('data-suggest');
    var source = typeof options.source === 'function' ? options.source : null;
    if (!source && (!scope || !window.fetch)) { return null; }
    input.setAttribute('data-typeahead', 'on');

    var limit = options.limit || TA_LIMIT;
    var minChars = options.min || TA_MIN;
    var listMode = !!options.list || input.hasAttribute('data-suggest-list');
    /* The separators a list box splits entries on (insSplitList()). */
    var ENTRY_END = /[^\s,;|]*$/;
    var mode = options.pick || input.getAttribute('data-suggest-pick') || 'submit';
    var submitSelector = options.submit || input.getAttribute('data-suggest-submit');
    var anchorSelector = options.anchor || input.getAttribute('data-suggest-anchor');
    var anchor = anchorSelector && input.closest ? input.closest(anchorSelector) : taBox(input);

    var id = 'mgdb-typeahead-' + (++taCount);
    var list = document.createElement('ul');
    list.id = id;
    list.className = 'mgdb-typeahead-list';
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    var labelText = input.getAttribute('aria-label') ||
      (input.labels && input.labels[0] ? input.labels[0].textContent.replace(/\s+/g, ' ').trim() : '');
    list.setAttribute('aria-label', labelText ? 'Suggestions: ' + labelText : 'Suggestions');
    document.body.appendChild(list);

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', id);
    input.setAttribute('autocomplete', 'off');

    var items = [];
    var shownFor = null;
    var active = -1;
    var timer = null;
    var controller = null;
    var serial = 0;
    /* Text the reader closed the list on, by Escape, Enter, a pick or leaving
       the field. An answer for it that lands later must not reopen the list;
       typing anything clears it. */
    var dismissed = null;
    var listening = false;

    /* A pick that fills the field and searches makes rows with the same value
       one choice, so the server keeps one per value. */
    var distinct = mode !== 'navigate' && !options.onPick;

    function cacheKey(qn) { return scope + '|' + limit + '|' + (distinct ? 'd' : '') + '|' + qn; }

    /* What is being completed: the whole field, or in a list box the entry
       from the last separator up to the caret. */
    function currentText() {
      if (!listMode) { return input.value; }
      var caret = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
      var match = input.value.slice(0, caret).match(ENTRY_END);
      return match ? match[0] : '';
    }

    function abort() {
      window.clearTimeout(timer);
      serial++;
      if (controller) { controller.abort(); controller = null; }
      input.removeAttribute('aria-busy');
    }

    /* As wide as the search box -- but never narrower than 20rem where the
       screen allows it, since on a phone a box beside its button can be 120px
       and every row would wrap to three lines. Kept 16px inside the viewport. */
    function place() {
      var box = (anchor || input).getBoundingClientRect();
      var viewport = document.documentElement.clientWidth || window.innerWidth;
      var width = Math.max(box.width, Math.min(320, viewport - 32));
      var left = Math.max(16, Math.min(box.left, viewport - 16 - width));
      if (width >= viewport - 32) { left = 16; width = viewport - 32; }
      if (box.width >= width) { left = box.left; width = box.width; }
      list.style.left = (left + window.pageXOffset) + 'px';
      list.style.top = (box.bottom + window.pageYOffset + 4) + 'px';
      list.style.width = width + 'px';
    }

    function onMove() { if (!list.hidden) { place(); } }

    function listen(on) {
      if (on === listening) { return; }
      listening = on;
      var method = on ? 'addEventListener' : 'removeEventListener';
      window[method]('resize', onMove);
      window[method]('scroll', onMove, true);
    }

    function close() {
      list.hidden = true;
      list.classList.remove('is-stale');
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      active = -1;
      listen(false);
    }

    function setActive(index) {
      active = index;
      Array.prototype.forEach.call(list.children, function (li, i) {
        var on = i === index;
        li.classList.toggle('is-active', on);
        li.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      if (index >= 0 && list.children[index]) {
        input.setAttribute('aria-activedescendant', list.children[index].id);
        var li = list.children[index];
        if (li.offsetTop < list.scrollTop) { list.scrollTop = li.offsetTop; }
        else if (li.offsetTop + li.offsetHeight > list.scrollTop + list.clientHeight) {
          list.scrollTop = li.offsetTop + li.offsetHeight - list.clientHeight;
        }
      } else {
        input.removeAttribute('aria-activedescendant');
      }
    }

    function render(newItems, qn) {
      /* Keep the reader's place when a list is replaced under the arrow keys. */
      var keep = active >= 0 && items[active] && items[active].n != null ? items[active].n : null;
      items = newItems;
      shownFor = qn;
      list.classList.remove('is-stale');
      list.innerHTML = '';
      if (!items.length) { close(); return; }
      var restore = -1;
      items.forEach(function (item, i) {
        var li = document.createElement('li');
        li.id = id + '-' + i;
        li.className = 'mgdb-typeahead-item';
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        var metaLine = taMetaLine(item);
        /* Joined by spaces: the flex row ignores them, but the option's
           accessible name is its text, and "Ki11Ames 27124" is one word. */
        li.innerHTML = [
          item.id ? '<span class="mgdb-typeahead-id">' + escapeHtml(item.id) + '</span>' : '',
          item.name ? '<strong>' + escapeHtml(item.name) + '</strong>' : '',
          item.text ? '<span class="mgdb-typeahead-text">' + escapeHtml(item.text) + '</span>' : '',
          metaLine ? '<span class="mgdb-typeahead-meta">' + escapeHtml(metaLine) + '</span>' : ''
        ].filter(Boolean).join(' ');
        li.addEventListener('mousedown', function (e) { e.preventDefault(); pick(i); });
        list.appendChild(li);
        if (keep !== null && item.n === keep) { restore = i; }
      });
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      place();
      listen(true);
      setActive(restore);
    }

    function show(entry, qn) {
      if (dismissed === qn) { list.classList.remove('is-stale'); return; }
      render(entry.items, qn);
    }

    function fromCache(qn) {
      var hit = taCacheGet(cacheKey(qn));
      if (hit) { return hit; }
      if (/[^\x20-\x7e]/.test(qn)) { return null; }
      for (var n = qn.length - 1; n >= TA_MIN; n--) {
        var entry = taCacheGet(cacheKey(qn.slice(0, n)));
        if (entry && entry.complete) {
          var narrowed = taNarrow(entry, qn, limit);
          if (!narrowed) { return null; }
          var made = { q: qn, items: narrowed, complete: true, words: entry.words };
          taCachePut(cacheKey(qn), made);
          return made;
        }
      }
      return null;
    }

    function request(qn) {
      abort();
      var mine = serial;
      controller = window.AbortController ? new window.AbortController() : null;
      input.setAttribute('aria-busy', 'true');
      var url = TA_ENDPOINT + '?scope=' + encodeURIComponent(scope) + '&q=' + encodeURIComponent(qn) +
        (limit !== TA_LIMIT ? '&limit=' + limit : '') + (distinct ? '&distinct=1' : '');
      window.fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        signal: controller ? controller.signal : undefined
      }).then(function (response) {
        if (!response.ok) { throw new Error('status ' + response.status); }
        return response.json();
      }).then(function (data) {
        if (!data || !data.ok) { throw new Error('no suggestions'); }
        var entry = { q: qn, items: data.items || [], complete: !!data.complete, words: !!data.words };
        taCachePut(cacheKey(qn), entry);
        if (mine !== serial) { return; }
        controller = null;
        input.removeAttribute('aria-busy');
        if (taNorm(currentText()) === qn) { show(entry, qn); }
      }).catch(function (error) {
        if (mine !== serial || (error && error.name === 'AbortError')) { return; }
        /* Suggestions are a convenience: the search itself still works. */
        controller = null;
        input.removeAttribute('aria-busy');
        close();
      });
    }

    function update() {
      var qn = taNorm(currentText());
      window.clearTimeout(timer);
      /* data-suggest-paused: the field is taking something the index does not
         hold right now -- a DNA sequence, say. */
      if (qn.length < minChars || input.hasAttribute('data-suggest-paused')) {
        abort(); close(); items = []; shownFor = null; return;
      }
      if (source) {
        abort();
        var mine = serial;
        Promise.resolve(source(qn, input.value)).then(function (list) {
          if (mine !== serial || taNorm(currentText()) !== qn) { return; }
          /* Rows with one value are one choice when a pick fills the field,
             exactly as the index sends them. */
          var seen = {};
          var rows = [];
          (list || []).forEach(function (item) {
            var key = taNorm(item.v);
            if (distinct && Object.prototype.hasOwnProperty.call(seen, key)) {
              rows[seen[key]].dups = (rows[seen[key]].dups || 1) + 1;
              return;
            }
            seen[key] = rows.length;
            rows.push(Object.assign({}, item));
          });
          show({ items: rows.slice(0, limit) }, qn);
        }, function () { close(); });
        return;
      }
      var hit = fromCache(qn);
      if (hit) { abort(); show(hit, qn); return; }
      if (!list.hidden) { list.classList.add('is-stale'); }
      timer = window.setTimeout(function () { request(qn); }, TA_DELAY);
    }

    function submit() {
      var button = submitSelector ? document.querySelector(submitSelector) : null;
      if (button) { button.click(); return; }
      var form = input.form;
      if (!form) { return; }
      if (typeof form.requestSubmit === 'function') { form.requestSubmit(); return; }
      var event = document.createEvent('Event');
      event.initEvent('submit', true, true);
      if (form.dispatchEvent(event)) { form.submit(); }
    }

    function pick(index) {
      var item = items[index];
      if (!item) { return; }
      abort();
      close();
      if (listMode) {
        /* Replace the entry being typed, start the next one on a new line,
           and leave the search to the reader: a list is rarely one entry. */
        var caret = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
        var before = input.value.slice(0, caret);
        var after = input.value.slice(caret).replace(/^[^\s,;|]*/, '');
        before = before.slice(0, before.length - (before.match(ENTRY_END) || [''])[0].length) + item.v;
        var joiner = /^[\s,;|]/.test(after) ? '' : '\n';
        input.value = before + joiner + after;
        var at = before.length + joiner.length;
        if (input.setSelectionRange) { input.setSelectionRange(at, at); }
        dismissed = null;
        try { input.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) { /* old browsers */ }
        input.focus();
        return;
      }
      input.value = item.v;
      dismissed = taNorm(item.v);
      try { input.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) { /* old browsers */ }
      if (options.onPick) { options.onPick(item, input); return; }
      if (mode === 'navigate' && item.url) { window.location.assign(item.url); return; }
      submit();
    }

    input.addEventListener('input', function () {
      dismissed = null;
      update();
    });

    input.addEventListener('keydown', function (e) {
      var open = !list.hidden && items.length > 0;
      var key = e.key;
      if (key === 'ArrowDown' || key === 'Down') {
        if (!open) {
          if (items.length && shownFor === taNorm(currentText())) {
            dismissed = null;
            render(items, shownFor);
            setActive(0);
          }
          e.preventDefault();
          return;
        }
        setActive(active + 1 >= items.length ? 0 : active + 1);
        e.preventDefault();
      } else if (key === 'ArrowUp' || key === 'Up') {
        if (!open) { return; }
        setActive(active <= 0 ? items.length - 1 : active - 1);
        e.preventDefault();
      } else if (key === 'Enter') {
        if (open && active >= 0) {
          /* The pick submits the search itself; a page's own Enter handler
             must not run a second one on the same keystroke. */
          e.preventDefault();
          e.stopImmediatePropagation();
          pick(active);
          return;
        }
        /* The reader's own text is being searched; the form does that. */
        dismissed = taNorm(currentText());
        abort();
        close();
      } else if (key === 'Escape' || key === 'Esc') {
        if (open) {
          /* Closes the list only: a search field's own Escape clears it. */
          e.preventDefault();
          e.stopImmediatePropagation();
          dismissed = taNorm(currentText());
          abort();
          close();
        }
      } else if (key === 'Tab') {
        close();
      }
    });

    input.addEventListener('blur', function () {
      window.setTimeout(function () {
        if (document.activeElement === input) { return; }
        dismissed = taNorm(currentText());
        abort();
        close();
      }, 150);
    });

    /* A form reset or a script clearing the field leaves no text to suggest for. */
    if (input.form) {
      input.form.addEventListener('reset', function () { abort(); close(); });
    }

    return {
      input: input,
      list: list,
      close: function () { abort(); close(); },
      refresh: function () { dismissed = null; update(); }
    };
  }

  function initTypeaheads(root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('input[data-suggest], textarea[data-suggest]'), function (input) {
      typeahead(input);
    });
  }

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

    /* Not inside sectionTabs(): a page can carry the bar without opting into
       the scrollspy, and the offset is the bar's business either way. */
    watchTabOffset();

    initCopyButtons();

    initTypeaheads();
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
  MGDB.watchTabOffset = watchTabOffset;
  MGDB.syncTabOffset = syncTabOffset;
  MGDB.chart = chart;
  MGDB.loadPlotly = loadPlotly;
  MGDB.whenNear = whenNear;
  MGDB.mergeLayout = mergeLayout;
  MGDB.CHART_COLORS = CHART_COLORS;
  MGDB.CHART_SYMBOLS = CHART_SYMBOLS;
  MGDB.CHART_DASHES = CHART_DASHES;
  MGDB.prefersReducedMotion = prefersReducedMotion;
  MGDB.initCopyButtons = initCopyButtons;
  MGDB.typeahead = typeahead;
  MGDB.initTypeaheads = initTypeaheads;

  window.MGDB = MGDB;
})(window, document);
