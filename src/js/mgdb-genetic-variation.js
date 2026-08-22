/* ==========================================================================
   /genetic_variation — the Genetic Variation data center
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

  function buildTabs() {
    var nav = document.querySelector('.mgdb-section-tabs');
    if (!nav) return;
    var links = nav.querySelectorAll('a[href^="#"]');
    if (!links.length) return;

    var sections = [];
    Array.prototype.forEach.call(links, function (link) {
      var id = link.getAttribute('href').slice(1);
      var el = document.getElementById(id);
      if (el) sections.push({ id: id, link: link, el: el });
    });

    if (!('IntersectionObserver' in window)) return;

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          sections.forEach(function (s) {
            s.link.classList.toggle('is-current', s.el === entry.target);
          });
        }
      });
    }, { rootMargin: '-20% 0px -70% 0px' });

    sections.forEach(function (s) { observer.observe(s.el); });
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
