/* mgdb-ssr-reports.js -- /ssrreports
 *
 * Both reports are in the document, rendered server side, so filtering and
 * sorting are local passes rather than requests and the browser's own find
 * works across the whole report. What this file adds is the two filters, the
 * section-tab scrollspy, and the chromosome figure.
 *
 * Sorting is not here: the tables carry `data-sortable`, which mgdb-modern.js
 * picks up on its own.
 *
 * Bauplan::includeScript emits into <head>, so nothing below may touch the
 * document until it has been parsed.
 */
(function () {
  'use strict';

  function byId(id) { return document.getElementById(id); }

  function esc(value) {
    if (!value && value !== 0) { return ''; }
    return String(value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function number(n) { return Number(n || 0).toLocaleString(); }

  /* ── Filters ───────────────────────────────────────────────────────────── */

  /* One report's filter. MGDB.filterList owns the matching, the count, the
     empty state, the reset button and the URL sync; the chips are optional
     because only the gene-derived report has a facet worth offering. */
  function initFilter(config) {
    var body = byId(config.body);
    if (!body || !window.MGDB || !window.MGDB.filterList) { return; }

    var chips = config.chips ? document.querySelectorAll('#' + config.chips + ' [data-filter]') : null;

    window.MGDB.filterList({
      items: body.rows,
      input: byId(config.input),
      chips: chips && chips.length ? chips : null,
      count: byId(config.count),
      empty: byId(config.empty),
      reset: byId(config.reset),
      noun: config.noun,
      urlKeys: config.urlKeys,
      onChange: config.onChange
    });
  }

  /* ── Section tabs ──────────────────────────────────────────────────────── */

  /* The trigger line is read back from the section's own scroll-margin-top, so
     the line the spy marks a section at and the offset a click parks it at
     agree by construction. A hardcoded value disagrees with the stylesheet the
     moment the bar wraps, and then clicking a tab marks the section above the
     one it jumped to. */
  function buildTabs() {
    var noop = function () {};
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length) { return noop; }

    var pairs = [];
    Array.prototype.forEach.call(tabs, function (tab) {
      var href = tab.getAttribute('href') || '';
      if (href.charAt(0) !== '#') { return; }
      var section = document.getElementById(href.slice(1));
      if (section) { pairs.push({ tab: tab, section: section }); }
    });
    if (!pairs.length) { return noop; }

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
      /* A clicked tab is held until the reader's own scroll releases it, or
         the smooth scroll on the way to the section marks every section it
         passes through. */
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
      /* Without a bottom-of-page case the last section never highlights: it is
         shorter than the viewport, so its top never reaches the line. */
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

    /* A filter can shorten a table by hundreds of rows, which moves every
       section below it, and nothing else tells the spy that happened. A
       MutationObserver would see it, but filterList writes `hidden` on every
       row it touches, so watching the tbody means allocating a couple of
       thousand MutationRecords per keystroke to learn one fact. The filters
       call this instead. */
    return update;
  }

  /* ── Gene-derived SSRs by chromosome ───────────────────────────────────── */

  /* The tally is rendered server side into #ssrr-chart-data by the same pass
     that builds the chromosome chips, so a chip's count and a bar's height
     cannot disagree, and the figure draws without a request of its own. */
  function initFigure() {
    var source = byId('ssrr-chart-data');
    if (!source) { return; }

    var data;
    try { data = JSON.parse(source.textContent || 'null'); }
    catch (error) { return; }
    if (!data || !data.bars || !data.bars.length) { return; }

    var bars = data.bars;

    var table = byId('ssrr-chrom-table');
    if (table && table.tBodies[0]) {
      table.tBodies[0].innerHTML = bars.map(function (bar) {
        return '<tr><td>' + esc(bar.label) + '</td>'
             + '<td class="mgdb-numeric">' + number(bar.count) + '</td></tr>';
      }).join('');
    }

    var el = byId('ssrr-chrom-chart');
    if (!el || !window.MGDB || !window.MGDB.chart) { return; }

    /* .mgdb-chart is a fixed 320px in the shared sheet, so layout.height alone
       does nothing. One variable feeds both the element and the layout. */
    var height = 360;
    el.style.height = height + 'px';

    /* Margins are sized from the figure rather than fixed. MGDB.chart re-runs
       Plotly.Plots.resize on a window resize, which rescales the figure but
       keeps the margins it was drawn with, so a desktop gutter would survive
       onto a phone. */
    var NARROW = 560;
    function metrics() {
      var width = el.getBoundingClientRect().width;
      var narrow = width > 0 && width < NARROW;
      return {
        narrow: narrow,
        margin: narrow ? { l: 52, r: 12, t: 8, b: 52 } : { l: 78, r: 24, t: 20, b: 56 },
        nticks: narrow ? 4 : 0
      };
    }
    var m = metrics();

    window.MGDB.chart({
      target: el,
      traces: [{
        type: 'bar',
        x: bars.map(function (bar) { return bar.short; }),
        y: bars.map(function (bar) { return bar.count; }),
        marker: { color: '#285d46' },
        text: bars.map(function (bar) { return m.narrow ? '' : number(bar.count); }),
        textposition: m.narrow ? 'none' : 'outside',
        cliponaxis: false,
        hovertemplate: 'Chromosome %{x}<br>%{y:,} marker-locus pairs<extra></extra>'
      }],
      layout: {
        height: height,
        showlegend: false,
        margin: m.margin,
        xaxis: { title: 'Chromosome', type: 'category' },
        yaxis: { title: 'Marker-locus pairs', zeroline: false, tickformat: ',d', nticks: m.nticks }
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
          window.Plotly.relayout(el, { margin: next.margin, 'yaxis.nticks': next.nticks });
          window.Plotly.restyle(el, { textposition: next.narrow ? 'none' : 'outside' });
        }, 180);
      });
    }
  }

  function init() {
    var sectionsMoved = buildTabs();

    initFilter({
      body: 'ssrr-repeat-body',
      input: 'ssrr-repeat-filter',
      count: 'ssrr-repeat-count',
      empty: 'ssrr-repeat-empty',
      reset: 'ssrr-repeat-reset',
      noun: 'records',
      urlKeys: { query: 'motif' },
      onChange: sectionsMoved
    });

    initFilter({
      body: 'ssrr-gene-body',
      input: 'ssrr-gene-filter',
      chips: 'ssrr-gene-chips',
      count: 'ssrr-gene-count',
      empty: 'ssrr-gene-empty',
      reset: 'ssrr-gene-reset',
      noun: 'pairs',
      urlKeys: { query: 'gene', filter: 'chr' },
      onChange: sectionsMoved
    });

    initFigure();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
