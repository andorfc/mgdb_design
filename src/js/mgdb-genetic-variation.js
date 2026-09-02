/* ==========================================================================
   /genetic_variation — the Genetic Variation data hub
   --------------------------------------------------------------------------
   Progressive enhancement over a page that is already complete. Both tables
   are rendered server-side, so with this file missing the page still shows
   every dataset and every project, every link still works, and the column
   sorting that mgdb-modern.js wires from `data-sortable` still works. All this
   adds is the search box and the filter chips above each table.

   Bauplan::includeScript() emits into <head>, so this runs while the document
   is still being parsed and nothing in the body exists yet. Everything is
   therefore deferred to DOMContentLoaded.
   ========================================================================== */

(function (window, document) {
  'use strict';

  /* Rows carry a space-separated tag list in data-filter, because a dataset can
     be more than one thing at once — a MaizeGDB build that also has indels.
     The default matcher in MGDB.filterList compares the attribute whole, so
     multi-tag rows need a token match instead. */
  function tokenFilter(el, value) {
    if (value === 'all') { return true; }
    var tags = ' ' + (el.getAttribute('data-filter') || '') + ' ';
    return tags.indexOf(' ' + value + ' ') !== -1;
  }

  function wireTable(options) {
    var body = document.getElementById(options.rows);
    if (!body || !window.MGDB || !window.MGDB.filterList) { return; }

    var rows = body.querySelectorAll('tr');
    if (!rows.length) { return; }

    var section = document.getElementById(options.section);

    window.MGDB.filterList({
      items: rows,
      input: document.getElementById(options.input),
      chips: section ? section.querySelectorAll('.mgdb-filters .mgdb-chip') : [],
      count: document.getElementById(options.count),
      empty: document.getElementById(options.empty),
      reset: document.getElementById(options.reset),
      filterOn: tokenFilter,
      noun: options.noun,
      urlKeys: options.urlKeys
    });
  }

  /* ── Section Tabs & Scrollspy ───────────────────────────────────────────── */

  /* The shared section-tab behaviour: a wrapping bar, aria-current, and a click
     hold released by real scrolling. The IntersectionObserver-only version this
     replaced marked nothing in embedded browsers, which deliver no entries. */
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

  function init() {
    buildTabs();

    wireTable({
      section: 'gv-datasets',
      rows: 'gv-dataset-rows',
      input: 'gv-dataset-query',
      count: 'gv-dataset-count',
      empty: 'gv-dataset-empty',
      reset: 'gv-dataset-reset',
      noun: 'datasets',
      /* Distinct keys per table so the two filters can be linked together and
         neither clobbers the other in the query string. */
      urlKeys: { query: 'dq', filter: 'df' }
    });

    wireTable({
      section: 'gv-projects',
      rows: 'gv-project-rows',
      input: 'gv-project-query',
      count: 'gv-project-count',
      empty: 'gv-project-empty',
      reset: 'gv-project-reset',
      noun: 'projects',
      urlKeys: { query: 'pq', filter: 'pf' }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}(window, document));
