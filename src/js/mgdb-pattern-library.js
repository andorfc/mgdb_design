/* Page behavior for the design system pattern library.
   Demonstrates the shared MGDB helpers; not used by production pages. */

(function () {
  'use strict';

  function byId(id) { return document.getElementById(id); }

  function init() {
    if (!window.MGDB) { return; }

    /* Search + filter + live count, with state mirrored into the URL. */
    var list = window.MGDB.filterList({
      items: document.querySelectorAll('#pattern-results > .mgdb-card'),
      input: byId('pattern-query'),
      chips: document.querySelectorAll('.mgdb-chip[data-filter]'),
      count: byId('pattern-count'),
      empty: byId('pattern-empty'),
      reset: byId('pattern-reset'),
      noun: 'assemblies',
      urlKeys: { query: 'q', filter: 'type' }
    });

    var emptyReset = byId('pattern-empty-reset');
    if (emptyReset) {
      emptyReset.addEventListener('click', function () {
        var reset = byId('pattern-reset');
        if (reset) { reset.click(); }
      });
    }
    void list;

    /* Bar chart. Assembly length in megabases. */
    window.MGDB.chart({
      target: 'pattern-chart-bar',
      traces: function () {
        return [{
          type: 'bar',
          x: ['B73', 'Mo17', 'CML247', 'TIL11'],
          y: [2178.3, 2183.0, 2143.1, 1683.9],
          marker: {
            color: window.MGDB.CHART_COLORS.slice(0, 4),
            line: { color: '#1f2723', width: 1 }
          },
          hovertemplate: '%{x}: %{y:,.1f} Mb<extra></extra>'
        }];
      },
      layout: {
        xaxis: { title: { text: 'Genotype' } },
        yaxis: { title: { text: 'Assembled length (Mb)' }, rangemode: 'tozero' },
        showlegend: false,
        margin: { l: 72, r: 20, t: 12, b: 56 }
      }
    });

    /* Line chart. Marker symbol and dash vary alongside color so the series
       remain distinguishable without color vision. */
    window.MGDB.chart({
      target: 'pattern-chart-line',
      traces: function () {
        return [{
          type: 'scatter',
          mode: 'lines+markers',
          name: 'Gene models',
          x: [2009, 2015, 2017, 2020, 2025],
          y: [32540, 39324, 39498, 39756, 39756],
          line: { color: window.MGDB.CHART_COLORS[1], width: 2, dash: 'solid' },
          marker: { symbol: window.MGDB.CHART_SYMBOLS[0], size: 9 },
          hovertemplate: '%{x}: %{y:,} gene models<extra></extra>'
        }];
      },
      layout: {
        xaxis: { title: { text: 'Annotation release year' }, dtick: 4 },
        yaxis: { title: { text: 'Annotated gene models' }, rangemode: 'tozero' },
        showlegend: false,
        margin: { l: 78, r: 20, t: 12, b: 56 }
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
