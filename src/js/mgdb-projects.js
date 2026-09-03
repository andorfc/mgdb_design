/* ==========================================================================
   Analysis projects listing (/projects)
   --------------------------------------------------------------------------
   The cards are rendered server-side from the registry in
   include/projects_lib.php, so the list is complete and indexable before this
   runs. This only hides and shows cards that are already on the page.

   The sticky section-tab bar is markup the shared shell styles; the shell
   moves no active state, so a page that ships the bar without asking for the
   behaviour gets one that scrolls correctly and then names the wrong section
   for the rest of the visit. MGDB.sectionTabs() is that behaviour, and it is
   opt-in rather than automatic so pages carrying their own older copy do not
   end up running two spies over one bar.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  function init() {
    /* Before the early return below: the tab bar is page furniture and does
       not depend on the results grid being present. Filtering hides cards, so
       the grid changes height and every section under it moves -- `watch` is
       what makes the spy re-measure when that happens. */
    MGDB.sectionTabs({ watch: '#projects-results' });

    var results = document.getElementById('projects-results');
    if (!results) { return; }

    var countEl = document.getElementById('projects-count');

    MGDB.filterList({
      items: results.querySelectorAll('.projects-card'),
      input: document.getElementById('projects-query'),
      chips: document.querySelectorAll('.mgdb-filters .mgdb-chip'),
      count: document.getElementById('projects-count'),
      empty: document.getElementById('projects-empty'),
      reset: document.getElementById('projects-reset'),
      noun:  'projects',
      urlKeys: { query: 'q', filter: 'topic' },
      /* A project carries several topics, so data-filter holds them all
         space-separated and a chip matches if it is one of them. */
      filterOn: function (el, value) {
        if (value === 'all') { return true; }
        var topics = (el.getAttribute('data-filter') || '').split(/\s+/);
        return topics.indexOf(value) !== -1;
      },
      /* The shared count is written as "<n> projects shown", which reads badly
         while the section is small enough to hold a single project. Rewriting
         it here keeps that fix off the six pages already using filterList. */
      onChange: function (visible, total) {
        if (!countEl) { return; }
        var noun = function (n) { return n === 1 ? 'project' : 'projects'; };
        countEl.textContent = (visible === total)
          ? total + ' ' + noun(total) + ' shown'
          : visible + ' of ' + total + ' ' + noun(total) + ' shown';
      }
    });

    var emptyReset = document.getElementById('projects-empty-reset');
    var reset = document.getElementById('projects-reset');
    if (emptyReset && reset) {
      emptyReset.addEventListener('click', function () { reset.click(); });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
