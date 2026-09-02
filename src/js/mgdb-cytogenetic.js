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

  function syncToggle(button) {
    var indicator = document.getElementById(button.getAttribute('data-cyt-indicator'));
    var expanded = indicator && indicator.textContent.trim() === '-';
    button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  }

  function runToggle(button) {
    var indicatorId = button.getAttribute('data-cyt-indicator');
    var targetId = button.getAttribute('data-cyt-target');
    var term = button.getAttribute('data-cyt-term');
    var mode = button.getAttribute('data-cyt-mode');
    var target = document.getElementById(targetId);

    if (!target) return;

    if (mode === 'stock' && typeof window.toggle_display_adv === 'function') {
      window.toggle_display_adv(indicatorId, targetId, 'stock', term);
    } else if (mode === 'locus' && typeof window.toggle_display === 'function') {
      window.toggle_display(indicatorId, targetId, 'locus', term);
    }

    syncToggle(button);
  }

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
  /* ── Stocks by variant type figure ───────────────────────────────────────────── */

  /* The census is rendered server side into #cyt-chart-data by the same GROUP
     BY that fills the metric cards, so this draws without a request of its
     own. */
  function initFigure() {
    var data = readJson('cyt-chart-data');
    if (!data || !data.bars || !data.bars.length) { return; }

    var bars = data.bars;

    var table = byId('cyt-stock-table');
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
    var el = byId('cyt-stock-chart');
    if (!el) { return; }
    var height = Math.max(320, ordered.length * 40 + 110);
    el.style.height = height + 'px';

    /* Margins are sized from the figure, not fixed. Variant type names run long --
       "Reciprocal Translocation (marked)" is 33 characters -- so the desktop gutter is
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
        hovertemplate: '%{customdata}<br>%{x:,} stocks<extra></extra>'
      }],
      layout: {
        height: height,
        showlegend: false,
        margin: m.margin,
        xaxis: { title: 'Stocks', zeroline: false, tickformat: ',d', nticks: m.nticks },
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
    var buttons = document.querySelectorAll('[data-cyt-mode]');
    if (!buttons.length) return;

    buttons.forEach(function (button) {
      syncToggle(button);
      button.addEventListener('click', function () {
        runToggle(button);
      });
    });

    var hash = window.location.hash.slice(1);
    if (!hash) return;

    var targetCard = document.getElementById(hash);
    var hashButton = targetCard && targetCard.querySelector('[data-cyt-mode]');
    if (hashButton && hashButton.getAttribute('aria-expanded') !== 'true') {
      hashButton.click();
    }
  });
})();
