/* /new_genes — genes with new annotations.
 *
 * Two behaviours, both shared: filter the table in place, and drive the section
 * tab bar. The window chips are plain links that re-render server side, so
 * switching window is a page load and is bookmarkable — the legacy page's four
 * ?window= URLs keep working.
 *
 * Bauplan emits page scripts into <head>, so nothing here touches the DOM
 * before DOMContentLoaded.
 */
(function (window, document) {
  'use strict';

  function byId(id) { return document.getElementById(id); }

  function initFilter() {
    var input = byId('ng-filter');
    var body  = byId('ng-rows');
    var scope = byId('ng-scope');
    if (!input || !body) { return; }

    var rows = Array.prototype.slice.call(body.querySelectorAll('tr'));
    var total = rows.length;
    var baseText = scope ? scope.textContent : '';

    function apply() {
      var term = input.value.trim().toLowerCase();
      var shown = 0;
      rows.forEach(function (tr) {
        if (tr.querySelector('.ng-empty')) { return; }
        var hit = term === '' ||
          (tr.textContent || '').toLowerCase().indexOf(term) !== -1;
        tr.hidden = !hit;
        if (hit) { shown++; }
      });
      if (scope) {
        /* Report the filter against the rows it narrows, never against the
           server-side total -- "1-0 of 953" when the filter matches nothing on
           the page is the wrong statement, because the search did match. */
        scope.textContent = term === ''
          ? baseText
          : shown + ' of ' + total + ' shown on this page match "' + input.value.trim() + '"';
      }
    }

    var t = null;
    input.addEventListener('input', function () {
      window.clearTimeout(t);
      t = window.setTimeout(apply, 200);
    });
  }

  function init() {
    initFilter();

    /* Sorting is NOT wired here: mgdb-modern.js already calls sortTable on every
       table[data-sortable] at load, and binding it twice attaches two click
       handlers to each header, so one click sorts and immediately re-sorts. */

    /* The sticky section tabs: styled by the hub shell, driven per page. */
    if (window.MGDB && MGDB.sectionTabs) { MGDB.sectionTabs(); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
