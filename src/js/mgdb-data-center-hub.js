/* Data Hub directory — /data_center/
   The directory's search and category filter, the two figures, and the sticky
   section tabs. */

(function () {
  'use strict';

  var activeCategory = 'all';

  function byId(id) { return document.getElementById(id); }
  function normalize(value) { return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim(); }

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

  /* ── Directory search and filter ────────────────────────────────────────── */

  /* Unlike a record hub, the results here are the hub cards themselves, so
     they are all visible until a keyword or a category narrows them. */
  function updateDirectory() {
    var queryInput = byId('data-hub-query');
    var query = queryInput ? normalize(queryInput.value) : '';
    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-hub-center]'));
    var visible = 0;

    cards.forEach(function (card) {
      var categoryMatch = activeCategory === 'all' || card.getAttribute('data-category') === activeCategory;
      var textMatch = !query || query.split(' ').every(function (term) {
        return normalize(card.getAttribute('data-search') + ' ' + card.textContent).indexOf(term) !== -1;
      });
      card.hidden = !(categoryMatch && textMatch);
      if (!card.hidden) { visible += 1; }
    });

    var countEl = byId('data-hub-result-count');
    if (countEl) {
      countEl.textContent = visible === 1 ? '1 data hub shown'
                                          : number(visible) + ' data hubs shown';
    }

    var emptyEl = byId('data-hub-empty');
    if (emptyEl) { emptyEl.hidden = visible !== 0; }

    var clearBtn = byId('data-hub-query-clear');
    if (clearBtn) { clearBtn.hidden = !query; }
  }

  function setCategory(category) {
    activeCategory = category || 'all';
    Array.prototype.forEach.call(document.querySelectorAll('.data-hub-filter-btn'), function (button) {
      var active = button.getAttribute('data-hub-filter') === activeCategory;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    updateDirectory();
  }

  function resetDirectory() {
    var input = byId('data-hub-query');
    if (input) {
      input.value = '';
      input.focus();
    }
    setCategory('all');
  }

  /* ── Figures ────────────────────────────────────────────────────────────── */

  /* Both figures are rendered server side into #data-hub-chart-data, so they
     draw without a request of their own, and the donut is right before any
     script runs -- it used to be tallied from the rendered cards, which meant
     it was also wrong for the moment before this file executed. */
  function fillTable(id, rows, render) {
    var table = byId(id);
    if (!table) { return; }
    var body = table.querySelector('tbody');
    if (body) { body.innerHTML = rows.map(render).join(''); }
  }

  function scaleFigure(rows) {
    fillTable('data-hub-scale-table', rows, function (row) {
      return '<tr><td>' + esc(row.label) + '</td>'
           + '<td class="mgdb-numeric">' + number(row.count) + '</td></tr>';
    });

    if (!window.MGDB || !window.MGDB.chart) { return; }
    var el = byId('data-hub-scale-chart');
    if (!el || !rows.length) { return; }

    /* Plotly stacks horizontal bars bottom-up, so the array is reversed to put
       the largest collection at the top, matching the table. */
    var ordered = rows.slice().reverse();
    var height = Math.max(300, ordered.length * 34 + 110);
    el.style.height = height + 'px';

    /* Margins are sized from the figure, not fixed. MGDB.chart re-runs
       Plotly.Plots.resize on a window resize, which rescales the figure but
       keeps the margins it was drawn with, so a desktop gutter would survive
       onto a phone and squeeze the plot to nothing. */
    var NARROW = 560;
    var fullLabels = ordered.map(function (r) { return r.label; });
    /* Eleven collection names with no short form of their own, so the narrow
       set is a hard truncation. It has to be short: the gutter comes out of a
       259px figure, and every character of label is a pixel off the plot. */
    var shortLabels = fullLabels.map(function (l) {
      return l.length > 10 ? l.slice(0, 9).replace(/[\s-]+$/, '') + '…' : l;
    });
    function metrics() {
      var width = el.getBoundingClientRect().width;
      var narrow = width > 0 && width < NARROW;
      return {
        narrow: narrow,
        margin: narrow ? { l: 72, r: 16, t: 8, b: 52 } : { l: 176, r: 96, t: 8, b: 56 },
        /* The full title is 27 characters and is centred on the plot, which is
           barely 100px wide on a phone -- it overflowed the figure box. */
        title: narrow ? 'Records (log)' : 'Records (logarithmic scale)',
        labels: narrow ? shortLabels : fullLabels
      };
    }
    var m = metrics();

    window.MGDB.chart({
      target: el,
      traces: [{
        type: 'bar',
        orientation: 'h',
        /* The bars are always keyed on the full labels. Plotly pins a category
           axis's values on first draw, so restyling `y` with a shortened set
           adds new categories rather than renaming the existing ones and the
           figure keeps whichever labels it was born with. Swapping the axis's
           ticktext instead renames what is drawn without touching the data. */
        y: fullLabels,
        x: ordered.map(function (r) { return r.count; }),
        customdata: fullLabels,
        marker: { color: '#285d46' },
        /* A non-breaking space: SVG collapses a plain leading one, leaving the
           label flush against the end of its bar. */
        text: ordered.map(function (r) { return '\u00A0' + number(r.count); }),
        textposition: m.narrow ? 'none' : 'outside',
        cliponaxis: false,
        hovertemplate: '%{customdata}<br>%{x:,} records<extra></extra>'
      }],
      layout: {
        height: height,
        showlegend: false,
        margin: m.margin,
        /* A log axis, because the smallest collection is 160 and the largest
           is 1.9 million. dtick 1 puts a gridline at each power of ten. */
        xaxis: { type: 'log', dtick: 1, title: m.title, zeroline: false },
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
            'yaxis.ticktext': next.labels,
            'xaxis.title.text': next.title
          });
          window.Plotly.restyle(el, { textposition: next.narrow ? 'none' : 'outside' });
        }, 180);
      });
    }
  }

  function domainFigure(rows) {
    fillTable('data-hub-domain-table', rows, function (row) {
      return '<tr><td>' + esc(row.label) + '</td>'
           + '<td class="mgdb-numeric">' + number(row.count) + '</td></tr>';
    });

    if (!window.MGDB || !window.MGDB.chart) { return; }
    var el = byId('data-hub-domain-chart');
    if (!el || !rows.length) { return; }
    el.style.height = '330px';

    /* No legend.y here: the shared layout places the legend above the plot and
       reserves a band for it, and a fixed y is a fraction of the plot height,
       so it drifts as the figure grows. */
    window.MGDB.chart({
      target: el,
      traces: [{
        type: 'pie',
        hole: 0.55,
        labels: rows.map(function (r) { return r.label; }),
        values: rows.map(function (r) { return r.count; }),
        marker: { colors: ['#285d46', '#1a5b7a', '#a96919', '#501719'] },
        textinfo: 'value',
        textfont: { size: 13, color: '#ffffff' },
        sort: false,
        direction: 'clockwise',
        hovertemplate: '%{label}<br>%{value} data hubs (%{percent})<extra></extra>'
      }],
      layout: {
        height: 330,
        showlegend: true,
        margin: { l: 12, r: 12, t: 8, b: 8 }
      }
    });
  }

  function initFigures() {
    var data = readJson('data-hub-chart-data');
    if (!data) { return; }
    if (data.scale) { scaleFigure(data.scale); }
    if (data.domains) { domainFigure(data.domains); }
  }

  /* ── Bootstrap ──────────────────────────────────────────────────────────── */

  function initialize() {
    buildTabs();
    initFigures();

    var queryInput = byId('data-hub-query');
    if (queryInput) { queryInput.addEventListener('input', updateDirectory); }

    var clearBtn = byId('data-hub-query-clear');
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        if (queryInput) {
          queryInput.value = '';
          queryInput.focus();
        }
        updateDirectory();
      });
    }

    var resetBtn = byId('data-hub-reset');
    if (resetBtn) { resetBtn.addEventListener('click', resetDirectory); }

    Array.prototype.forEach.call(document.querySelectorAll('.data-hub-filter-btn'), function (button) {
      button.addEventListener('click', function () {
        setCategory(button.getAttribute('data-hub-filter'));
      });
    });

    updateDirectory();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }
})();
