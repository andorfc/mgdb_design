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

      var message = visible === items.length
        ? String(items.length) + ' ' + noun + ' shown'
        : String(visible) + ' of ' + items.length + ' ' + noun + ' shown';

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
    legend: { orientation: 'h', y: -0.22, x: 0, font: { size: 13 } },
    xaxis: { gridcolor: '#ece7dd', zerolinecolor: '#dcd8cf', automargin: true },
    yaxis: { gridcolor: '#ece7dd', zerolinecolor: '#dcd8cf', automargin: true }
  };

  var BASE_CONFIG = {
    displayModeBar: false,
    responsive: true,
    // Locks the chart to the page's own scroll behavior rather than zooming.
    scrollZoom: false
  };

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

      var layout = mergeLayout(config.layout);
      var plotConfig = Object.assign({}, BASE_CONFIG, config.config || {});
      if (prefersReducedMotion()) { layout.transition = { duration: 0 }; }

      target.textContent = '';

      window.Plotly.newPlot(target, traces, layout, plotConfig).then(function () {
        // Plotly's generated SVG is decorative here; role="img" plus the
        // aria-label on the container is what assistive technology reads.
        var svg = target.querySelector('.main-svg');
        if (svg) { svg.setAttribute('aria-hidden', 'true'); }
        if (window.Plotly.Plots && window.Plotly.Plots.resize) {
          window.addEventListener('resize', debounce(function () {
            window.Plotly.Plots.resize(target);
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
  MGDB.chart = chart;
  MGDB.mergeLayout = mergeLayout;
  MGDB.CHART_COLORS = CHART_COLORS;
  MGDB.CHART_SYMBOLS = CHART_SYMBOLS;
  MGDB.CHART_DASHES = CHART_DASHES;
  MGDB.prefersReducedMotion = prefersReducedMotion;

  window.MGDB = MGDB;
})(window, document);
