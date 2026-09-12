/**
 * file: js/mgdb-assembly.js
 *
 * purpose: the three assembly-improvement figures on /assembly.
 *
 * The scrollspy this file used to carry is gone: MGDB.sectionTabs() is the
 * shared one, and it measures the bar's own height into --mgdb-tab-offset
 * rather than guessing a rootMargin, so clicking a tab cannot land a section
 * behind the bar. The copy-to-clipboard handler is gone too -- the reference
 * cards come from references_lib.php now and mgdb-modern.js already binds
 * their Copy citation / Copy DOI buttons.
 *
 * Data: the five B73 rows of data/genome/genome_assembly_stats_demo.json, cut
 * down by the controller and handed over on .assembly-charts[data-series].
 * The page never fetches the 539 KB file.
 */

(function () {
  'use strict';

  var COLORS = (window.MGDB && window.MGDB.CHART_COLORS) || ['#D55E00', '#0072B2', '#E69F00'];

  function byId(id) { return document.getElementById(id); }

  /* Releases on the x axis read "v5" over "2020": the version is what every
     other surface on this page calls them, and the year is what makes the
     spacing mean something. Plotly pins a category axis on first draw, so
     these strings are the categories for the life of the figure. */
  function xLabels(series) {
    return series.map(function (d) { return d.label + '<br>' + d.year; });
  }

  function values(series, field) {
    return series.map(function (d) {
      var v = d[field];
      // null in the source file means "not measured", never zero. Plotly
      // breaks the line at null, which is the honest mark for it.
      return (v === null || v === undefined) ? null : Number(v);
    });
  }

  function hasAny(list) {
    return list.some(function (v) { return v !== null; });
  }

  /* A log axis wants its ticks at the decades, not at Plotly's defaults --
     it labels 4.3 and 5.1 otherwise. tickvals on a log axis are in DATA space,
     not in exponents: [4, 5, 6] means the values four, five and six, which sit
     below every range here and draw no ticks at all. */
  function decades(lo, hi) {
    var vals = [];
    for (var e = lo; e <= hi; e++) { vals.push(Math.pow(10, e)); }
    return vals;
  }

  /* Two label sets per axis, because `automargin` sizes the gutter to whatever
     the tick text and the axis title need. At 375px "100,000" plus a rotated
     "Count" took 103px of a 259px figure and left the plot 156px wide. The
     narrow set drops the title -- the figure's own h3 and figcaption already
     say what is being measured -- and shortens every label.

     The narrow set has to shorten the LONGEST member to be worth anything:
     "1,000,000" is the one that sets the gutter, not "1". */
  function lengthTicks(lo, hi, narrow) {
    var unit = narrow ? ['', 'k', 'M', 'G'] : [' bp', ' kb', ' Mb', ' Gb'];
    var text = [];
    for (var e = lo; e <= hi; e++) {
      var step = Math.floor(e / 3);
      text.push(String(Math.pow(10, e - step * 3)) + unit[step]);
    }
    return text;
  }

  function countTicks(narrow) {
    return narrow ? ['1', '10', '100', '1k', '10k', '100k', '1M']
                  : ['1', '10', '100', '1,000', '10,000', '100,000', '1,000,000'];
  }

  function logAxis(title, lo, hi, narrow) {
    return {
      type: 'log', title: { text: narrow ? '' : title },
      tickmode: 'array', tickvals: decades(lo, hi), ticktext: lengthTicks(lo, hi, narrow),
      automargin: true, gridcolor: '#e6eaf2'
    };
  }

  function countAxis(title, narrow) {
    return {
      type: 'log', title: { text: narrow ? '' : title },
      tickmode: 'array', tickvals: decades(0, 6), ticktext: countTicks(narrow),
      automargin: true, gridcolor: '#e6eaf2'
    };
  }

  function pctAxis(title, narrow) {
    return {
      title: { text: narrow ? '' : title },
      range: [80, 100], ticksuffix: '%',
      automargin: true, gridcolor: '#e6eaf2'
    };
  }

  /* Margins are sized from the figure's measured width, not written as
     constants: MGDB.chart re-runs Plotly.Plots.resize on a window resize,
     which rescales the plot but keeps the margins it was drawn with, so a
     desktop gutter would survive onto a phone and squeeze the plot flat.

     `automargin` on the y axis only ever grows these, so the narrow left
     margin is 8 and the axis's own short tick text decides the rest. */
  function metrics(el) {
    var w = el ? el.getBoundingClientRect().width : 0;
    var narrow = w > 0 && w < 560;
    return {
      narrow: narrow,
      margin: narrow ? { l: 8, r: 14, t: 8, b: 52 } : { l: 92, r: 28, t: 8, b: 56 }
    };
  }

  function line(name, x, y, color, hover) {
    return {
      type: 'scatter', mode: 'lines+markers', name: name,
      x: x, y: y, connectgaps: false,
      line: { color: color, width: 2.5 },
      marker: { color: color, size: 8, line: { color: '#fff', width: 1.5 } },
      hovertemplate: '%{x}<br>' + name + ': ' + hover + '<extra></extra>'
    };
  }

  /* .mgdb-chart is a fixed 320px in the shared sheet. The wide figure needs
     more, and the element height and the Plotly height have to come from one
     value or the figure draws into a box the wrong size. */
  function sizeChart(id, h) {
    var el = byId(id);
    if (el) { el.style.height = h + 'px'; }
    return h;
  }

  /* One description per figure. The resize handler rebuilds the y axis from
     the same function the first draw used, so the narrow and wide forms cannot
     drift apart. */
  function figureSpecs(series) {
    var x = xLabels(series);
    return [
      { id: 'assembly-contiguity-chart', height: 400,
        traces: [
          line('Scaffold N50',   x, values(series, 'scaffold_N50'),   COLORS[1], '%{y:.3s}b'),
          line('Largest contig', x, values(series, 'largest_contig'), COLORS[2], '%{y:.3s}b'),
          line('Contig N50',     x, values(series, 'contig_N50'),     COLORS[0], '%{y:.3s}b')
        ],
        yaxis: function (narrow) { return logAxis('Length', 4, 9, narrow); } },

      { id: 'assembly-fragments-chart', height: 320,
        traces: [
          line('Contigs', x, values(series, 'n_contigs'), COLORS[0], '%{y:,d}'),
          line('Gaps',    x, values(series, 'n_gaps'),    COLORS[3] || COLORS[1], '%{y:,d}')
        ],
        yaxis: function (narrow) { return countAxis('Count', narrow); } },

      /* A linear axis held to 80-100. The whole range of interest is the top
         fifth of the scale, and starting at zero would draw five flat lines. */
      { id: 'assembly-completeness-chart', height: 320,
        traces: [
          line('Genome (compleasm)', x, values(series, 'compleasm_complete_pct'),     COLORS[0], '%{y:.2f}%'),
          line('Proteins (BUSCO)',   x, values(series, 'busco_protein_complete_pct'), COLORS[1], '%{y:.2f}%')
        ],
        yaxis: function (narrow) { return pctAxis('Complete single-copy orthologs', narrow); } }
    ];
  }

  function buildCharts(series) {
    if (!window.MGDB || typeof window.MGDB.chart !== 'function') { return; }

    var specs = figureSpecs(series);

    specs.forEach(function (spec) {
      if (!spec.traces.some(function (t) { return hasAny(t.y); })) { return; }

      var el = byId(spec.id);
      if (!el) { return; }

      var h = sizeChart(spec.id, spec.height);
      var m = metrics(el);
      spec.narrow = m.narrow;

      window.MGDB.chart({
        target: spec.id,
        traces: spec.traces,
        layout: {
          height: h,
          xaxis: { type: 'category', automargin: true },
          yaxis: spec.yaxis(m.narrow),
          margin: m.margin
        }
      });
    });

    /* MGDB.chart re-runs Plotly.Plots.resize on a resize, which rescales the
       figure but keeps the axes and margins it was drawn with. Crossing the
       breakpoint has to be handled here, or a figure opened on a desktop and
       narrowed keeps the wide gutter. */
    window.addEventListener('resize', debounce(function () {
      if (!window.Plotly) { return; }
      specs.forEach(function (spec) {
        var el = byId(spec.id);
        if (!el || !el.layout) { return; }
        var m = metrics(el);
        if (m.narrow === spec.narrow) { return; }
        spec.narrow = m.narrow;
        window.Plotly.relayout(el, { yaxis: spec.yaxis(m.narrow), margin: m.margin });
      });
    }, 180));
  }

  function debounce(fn, wait) {
    var t;
    return function () {
      var args = arguments, self = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(self, args); }, wait);
    };
  }

  function init() {
    // The shared scrollspy: it writes --mgdb-tab-offset from the bar's own
    // measured height, which is what the sections' scroll-margin reads.
    if (window.MGDB && typeof window.MGDB.sectionTabs === 'function') {
      window.MGDB.sectionTabs();
    }

    var host = document.querySelector('.assembly-charts');
    if (!host) { return; }

    var series;
    try {
      series = JSON.parse(host.getAttribute('data-series') || '[]');
    } catch (error) {
      return;                     // the fallback text and the table stand
    }
    if (!series.length) { return; }

    buildCharts(series);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
